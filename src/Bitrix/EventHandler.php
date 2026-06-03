<?php
declare(strict_types=1);

namespace Glue\Bitrix;

use Glue\Config;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Glue\Tracking\Repo;

/**
 * Reacts to Bitrix24 OUTBOUND webhook events (ONCRMLEADUPDATE / ONCRMDEALUPDATE
 * / ONCRMDEALADD). Re-reads the entity over REST (the webhook only carries the
 * id) and runs the right automation. All scheduling is idempotent via dedupe.
 *
 * This is the "thin glue" half of requirements #3, #5, #6, #7.
 */
final class EventHandler
{
    private Client $client;
    private Repo $repo;
    private Scheduler $sched;

    public function __construct()
    {
        $this->client = new Client();
        $this->repo   = new Repo();
        $this->sched  = new Scheduler();
    }

    /** entityType 'lead'|'deal', $event like ONCRMDEALUPDATE. */
    public function handle(string $entityType, int $bitrixId, string $event): array
    {
        if ($entityType === 'lead') {
            return $this->handleLead($bitrixId, $event);
        }
        return $this->handleDeal($bitrixId, $event);
    }

    private function handleLead(int $id, string $event): array
    {
        $lead = $this->client->getLead($id);
        if (!$lead) {
            return ['ok' => false, 'reason' => 'lead_not_found'];
        }
        $contacts = Client::primaryContacts($lead);
        $stage    = (string)($lead['STATUS_ID'] ?? '');
        $prev     = $this->repo->find('lead', $id);

        $this->repo->upsert('lead', $id, [
            'stage_id'        => $stage,
            'assigned_by_id'  => (int)($lead['ASSIGNED_BY_ID'] ?? 0) ?: null,
            'customer_phone'  => $contacts['phone'],
            'customer_email'  => $contacts['email'],
            'customer_name'   => trim(($lead['NAME'] ?? '') . ' ' . ($lead['LAST_NAME'] ?? '')) ?: null,
            'received_at'     => $prev['received_at'] ?? date('Y-m-d H:i:s'),
            'stage_changed_at'=> date('Y-m-d H:i:s'),
            'last_synced_at'  => date('Y-m-d H:i:s'),
        ]);

        $actions = [];

        // #3 Agent assignment: assignee changed (or first seen) -> send profile.
        $prevAgent = (int)($prev['assigned_by_id'] ?? 0);
        $nowAgent  = (int)($lead['ASSIGNED_BY_ID'] ?? 0);
        if ($nowAgent > 0 && $nowAgent !== $prevAgent) {
            $this->scheduleAgentProfile('lead', $id, $nowAgent, $contacts);
            $actions[] = 'agent_assigned';
        }

        // #4 Lead moved out of the first status -> silence the inactivity timer.
        $newStatus = Config::get('bitrix.lead_status_new', 'NEW');
        if ($prev && ($prev['stage_id'] ?? null) === $newStatus && $stage !== $newStatus) {
            $this->sched->cancelForEntity('lead', $id, ['lead_inactivity']);
            $actions[] = 'inactivity_cancelled';
        }

        Log::write('bitrix_event', 'lead_updated', 'lead', $id, ['event' => $event, 'actions' => $actions]);
        return ['ok' => true, 'actions' => $actions];
    }

    private function handleDeal(int $id, string $event): array
    {
        $deal = $this->client->getDeal($id);
        if (!$deal) {
            return ['ok' => false, 'reason' => 'deal_not_found'];
        }
        $contacts = Client::primaryContacts($deal);
        $stage    = (string)($deal['STAGE_ID'] ?? '');
        $prev     = $this->repo->find('deal', $id);

        $this->repo->upsert('deal', $id, [
            'stage_id'        => $stage,
            'assigned_by_id'  => (int)($deal['ASSIGNED_BY_ID'] ?? 0) ?: null,
            'customer_phone'  => $contacts['phone'],
            'customer_email'  => $contacts['email'],
            'customer_name'   => $deal['TITLE'] ?? null,
            'received_at'     => $prev['received_at'] ?? date('Y-m-d H:i:s'),
            'stage_changed_at'=> date('Y-m-d H:i:s'),
            'last_synced_at'  => date('Y-m-d H:i:s'),
        ]);

        $actions = [];
        $prevStage  = $prev['stage_id'] ?? null;
        $quoteStage = Config::get('bitrix.deal_stage_quote', 'PREPARATION');
        $wonStage   = Config::get('bitrix.deal_stage_signed', 'WON');

        // #3 agent assignment on deals too.
        $prevAgent = (int)($prev['assigned_by_id'] ?? 0);
        $nowAgent  = (int)($deal['ASSIGNED_BY_ID'] ?? 0);
        if ($nowAgent > 0 && $nowAgent !== $prevAgent) {
            $this->scheduleAgentProfile('deal', $id, $nowAgent, $contacts);
            $actions[] = 'agent_assigned';
        }

        // #6 Quote sent -> schedule the signing reminder cadence.
        if ($stage === $quoteStage && $prevStage !== $quoteStage) {
            $this->scheduleSigningReminders($id, $contacts);
            $actions[] = 'signing_reminders_scheduled';
        }

        // #7 Signed -> thank-you + logistics, and stop chasing the signature.
        if ($stage === $wonStage && $prevStage !== $wonStage) {
            $this->sched->cancelForEntity('deal', $id, ['sign_due', 'sign_overdue']);
            $this->scheduleClosing($id, $contacts);
            $this->repo->close('deal', $id);
            $actions[] = 'closed_thank_you_logistics';
        }

        Log::write('bitrix_event', 'deal_updated', 'deal', $id, ['event' => $event, 'actions' => $actions]);
        return ['ok' => true, 'actions' => $actions];
    }

