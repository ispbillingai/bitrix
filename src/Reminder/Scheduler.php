<?php
declare(strict_types=1);

namespace Glue\Reminder;

use Glue\Bitrix\Client;
use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use PDO;
use Throwable;

/**
 * Owns the reminders queue: enqueue future work, cancel it when a deal moves
 * (manual silence), and dispatch whatever is due. Called by bin/scheduler.php.
 */
final class Scheduler
{
    private PDO $db;
    private Notifier $notifier;

    public function __construct()
    {
        $this->db = Db::pdo();
        $this->notifier = new Notifier();
    }

    /**
     * Insert a reminder. dedupe_key makes repeated enqueues (e.g. webhook retries)
     * a no-op via the unique index. due_at is a 'Y-m-d H:i:s' string.
     */
    public function enqueue(array $r): void
    {
        $sql = 'INSERT INTO reminders
                (entity_type, bitrix_id, rule_key, recipient_type, channel, due_at,
                 skip_if_stage_changed_from, payload, dedupe_key)
                VALUES (:entity_type, :bitrix_id, :rule_key, :recipient_type, :channel, :due_at,
                        :skip_stage, :payload, :dedupe)
                ON DUPLICATE KEY UPDATE id = id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':entity_type'  => $r['entity_type'],
            ':bitrix_id'    => $r['bitrix_id'] ?? null,
            ':rule_key'     => $r['rule_key'],
            ':recipient_type' => $r['recipient_type'],
            ':channel'      => $r['channel'] ?? 'both',
            ':due_at'       => $r['due_at'],
            ':skip_stage'   => $r['skip_if_stage_changed_from'] ?? null,
            ':payload'      => isset($r['payload']) ? json_encode($r['payload'], JSON_UNESCAPED_UNICODE) : null,
            ':dedupe'       => $r['dedupe_key'] ?? null,
        ]);
    }

    /**
     * Cancel pending reminders for an entity — used to silence automations when
     * an agent manually moves the deal. Optionally limit to specific rule keys.
     */
    public function cancelForEntity(string $entityType, int $bitrixId, array $ruleKeys = []): int
    {
        $sql = "UPDATE reminders SET status='cancelled'
                WHERE status='pending' AND entity_type=? AND bitrix_id=?";
        $args = [$entityType, $bitrixId];
        if ($ruleKeys) {
            $sql .= ' AND rule_key IN (' . implode(',', array_fill(0, count($ruleKeys), '?')) . ')';
            $args = array_merge($args, $ruleKeys);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->rowCount();
    }

    /**
     * Dispatch all pending reminders whose due_at has passed. Returns a small
     * summary. Safe to call every minute from cron.
     */
    public function runDue(int $limit = 200): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM reminders
             WHERE status='pending' AND due_at <= NOW()
             ORDER BY due_at ASC
             LIMIT $limit"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $sent = $skipped = $failed = 0;
        foreach ($rows as $r) {
            try {
                $outcome = $this->dispatchOne($r);
                $outcome === 'sent' ? $sent++ : $skipped++;
            } catch (Throwable $e) {
                $failed++;
                $this->markFailed((int)$r['id'], $e->getMessage());
                Log::write('scheduler', 'reminder_failed', $r['entity_type'], (int)$r['bitrix_id'],
                    ['reminder_id' => (int)$r['id'], 'error' => $e->getMessage()]);
            }
        }
        return ['due' => count($rows), 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** Returns 'sent' or 'skipped'. */
    private function dispatchOne(array $r): string
    {
        $reminderId = (int)$r['id'];
        $bitrixId   = (int)$r['bitrix_id'];
        $ruleKey    = $r['rule_key'];
        $payload    = $r['payload'] ? json_decode($r['payload'], true) : [];

        // Manual-silence guard: if the entity has moved past the stage we were
        // waiting on, skip (the agent already acted / changed the status).
        if (!empty($r['skip_if_stage_changed_from']) && $bitrixId > 0) {
            if ($this->stageMovedFrom($r['entity_type'], $bitrixId, $r['skip_if_stage_changed_from'])) {
                $this->mark($reminderId, 'skipped');
                Log::write('scheduler', 'reminder_skipped_stage_moved', $r['entity_type'], $bitrixId,
                    ['reminder_id' => $reminderId, 'rule' => $ruleKey]);
                return 'skipped';
            }
        }

        $vars = $this->buildVars($r, $payload);
        $channel = $r['channel'];
        $okAny = false;

        if ($channel === 'whatsapp' || $channel === 'both') {
            $phone = $this->recipientPhone($r, $vars);
            if ($phone !== '') {
                $text = Templates::whatsapp($ruleKey, $vars);
                $okAny = $this->notifier->whatsapp($phone, $text, $reminderId) || $okAny;
            }
        }
        if ($channel === 'email' || $channel === 'both') {
            $to = $this->recipientEmail($r, $vars);
            if ($to !== '') {
                $mail = Templates::email($ruleKey, $vars);
                $okAny = $this->notifier->email($to, $mail['subject'], $mail['html'], $reminderId) || $okAny;
            }
        }

        $this->mark($reminderId, $okAny ? 'sent' : 'failed');
        Log::write('scheduler', 'reminder_sent', $r['entity_type'], $bitrixId,
            ['reminder_id' => $reminderId, 'rule' => $ruleKey, 'ok' => $okAny]);
        return $okAny ? 'sent' : 'skipped';
    }

    /** Build template vars from the tracked entity + payload + company name. */
    private function buildVars(array $r, array $payload): array
    {
        $track = $this->track($r['entity_type'], (int)$r['bitrix_id']);
        $vars = [
            'company'        => Config::get('mail.from_name', 'our company'),
            'name'           => $track['customer_name'] ?? ($payload['name'] ?? 'there'),
            'customer_name'  => $track['customer_name'] ?? ($payload['name'] ?? 'the customer'),
            'customer_phone' => $track['customer_phone'] ?? ($payload['customer_phone'] ?? ''),
            'customer_email' => $track['customer_email'] ?? ($payload['customer_email'] ?? ''),
            'bitrix_id'      => (string)$r['bitrix_id'],
        ];
        return array_merge($vars, $payload); // payload (agent_name, when, deadline...) wins
    }

    private function recipientPhone(array $r, array $vars): string
    {
        return match ($r['recipient_type']) {
            'agent'     => (string)($vars['agent_phone'] ?? ''),
            'logistics' => (string)Config::get('logistics.phone', ''),
            default     => (string)($vars['customer_phone'] ?? ''),
        };
    }

    private function recipientEmail(array $r, array $vars): string
    {
        return match ($r['recipient_type']) {
            'agent'     => (string)($vars['agent_email'] ?? ''),
            'logistics' => (string)Config::get('logistics.email', ''),
            default     => (string)($vars['customer_email'] ?? ''),
        };
    }

    private function stageMovedFrom(string $entityType, int $bitrixId, string $fromStage): bool
    {
        try {
            $client = new Client();
            $entity = $entityType === 'lead' ? $client->getLead($bitrixId) : $client->getDeal($bitrixId);
            if (!$entity) {
                return false;
            }
            $current = $entity['STATUS_ID'] ?? $entity['STAGE_ID'] ?? null;
            return $current !== null && $current !== $fromStage;
        } catch (Throwable) {
            return false; // on Bitrix error, don't silence — better to remind than drop
        }
    }

    private function track(string $entityType, int $bitrixId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM tracked_entities WHERE entity_type=? AND bitrix_id=?'
        );
        $stmt->execute([$entityType, $bitrixId]);
        return $stmt->fetch() ?: [];
    }

    private function mark(int $id, string $status): void
    {
        $this->db->prepare(
            "UPDATE reminders SET status=?, attempts=attempts+1, sent_at=NOW() WHERE id=?"
        )->execute([$status, $id]);
    }

    private function markFailed(int $id, string $error): void
    {
        $this->db->prepare(
            "UPDATE reminders SET status='failed', attempts=attempts+1, last_error=? WHERE id=?"
        )->execute([mb_substr($error, 0, 1000), $id]);
    }
}
