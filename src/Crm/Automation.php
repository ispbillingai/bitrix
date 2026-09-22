<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
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

    /**
     * #2 Welcome — immediate message to the customer on a new lead. enqueue() sends
     * it inline because due_at is now, so it goes out the moment the lead is created
     * (no cron wait). Future-dated rules below just queue and fire later.
     */
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

    /**
     * #4 Activity reminders for an uncontacted record. Two recurring cadences,
     * both of which STOP automatically the moment the record leaves $fromStage
     * (the skip_if_stage_changed_from guard ends the repeat chain):
     *
     *   - AGENT nudge: first after reminders.lead_inactivity_hours, then repeating
     *     every reminders.lead_nudge_repeat_hours (default 12h = "twice a day")
     *     until the lead is worked.
     *   - CUSTOMER invite: after reminders.lead_customer_after_hours (default 24h)
     *     of no contact, invite the lead to reach the agent/office, repeating on
     *     the same cadence. Only enqueued when the customer is reachable.
     *
     * Deals keep the single agent "to work" timer (no customer-facing nudge).
     */
    public static function inactivity(string $entityType, int $id, string $fromStage): void
    {
        $sched = self::sched();

        if ($entityType === 'deal') {
            $hours = (int)Config::get('reminders.deal_inactivity_hours', 3);
            $sched->enqueue([
                'entity_type'    => 'deal',
                'entity_id'      => $id,
                'rule_key'       => 'lead_inactivity',
                'recipient_type' => 'agent',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s', time() + $hours * 3600),
                'skip_if_stage_changed_from' => $fromStage,
                'dedupe_key'     => "inactivity:deal:$id",
            ]);
            return;
        }

        // Lead — recurring agent nudge.
        $firstH  = (int)Config::get('reminders.lead_inactivity_hours', 3);
        $repeatH = max(1, (int)Config::get('reminders.lead_nudge_repeat_hours', 12));
        $sched->enqueue([
            'entity_type'    => 'lead',
            'entity_id'      => $id,
            'rule_key'       => 'lead_inactivity',
            'recipient_type' => 'agent',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s', time() + $firstH * 3600),
            'skip_if_stage_changed_from' => $fromStage,
            'repeat_every_hours'         => $repeatH,
            'dedupe_key'     => "inactivity:lead:$id",
        ]);

        // Lead — recurring customer invite after a day of no contact.
        $custH = max(1, (int)Config::get('reminders.lead_customer_after_hours', 24));
        $sched->enqueue([
            'entity_type'    => 'lead',
            'entity_id'      => $id,
            'rule_key'       => 'lead_uncontacted_customer',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s', time() + $custH * 3600),
            'skip_if_stage_changed_from' => $fromStage,
            'repeat_every_hours'         => $repeatH,
            'dedupe_key'     => "uncontacted:lead:$id",
        ]);
    }

    /**
     * #3 Agent assignment. Two instant messages (both channels):
     *   - to the CUSTOMER: who their consultant is + how to reach them;
     *   - to the AGENT: a "new customer assigned to you" heads-up.
     * Both go out immediately (due now → enqueue sends inline).
     */
    public static function agentAssigned(string $entityType, int $id, array $agent): void
    {
        $agentName  = trim((string)($agent['full_name'] ?? $agent['username'] ?? '')) ?: 'your agent';
        $agentPhone = (string)($agent['phone'] ?? '');
        $agentEmail = (string)($agent['email'] ?? '');
        $agentId    = (int)($agent['id'] ?? 0);

        // Customer: meet your consultant.
        self::sched()->enqueue([
            'entity_type'    => $entityType,
            'entity_id'      => $id,
            'rule_key'       => 'agent_assigned',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'payload'        => [
                'agent_name'  => $agentName,
                'agent_phone' => $agentPhone,
                'agent_email' => $agentEmail,
            ],
            // re-fires if a different agent is later assigned
            'dedupe_key'     => "agent:$entityType:$id:$agentId",
        ]);

        // Agent: you've been assigned a new customer.
        self::sched()->enqueue([
            'entity_type'    => $entityType,
            'entity_id'      => $id,
            'rule_key'       => 'agent_new_assignment',
            'recipient_type' => 'agent',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'payload'        => [
                'agent_name'  => $agentName,
                'agent_phone' => $agentPhone,
                'agent_email' => $agentEmail,
            ],
            'dedupe_key'     => "agentnotify:$entityType:$id:$agentId",
        ]);
    }

    /**
     * Ask the customer to sign — sent the moment a deal enters the signature
     * stage. Instant (due now), both channels, carries the portal link. No dedupe
     * key: re-entering the stage re-sends, and moveStage's stage-change guard
     * already prevents a double-submit from sending twice.
     */
    public static function signRequest(int $dealId, string $link, ?string $lang = null): void
    {
        self::sched()->enqueue([
            'entity_type'    => 'deal',
            'entity_id'      => $dealId,
            'rule_key'       => 'sign_request',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'payload'        => ['link' => $link],
            'lang'           => $lang,
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
        // See interventionReminders: the assignee decides whether the staff copy
        // is queued at all, and goes into the dedupe key so reassigning a visit
        // does not collide with the cancelled rows it just replaced.
        $agentId = (int)(Db::pdo()->query(
            'SELECT COALESCE(agent_id, 0) FROM appointments WHERE id = ' . $apptId
        )->fetchColumn() ?: 0);
        $rev = ':a' . $agentId;

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
                'dedupe_key'     => "appt_cust:$apptId:$whenTs:$minBefore$rev",
            ]);
            $n++;

            if ($agentId > 0) {
                $sched->enqueue([
                    'entity_type'    => 'appointment',
                    'entity_id'      => $apptId,
                    'rule_key'       => 'appointment_agent',
                    'recipient_type' => 'agent',
                    'channel'        => 'both',
                    'due_at'         => $due,
                    'payload'        => ['when' => $whenLabel],
                    'dedupe_key'     => "appt_agent:$apptId:$whenTs:$minBefore$rev",
                ]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * The same, for a technical support intervention: the customer is reminded
     * that someone is coming, the technician that they have somewhere to be.
     *
     * Its own offsets, because a visit is not a sales meeting — the technician
     * plans a round the evening before and wants the morning's list early,
     * while a seller's two-hour nudge is plenty.
     */
    public static function interventionReminders(int $apptId, int $whenTs, string $whenLabel,
                                                 string $location = ''): int
    {
        $payload = ['when' => $whenLabel, 'location' => $location];
        $sched = self::sched();
        $n = 0;

        // Who the visit belongs to, read off the row rather than passed in —
        // and it does two jobs.
        //
        // It decides whether to queue the technician's copy at all: a visit
        // still in the pool has nobody to tell, and a reminder addressed to
        // agent_id NULL resolves no phone and no email and sits there failing.
        //
        // And it goes into the dedupe key, because dedupe_key is UNIQUE and a
        // cancelled row keeps its key: reassigning a visit cancels what was
        // queued and re-queues it, and without the assignee in the key the
        // re-queue collides with the cancelled row, is skipped, and the
        // day-before notice is lost for good.
        $agentId = (int)(Db::pdo()->query(
            'SELECT COALESCE(agent_id, 0) FROM appointments WHERE id = ' . $apptId
        )->fetchColumn() ?: 0);
        $rev = ':a' . $agentId;

        // The client's rule: a support visit is announced at a FIXED time the
        // evening before — 17:00 by default — not at so many hours before the
        // slot. A visit at 08:30 and one at 16:00 are then both read the same
        // evening, while an offset would have woken the customer at 08:30 for
        // the first and told the technician too late to plan the round.
        foreach (self::dayBeforeDue($whenTs) as $tag => $dueTs) {
            $due = date('Y-m-d H:i:s', $dueTs);
            $sched->enqueue([
                'entity_type'    => 'appointment',
                'entity_id'      => $apptId,
                'rule_key'       => 'intervention_customer',
                'recipient_type' => 'customer',
                'channel'        => 'both',
                'due_at'         => $due,
                'payload'        => $payload,
                'dedupe_key'     => "interv_cust:$apptId:$whenTs:$tag$rev",
            ]);
            $n++;

            // Nobody to tell while the visit is still in the pool.
            if ($agentId > 0) {
                $sched->enqueue([
                    'entity_type'    => 'appointment',
                    'entity_id'      => $apptId,
                    'rule_key'       => 'intervention_tech',
                    'recipient_type' => 'agent',
                    'channel'        => 'both',
                    'due_at'         => $due,
                    'payload'        => $payload,
                    'dedupe_key'     => "interv_tech:$apptId:$whenTs:$tag$rev",
                ]);
                $n++;
            }
        }

        // Extra nudges closer in, for anyone who wants them. Empty by default:
        // the evening-before notice is the rule, and a second message two hours
        // before a visit the customer already confirmed is noise.
        $offsets = (array)Config::get('reminders.intervention_offsets_min', []);
        foreach ($offsets as $minBefore) {
            $dueTs = $whenTs - (int)$minBefore * 60;
            if ($dueTs < time()) {
                continue; // booked closer than this offset — that reminder is moot
            }
            $due = date('Y-m-d H:i:s', $dueTs);

            $sched->enqueue([
                'entity_type'    => 'appointment',
                'entity_id'      => $apptId,
                'rule_key'       => 'intervention_customer',
                'recipient_type' => 'customer',
                'channel'        => 'both',
                'due_at'         => $due,
                'payload'        => $payload,
                'dedupe_key'     => "interv_cust:$apptId:$whenTs:$minBefore$rev",
            ]);
            $n++;

            if ($agentId > 0) {
                $sched->enqueue([
                    'entity_type'    => 'appointment',
                    'entity_id'      => $apptId,
                    'rule_key'       => 'intervention_tech',
                    'recipient_type' => 'agent',
                    'channel'        => 'both',
                    'due_at'         => $due,
                    'payload'        => $payload,
                    'dedupe_key'     => "interv_tech:$apptId:$whenTs:$minBefore$rev",
                ]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * When the evening-before notice for a visit is due.
     *
     * Returns ['daybefore' => timestamp] normally, or an empty array when that
     * moment has already passed — a visit booked this morning for tomorrow at
     * 18:00 is past 17:00 today only if it is already gone, and a visit booked
     * for today has no evening before at all. Nothing is back-dated: the
     * scheduler would fire it on its very next run, which is a reminder arriving
     * after the confirmation it was meant to follow.
     *
     * @return array<string,int>
     */
    private static function dayBeforeDue(int $whenTs): array
    {
        $at = trim((string)Config::get('reminders.intervention_day_before_at', '17:00'));
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $at, $m)) {
            $m = [null, '17', '00'];
        }
        // Anchored to the DAY of the visit minus one, then that clock time —
        // built from the date parts rather than "-86400 seconds", so the hour
        // stays 17:00 across a daylight-saving change.
        $dueTs = mktime((int)$m[1], (int)$m[2], 0,
            (int)date('n', $whenTs), (int)date('j', $whenTs) - 1, (int)date('Y', $whenTs));

        return ($dueTs !== false && $dueTs > time()) ? ['daybefore' => $dueTs] : [];
    }

    /**
     * #6 Signing cadence (Phase 4 "Quote sent"). The agent sets a signature due
     * date on the deal when sending the quote; the cadence anchors on it:
     *   R1  — unsigned N days AFTER the quote was sent (sign_after_sent_days)
     *   R2+ — a number of days BEFORE the due date (sign_before_due_days)
     * then recurring nudges after the due date until signed. When no due date is
     * given, falls back to a configurable window from today. Dedupe keys include
     * the resolved deadline, so changing the due date (re-anchor) enqueues afresh.
     */
    public static function signCadence(int $dealId, string $quoteStage, ?string $dueDate = null): void
    {
        $sched = self::sched();
        $now   = time();

        // Resolve the signature due date: agent's value, else a default window.
        $defaultDays = max(1, (int)Config::get('reminders.sign_due_default_days', 30));
        $deadlineTs  = $dueDate ? strtotime($dueDate . ' 09:00:00') : false;
        if (!$deadlineTs || $deadlineTs <= $now) {
            $deadlineTs = strtotime("+$defaultDays days", $now);
        }
        $deadline = date('Y-m-d', $deadlineTs);

        // R1 — still unsigned N days after the quote went out (CRM.txt: 15 days).
        $afterDays = max(1, (int)Config::get('reminders.sign_after_sent_days', 15));
        $sched->enqueue([
            'entity_type'    => 'deal',
            'entity_id'      => $dealId,
            'rule_key'       => 'sign_due',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s', $now + $afterDays * 86400),
            'skip_if_stage_changed_from' => $quoteStage,
            'payload'        => ['deadline' => $deadline],
            'dedupe_key'     => "sign:deal:$dealId:after:$deadline",
        ]);

        // R2/R3 — a number of days before the due date (CRM.txt: 10 and 5 days left).
        foreach ((array)Config::get('reminders.sign_before_due_days', [10, 5]) as $daysBefore) {
            $dueTs = $deadlineTs - (int)$daysBefore * 86400;
            if ($dueTs <= $now) {
                continue; // that window is already past for this due date
            }
            $sched->enqueue([
                'entity_type'    => 'deal',
                'entity_id'      => $dealId,
                'rule_key'       => 'sign_due',
                'recipient_type' => 'customer',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s', $dueTs),
                'skip_if_stage_changed_from' => $quoteStage,
                'payload'        => ['deadline' => $deadline],
                'dedupe_key'     => "sign:deal:$dealId:before$daysBefore:$deadline",
            ]);
        }

        // Overdue recurring nudges after the due date, up to the max window (15 days).
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
                'dedupe_key'     => "signover:deal:$dealId:$d:$deadline",
            ]);
        }
    }

    /** #7 Closing — thank-you to the customer + notify logistics, on a won deal (sent inline). */
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
