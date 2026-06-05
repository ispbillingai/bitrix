<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Reminder\Scheduler;

/**
 * The automation rules from "Management software.txt", driven entirely by LOCAL
 * CRM events (no Bitrix). Every method just enqueues reminders on the queue; the
 * cron Scheduler (bin/scheduler.php) is the only thing that actually sends, so a
 * slow WhatsApp call never blocks a web request. All enqueues are idempotent via
 * dedupe_key, so repeated triggers (double-submit, webhook retry) never double-send.
 *
 * This replaces the scheduling halves of the old Lead\Intake and Bitrix\EventHandler.
 */
final class Automation
{
    private static function sched(): Scheduler
    {
        return new Scheduler();
    }

    /** #2 Welcome — immediate message to the customer on a new lead. */
    public static function welcome(string $entityType, int $id, ?string $lang = null, string $rule = 'welcome'): void
    {
        self::sched()->enqueue([
            'entity_type'    => $entityType,
            'entity_id'      => $id,
            'rule_key'       => $rule,
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'lang'           => $lang,
            'dedupe_key'     => "$rule:$entityType:$id",
        ]);
    }

    /** #4 Activity reminder — nudge the agent if the lead hasn't left $fromStage in N hours. */
    public static function inactivity(string $entityType, int $id, string $fromStage): void
    {
        $cfgKey = $entityType === 'deal' ? 'reminders.deal_inactivity_hours' : 'reminders.lead_inactivity_hours';
        $hours = (int)Config::get($cfgKey, $entityType === 'deal' ? 3 : 2);
        self::sched()->enqueue([
            'entity_type'    => $entityType,
            'entity_id'      => $id,
            'rule_key'       => 'lead_inactivity',
            'recipient_type' => 'agent',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s', time() + $hours * 3600),
            'skip_if_stage_changed_from' => $fromStage,
            'dedupe_key'     => "inactivity:$entityType:$id",
        ]);
    }

    /** #3 Agent assignment — send the agent's profile to the customer. */
    public static function agentAssigned(string $entityType, int $id, array $agent): void
    {
        $agentName = trim((string)($agent['full_name'] ?? $agent['username'] ?? '')) ?: 'your agent';
        self::sched()->enqueue([
            'entity_type'    => $entityType,
            'entity_id'      => $id,
            'rule_key'       => 'agent_assigned',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'payload'        => [
                'agent_name'  => $agentName,
                'agent_phone' => (string)($agent['phone'] ?? ''),
                'agent_email' => (string)($agent['email'] ?? ''),
            ],
            // re-fires if a different agent is later assigned
            'dedupe_key'     => "agent:$entityType:$id:" . (int)($agent['id'] ?? 0),
        ]);
    }

    /**
     * #5 Appointment reminders — to BOTH the customer and the agent, at each
     * configured offset before the start time. $whenTs is a unix timestamp.
     */
    public static function appointmentReminders(int $apptId, int $whenTs, string $whenLabel): int
    {
        $offsets = (array)Config::get('reminders.appointment_offsets_min', [1440, 120]);
        $sched = self::sched();
        $n = 0;
        foreach ($offsets as $minBefore) {
            $dueTs = $whenTs - (int)$minBefore * 60;
            if ($dueTs < time()) {
                continue; // offset already in the past for this appointment
            }
            $due = date('Y-m-d H:i:s', $dueTs);

            $sched->enqueue([
                'entity_type'    => 'appointment',
                'entity_id'      => $apptId,
                'rule_key'       => 'appointment_customer',
                'recipient_type' => 'customer',
                'channel'        => 'both',
                'due_at'         => $due,
                'payload'        => ['when' => $whenLabel],
                'dedupe_key'     => "appt_cust:$apptId:$whenTs:$minBefore",
            ]);
            $n++;

            $sched->enqueue([
                'entity_type'    => 'appointment',
                'entity_id'      => $apptId,
                'rule_key'       => 'appointment_agent',
                'recipient_type' => 'agent',
                'channel'        => 'both',
                'due_at'         => $due,
                'payload'        => ['when' => $whenLabel],
                'dedupe_key'     => "appt_agent:$apptId:$whenTs:$minBefore",
            ]);
            $n++;
        }
        return $n;
    }

    /** #6 Signing cadence — reminders at 10/5/0 days before deadline, then overdue nudges. */
    public static function signCadence(int $dealId, string $quoteStage): void
    {
        $offsets    = (array)Config::get('reminders.sign_offsets_days', [10, 5, 0]);
        $maxOffset  = $offsets ? max($offsets) : 10;
        $deadlineTs = strtotime("+$maxOffset days");
        $deadline   = date('Y-m-d', $deadlineTs);
        $sched      = self::sched();

        foreach ($offsets as $daysBefore) {
            $dueTs = max(time(), $deadlineTs - (int)$daysBefore * 86400);
            $sched->enqueue([
                'entity_type'    => 'deal',
                'entity_id'      => $dealId,
                'rule_key'       => 'sign_due',
                'recipient_type' => 'customer',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s', $dueTs),
                'skip_if_stage_changed_from' => $quoteStage,
                'payload'        => ['deadline' => $deadline],
                'dedupe_key'     => "sign:deal:$dealId:$daysBefore",
            ]);
        }

        // Overdue recurring nudges up to the max window (req part2 #2: 15 days).
        $every = max(1, (int)Config::get('reminders.sign_overdue_every_days', 3));
        $maxD  = (int)Config::get('reminders.sign_overdue_max_days', 15);
        for ($d = $every; $d <= $maxD; $d += $every) {
            $sched->enqueue([
                'entity_type'    => 'deal',
                'entity_id'      => $dealId,
                'rule_key'       => 'sign_overdue',
                'recipient_type' => 'customer',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s', $deadlineTs + $d * 86400),
                'skip_if_stage_changed_from' => $quoteStage,
                'dedupe_key'     => "signover:deal:$dealId:$d",
            ]);
        }
    }

    /** #7 Closing — thank-you to the customer + notify logistics, on a won deal. */
    public static function closing(int $dealId): void
    {
        $sched = self::sched();
        $sched->enqueue([
            'entity_type'    => 'deal',
            'entity_id'      => $dealId,
            'rule_key'       => 'thank_you',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'dedupe_key'     => "thankyou:deal:$dealId",
        ]);

        $channel = Config::get('logistics.phone') ? 'both' : 'email';
        $sched->enqueue([
            'entity_type'    => 'deal',
            'entity_id'      => $dealId,
            'rule_key'       => 'logistics_notify',
            'recipient_type' => 'logistics',
            'channel'        => $channel,
            'due_at'         => date('Y-m-d H:i:s'),
            'dedupe_key'     => "logistics:deal:$dealId",
        ]);
    }
}
