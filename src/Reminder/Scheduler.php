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
     *
     * Instant delivery: if the reminder is due now (due_at <= now) it is dispatched
     * immediately, in this same request, so the customer/agent gets the message
     * without waiting for a cron tick. Future-dated reminders (inactivity, sign
     * cadence, appointment/offer reminders) are just queued and fire later via the
     * web dispatcher (tickWeb) or an external cron, if any. Pass $sendIfDue=false
     * to force pure-queue behaviour.
     *
     * Returns the reminder id (the new row, or the existing one on a dedupe hit),
     * or 0 if it could not be resolved.
     */
    public function enqueue(array $r, bool $sendIfDue = true): int
    {
        $sql = 'INSERT INTO reminders
                (entity_type, entity_id, rule_key, recipient_type, channel, due_at,
                 skip_if_stage_changed_from, repeat_every_hours, payload, lang, dedupe_key)
                VALUES (:entity_type, :entity_id, :rule_key, :recipient_type, :channel, :due_at,
                        :skip_stage, :repeat_hours, :payload, :lang, :dedupe)
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
            ':repeat_hours'   => isset($r['repeat_every_hours']) ? max(0, (int)$r['repeat_every_hours']) ?: null : null,
            ':payload'        => isset($r['payload']) ? json_encode($r['payload'], JSON_UNESCAPED_UNICODE) : null,
            ':lang'           => $r['lang'] ?? null,
            ':dedupe'         => $r['dedupe_key'] ?? null,
        ]);

        $id = (int)$this->db->lastInsertId();
        $freshInsert = $id > 0; // 0 => dedupe hit (ON DUPLICATE KEY UPDATE id=id)
        if (!$freshInsert && !empty($r['dedupe_key'])) {
            // Dedupe hit: nothing inserted. Look the existing row up by its
            // dedupe_key so the caller can still reference it.
            $q = $this->db->prepare('SELECT id FROM reminders WHERE dedupe_key = ? LIMIT 1');
            $q->execute([$r['dedupe_key']]);
            $id = (int)($q->fetchColumn() ?: 0);
        }

        // Instant send for already-due reminders. Only a fresh insert sends; a
        // dedupe hit means it was enqueued (and likely already sent) before, so
        // re-sending would double-message on a double-submit / webhook retry.
        if ($sendIfDue && $freshInsert && strtotime((string)$r['due_at']) <= time()) {
            $this->sendNow($id);
        }
        return $id;
    }

    /**
     * Send a single reminder immediately, by id — the "instant" path used when an
     * event fires (new request, agent assigned, deal won) so the customer doesn't
     * wait for a cron tick. Only dispatches if the reminder is still pending and
     * already due. Never throws: a channel error is recorded on the reminder and
     * in the messages outbox, exactly as the cron path does.
     */
    public function sendNow(int $reminderId): string
    {
        if ($reminderId <= 0) {
            return 'skipped';
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM reminders WHERE id = ? AND status = 'pending' AND due_at <= NOW()"
        );
        $stmt->execute([$reminderId]);
        $row = $stmt->fetch();
        if (!$row) {
            return 'skipped'; // future-dated, already sent, or cancelled
        }
        if (!$this->claimForDispatch($row)) {
            return 'skipped'; // another process got there first
        }
        try {
            return $this->dispatchOne($row);
        } catch (Throwable $e) {
            $this->markFailed($reminderId, $e->getMessage());
            Log::write('scheduler', 'reminder_failed', $row['entity_type'], (int)$row['entity_id'],
                ['reminder_id' => $reminderId, 'error' => $e->getMessage(), 'inline' => true]);
            return 'failed';
        }
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
            if (!$this->claimForDispatch($r)) {
                continue; // a concurrent dispatcher owns this one
            }
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

    /**
     * Opportunistic, self-throttling dispatcher for the future-dated reminders
     * (inactivity nudges, sign cadence, appointment reminders). The instant rules
     * — welcome, agent-assigned, closing — already send the moment they fire via
     * sendNow(); this only exists to flush time-delayed work WITHOUT a system cron.
     *
     * Called on dashboard page loads. Runs at most once per $minIntervalSec across
     * the whole app (guarded by a timestamp in `settings`), and only when there is
     * actually something due, so it adds no measurable cost to a normal page view.
     * Any external cron calling bin/scheduler.php still works unchanged.
     */
    public function tickWeb(int $minIntervalSec = 60): array
    {
        $now  = time();
        $last = (int)\Glue\Settings::get('scheduler.last_web_tick', 0);
        if ($now - $last < $minIntervalSec) {
            return ['ran' => false, 'reason' => 'throttled'];
        }
        // Claim the window first so concurrent requests don't all run runDue().
        \Glue\Settings::set('scheduler.last_web_tick', (string)$now);

        // Cheap existence check before the heavier runDue().
        $due = (int)$this->db->query(
            "SELECT COUNT(*) FROM reminders WHERE status='pending' AND due_at <= NOW()"
        )->fetchColumn();
        if ($due === 0) {
            return ['ran' => true, 'due' => 0];
        }
        return ['ran' => true] + $this->runDue();
    }

    /**
     * Atomically claim a due reminder before dispatching it (optimistic lock on
     * `attempts`). Web-tick dispatch runs on page loads, so two simultaneous
     * requests can both select the same due row; without this claim both sent it
     * (customers received every recurring reminder twice) and each re-enqueue
     * forked the repeat chain. Exactly one caller wins the UPDATE.
     */
    private function claimForDispatch(array $r): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE reminders SET attempts = attempts + 1
             WHERE id = ? AND status = 'pending' AND attempts = ?"
        );
        $stmt->execute([(int)$r['id'], (int)$r['attempts']]);
        return $stmt->rowCount() === 1;
    }

    /** Returns 'sent' or 'skipped'. */
    private function dispatchOne(array $r): string
    {
        $reminderId = (int)$r['id'];
        $entityId   = (int)$r['entity_id'];
        $ruleKey    = $r['rule_key'];
        $payload    = $r['payload'] ? json_decode($r['payload'], true) : [];

        // Manual-silence guard: if the record has moved past the stage we were
        // waiting on, skip (the seller already acted / changed the stage). For a
        // recurring reminder this also ENDS the chain — we don't re-enqueue.
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
        $hadRecipient = false;

        // First-contact branding: the lead welcome (fires once per lead, on
        // creation) carries the configured image on both channels.
        $imageUrl = ($ruleKey === 'welcome' && $r['entity_type'] === 'lead'
            && $r['recipient_type'] === 'customer') ? self::welcomeImageUrl() : '';

        if ($channel === 'whatsapp' || $channel === 'both') {
            $phone = $this->recipientPhone($r, $vars);
            if ($phone !== '') {
                $hadRecipient = true;
                $text = Templates::whatsapp($ruleKey, $vars, $lang);
                $okAny = $this->notifier->whatsapp($phone, $text, $reminderId, null, $imageUrl ?: null) || $okAny;
            }
        }
        if ($channel === 'email' || $channel === 'both') {
            $to = $this->recipientEmail($r, $vars);
            if ($to !== '') {
                $hadRecipient = true;
                $mail = Templates::email($ruleKey, $vars, $lang);
                $html = $imageUrl !== ''
                    ? '<p><img src="' . htmlspecialchars($imageUrl, ENT_QUOTES) . '" alt="" style="max-width:100%;border-radius:8px"></p>' . $mail['html']
                    : $mail['html'];
                $okAny = $this->notifier->email($to, $mail['subject'], $html, $reminderId) || $okAny;
            }
        }

        // No phone/email to send to (e.g. a recurring agent nudge on a lead that
        // isn't assigned yet) is a clean skip, not a failure — a repeating rule
        // still re-enqueues below so it fires once the recipient exists.
        $outcome = !$hadRecipient ? 'skipped' : ($okAny ? 'sent' : 'failed');
        $this->mark($reminderId, $outcome);
        Log::write('scheduler', 'reminder_sent', $r['entity_type'], $entityId,
            ['reminder_id' => $reminderId, 'rule' => $ruleKey, 'ok' => $okAny, 'outcome' => $outcome]);

        // Recurring reminder: schedule the next occurrence. The stage guard above
        // already ended the chain if the record moved, so reaching here means it's
        // still uncontacted. We re-enqueue regardless of send success so a transient
        // WhatsApp failure doesn't silently stop the cadence.
        $this->scheduleNextOccurrence($r);

        return $outcome === 'sent' ? 'sent' : 'skipped';
    }

    /**
     * If this reminder repeats, enqueue the next occurrence one interval out. The
     * dedupe_key carries the occurrence timestamp so each one is a distinct row,
     * and skip_if_stage_changed_from is preserved so the guard keeps ending the
     * chain when the record finally moves.
     */
    private function scheduleNextOccurrence(array $r): void
    {
        $everyH = (int)($r['repeat_every_hours'] ?? 0);
        if ($everyH <= 0) {
            return;
        }
        // Deterministic occurrence time: step the ROW's due_at forward by whole
        // intervals until it lands in the future. Using time() here made the
        // dedupe key differ between two racing dispatchers (1s apart), forking
        // the chain into two — the "same notification arrives twice" bug.
        $intervalS = $everyH * 3600;
        $prev = strtotime((string)$r['due_at']) ?: time();
        $steps = max(1, intdiv(max(0, time() - $prev), $intervalS) + 1);
        $nextTs = $prev + $steps * $intervalS;
        $nextDue = date('Y-m-d H:i:s', $nextTs);
        $base = $r['dedupe_key'] ?? ('recur:' . $r['rule_key'] . ':' . $r['entity_type'] . ':' . (int)$r['entity_id']);
        // Strip any prior ":@<ts>" suffix so the key stays bounded, then re-stamp.
        $base = preg_replace('/:@\d+$/', '', (string)$base);

        $this->enqueue([
            'entity_type'    => $r['entity_type'],
            'entity_id'      => (int)$r['entity_id'],
            'rule_key'       => $r['rule_key'],
            'recipient_type' => $r['recipient_type'],
            'channel'        => $r['channel'],
            'due_at'         => $nextDue,
            'skip_if_stage_changed_from' => $r['skip_if_stage_changed_from'] ?? null,
            'repeat_every_hours'         => $everyH,
            'payload'        => $r['payload'] ? json_decode((string)$r['payload'], true) : null,
            'lang'           => $r['lang'] ?? null,
            'dedupe_key'     => $base . ':@' . $nextTs,
        ], false); // pure-queue: never send inline from the dispatcher
    }

    /**
     * Absolute public URL of the welcome image ('' when none is configured).
     * welcome.lead_image stores a site-relative path like /uploads/welcome-lead.png
     * (set by the Settings upload); TextMeBot and email clients need it absolute.
     */
    private static function welcomeImageUrl(): string
    {
        $path = (string)Config::get('welcome.lead_image', '');
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = Config::appBaseUrl() ?: rtrim((string)Config::get('app.base_url', ''), '/');
        return $base !== '' ? $base . $path : '';
    }

    /** Build template vars from the local record (resolver) + payload + company. */
    private function buildVars(array $r, array $payload): array
    {
        $entityId = (int)$r['entity_id'];
        $res = EntityResolver::resolve($r['entity_type'], $entityId);
        $company = (string)Config::get('mail.from_name', '')
            ?: (string)Config::get('app.company_name', 'our company');
        // Number a customer should call: the assigned agent's phone if we have one,
        // otherwise the office/logistics line, otherwise the company name.
        $officePhone = (string)($res['agent_phone'] ?? '')
            ?: (string)Config::get('logistics.phone', '')
            ?: $company;
        $vars = [
            'company'        => $company,
            'office_phone'   => $officePhone,
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

    // attempts is incremented by claimForDispatch(), not here.
    private function mark(int $id, string $status): void
    {
        $this->db->prepare(
            "UPDATE reminders SET status=?, sent_at=NOW() WHERE id=?"
        )->execute([$status, $id]);
    }

    private function markFailed(int $id, string $error): void
    {
        $this->db->prepare(
            "UPDATE reminders SET status='failed', last_error=? WHERE id=?"
        )->execute([mb_substr($error, 0, 1000), $id]);
    }
}