    private function scheduleAgentProfile(string $entityType, int $id, int $agentUserId, array $contacts): void
    {
        $agent = $this->client->getUser($agentUserId);
        $agentName  = trim(($agent['NAME'] ?? '') . ' ' . ($agent['LAST_NAME'] ?? '')) ?: 'your agent';
        $agentPhone = $agent['WORK_PHONE'] ?? $agent['PERSONAL_MOBILE'] ?? '';
        $agentEmail = $agent['EMAIL'] ?? '';

        $this->sched->enqueue([
            'entity_type'   => $entityType,
            'bitrix_id'     => $id,
            'rule_key'      => 'agent_assigned',
            'recipient_type'=> 'customer',
            'channel'       => 'both',
            'due_at'        => date('Y-m-d H:i:s'),
            'payload'       => [
                'agent_name'  => $agentName,
                'agent_phone' => $agentPhone,
                'agent_email' => $agentEmail,
            ],
            // re-fire if a different agent is assigned later
            'dedupe_key'    => "agent:$entityType:$id:$agentUserId",
        ]);
    }

    /** #6 Reminders at 10/5/0 days before deadline, then overdue nudges. */
    private function scheduleSigningReminders(int $dealId, array $contacts): void
    {
        $quoteStage = Config::get('bitrix.deal_stage_quote', 'PREPARATION');
        $offsets    = (array)Config::get('reminders.sign_offsets_days', [10, 5, 0]);
        // Deadline = today + max(offset) days, unless the deal carries CLOSEDATE.
        $maxOffset  = $offsets ? max($offsets) : 10;
        $deadlineTs = strtotime("+$maxOffset days");
        $deadline   = date('Y-m-d', $deadlineTs);

        foreach ($offsets as $daysBefore) {
            $dueTs = $deadlineTs - $daysBefore * 86400;
            if ($dueTs < time()) {
                $dueTs = time(); // a 0-day or past offset fires now
            }
            $this->sched->enqueue([
                'entity_type'   => 'deal',
                'bitrix_id'     => $dealId,
                'rule_key'      => 'sign_due',
                'recipient_type'=> 'customer',
                'channel'       => 'both',
                'due_at'        => date('Y-m-d H:i:s', $dueTs),
                'skip_if_stage_changed_from' => $quoteStage,
                'payload'       => ['deadline' => $deadline],
                'dedupe_key'    => "sign:deal:$dealId:$daysBefore",
            ]);
        }

        // Overdue recurring nudges up to the max window (req part2 #2: 15 days).
        $every = (int)Config::get('reminders.sign_overdue_every_days', 3);
        $maxD  = (int)Config::get('reminders.sign_overdue_max_days', 15);
        for ($d = $every; $d <= $maxD; $d += max(1, $every)) {
            $this->sched->enqueue([
                'entity_type'   => 'deal',
                'bitrix_id'     => $dealId,
                'rule_key'      => 'sign_overdue',
                'recipient_type'=> 'customer',
                'channel'       => 'both',
                'due_at'        => date('Y-m-d H:i:s', $deadlineTs + $d * 86400),
                'skip_if_stage_changed_from' => $quoteStage,
                'dedupe_key'    => "signover:deal:$dealId:$d",
            ]);
        }
    }

    /** #7 Thank-you to customer + logistics notification. */
    private function scheduleClosing(int $dealId, array $contacts): void
    {
        $this->sched->enqueue([
            'entity_type'   => 'deal',
            'bitrix_id'     => $dealId,
            'rule_key'      => 'thank_you',
            'recipient_type'=> 'customer',
            'channel'       => 'both',
            'due_at'        => date('Y-m-d H:i:s'),
            'dedupe_key'    => "thankyou:deal:$dealId",
        ]);

        // Logistics: email always; WhatsApp too if logistics.phone is set.
        $channel = Config::get('logistics.phone') ? 'both' : 'email';
        $this->sched->enqueue([
            'entity_type'   => 'deal',
            'bitrix_id'     => $dealId,
            'rule_key'      => 'logistics_notify',
            'recipient_type'=> 'logistics',
            'channel'       => $channel,
            'due_at'        => date('Y-m-d H:i:s'),
            'payload'       => [
                'customer_phone' => $contacts['phone'] ?? '',
                'customer_email' => $contacts['email'] ?? '',
            ],
            'dedupe_key'    => "logistics:deal:$dealId",
        ]);
    }
}
