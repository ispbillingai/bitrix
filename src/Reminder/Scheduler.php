<?php
declare(strict_types=1);

namespace Glue\Reminder;

use Glue\Config;
use Glue\Crm\EntityResolver;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use PDO;
use Throwable;

/**
 * Owns the reminders queue: enqueue future work, cancel it when a record moves
 * (manual silence), and dispatch whatever is due. Called by bin/scheduler.php.
 *
 * Recipient details and the "has it moved?" guard now come from the LOCAL CRM
 * tables via Crm\EntityResolver — the scheduler no longer reads Bitrix over REST.
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
     * Insert a reminder. dedupe_key makes repeated enqueues (double-submit, webhook
     * retry) a no-op via the unique index. due_at is a 'Y-m-d H:i:s' string.
     */
    public function enqueue(array $r): void
    {
        $sql = 'INSERT INTO reminders
                (entity_type, entity_id, rule_key, recipient_type, channel, due_at,
                 skip_if_stage_changed_from, payload, lang, dedupe_key)
                VALUES (:entity_type, :entity_id, :rule_key, :recipient_type, :channel, :due_at,
                        :skip_stage, :payload, :lang, :dedupe)
                ON DUPLICATE KEY UPDATE id = id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':entity_type'    => $r['entity_type'],
            ':entity_id'      => $r['entity_id'] ?? null,
            ':rule_key'       => $r['rule_key'],
            ':recipient_type' => $r['recipient_type'],
            ':channel'        => $r['channel'] ?? 'both',
            ':due_at'         => $r['due_at'],
            ':skip_stage'     => $r['skip_if_stage_changed_from'] ?? null,
            ':payload'        => isset($r['payload']) ? json_encode($r['payload'], JSON_UNESCAPED_UNICODE) : null,
            ':lang'           => $r['lang'] ?? null,
            ':dedupe'         => $r['dedupe_key'] ?? null,
        ]);
    }

    /**
     * Cancel pending reminders for a record — used to silence automations when a
     * seller manually moves the deal. Optionally limit to specific rule keys.
     */
    public function cancelForEntity(string $entityType, int $entityId, array $ruleKeys = []): int
    {
        $sql = "UPDATE reminders SET status='cancelled'
                WHERE status='pending' AND entity_type=? AND entity_id=?";
        $args = [$entityType, $entityId];
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
                Log::write('scheduler', 'reminder_failed', $r['entity_type'], (int)$r['entity_id'],
                    ['reminder_id' => (int)$r['id'], 'error' => $e->getMessage()]);
            }
        }
        return ['due' => count($rows), 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** Returns 'sent' or 'skipped'. */
    private function dispatchOne(array $r): string
    {
        $reminderId = (int)$r['id'];
        $entityId   = (int)$r['entity_id'];
        $ruleKey    = $r['rule_key'];
        $payload    = $r['payload'] ? json_decode($r['payload'], true) : [];

        // Manual-silence guard: if the record has moved past the stage we were
        // waiting on, skip (the seller already acted / changed the stage).
        if (!empty($r['skip_if_stage_changed_from']) && $entityId > 0) {
            if ($this->stageMovedFrom($r['entity_type'], $entityId, $r['skip_if_stage_changed_from'])) {
                $this->mark($reminderId, 'skipped');
                Log::write('scheduler', 'reminder_skipped_stage_moved', $r['entity_type'], $entityId,
                    ['reminder_id' => $reminderId, 'rule' => $ruleKey]);
                return 'skipped';
            }
        }

        $vars = $this->buildVars($r, is_array($payload) ? $payload : []);
        $lang = $this->resolveLang($r);
        $channel = $r['channel'];
        $okAny = false;

        if ($channel === 'whatsapp' || $channel === 'both') {
            $phone = $this->recipientPhone($r, $vars);
            if ($phone !== '') {
                $text = Templates::whatsapp($ruleKey, $vars, $lang);
                $okAny = $this->notifier->whatsapp($phone, $text, $reminderId) || $okAny;
            }
        }
        if ($channel === 'email' || $channel === 'both') {
            $to = $this->recipientEmail($r, $vars);
            if ($to !== '') {
                $mail = Templates::email($ruleKey, $vars, $lang);
                $okAny = $this->notifier->email($to, $mail['subject'], $mail['html'], $reminderId) || $okAny;
            }
        }

        $this->mark($reminderId, $okAny ? 'sent' : 'failed');
        Log::write('scheduler', 'reminder_sent', $r['entity_type'], $entityId,
            ['reminder_id' => $reminderId, 'rule' => $ruleKey, 'ok' => $okAny]);
        return $okAny ? 'sent' : 'skipped';
    }

    /** Build template vars from the local record (resolver) + payload + company. */
    private function buildVars(array $r, array $payload): array
    {
        $entityId = (int)$r['entity_id'];
        $res = EntityResolver::resolve($r['entity_type'], $entityId);
        $company = (string)Config::get('mail.from_name', '')
            ?: (string)Config::get('app.company_name', 'our company');
        $vars = [
            'company'        => $company,
            'name'           => $res['customer_name'] ?? ($payload['name'] ?? 'there'),
            'customer_name'  => $res['customer_name'] ?? ($payload['name'] ?? 'the customer'),
            'customer_phone' => $res['customer_phone'] ?? ($payload['customer_phone'] ?? ''),
            'customer_email' => $res['customer_email'] ?? ($payload['customer_email'] ?? ''),
            'agent_name'     => $res['agent_name'] ?? '',
            'agent_phone'    => $res['agent_phone'] ?? '',
            'agent_email'    => $res['agent_email'] ?? '',
            'id'             => (string)$entityId,
            'entity_id'      => (string)$entityId,
            'bitrix_id'      => (string)$entityId, // legacy placeholder still used in some copy
        ];
        return array_merge($vars, $payload); // payload (agent_name, when, deadline...) wins
    }

    /**
     * Language for this message. Staff (agent/logistics) always get the office
     * default language; customers get their record's stored language, with an
     * optional per-reminder override.
     */
    private function resolveLang(array $r): string
    {
        if ($r['recipient_type'] !== 'customer') {
            return Templates::lang(Config::get('app.default_lang', 'it'));
        }
        if (!empty($r['lang'])) {
            return Templates::lang($r['lang']);
        }
        $res = EntityResolver::resolve($r['entity_type'], (int)$r['entity_id']);
        return Templates::lang($res['lang'] ?? null);
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

    /** Has the local record left the stage this reminder was waiting on? */
    private function stageMovedFrom(string $entityType, int $entityId, string $fromStage): bool
    {
        $current = EntityResolver::stageCode($entityType, $entityId);
        return $current !== null && $current !== $fromStage;
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
