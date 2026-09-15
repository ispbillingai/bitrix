<?php
declare(strict_types=1);

namespace Glue\Notify;

use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Throwable;

/**
 * An alert to the staff of one role (every active admin, every technician), by
 * WhatsApp and email — queued, never sent while the person who caused it waits.
 *
 * The loops this replaces sent each message inside the page request. With five
 * admins and TextMeBot's gap between WhatsApps, an agent's "Invia la richiesta"
 * hung for 50–110 seconds with its button greyed out by the double-submit
 * guard; the agent read that as a dead button and sent the request again
 * (quote requests #28 and #29, 2026-09-15), so the office got everything twice.
 *
 * One reminder row per recipient, carrying the finished text (raw_wa,
 * raw_subject, raw_html — Scheduler::dispatchOne sends those as they are).
 * Nothing is sent inside the request, not even the first: one inline WhatsApp
 * can itself wait out TextMeBot's 8-second gap. The scheduler's every-minute
 * cron delivers them, so the office hears within a minute and the page
 * answers at once.
 */
final class StaffAlert
{
    /**
     * @param string $ruleKey    what it is, for the Reminders list (rk_<key> in lang/ui.*.php)
     * @param string $entityType what it is about ('quote_request', 'assist_request'…); '' for nothing in particular
     * @return int how many people were queued (0: nobody of that role has a phone or an email)
     */
    public static function toRole(string $role, string $ruleKey, string $text, string $subject, string $html,
                                  string $entityType = '', int $entityId = 0): int
    {
        try {
            $stmt = Db::pdo()->prepare(
                "SELECT id, COALESCE(NULLIF(TRIM(full_name), ''), username) AS name, phone, email
                   FROM users WHERE role = ? AND active = 1 ORDER BY id"
            );
            $stmt->execute([$role]);
            $users = $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            Log::write('notify', 'staff_alert_failed', $entityType !== '' ? $entityType : 'user', $entityId,
                ['rule' => $ruleKey, 'error' => $e->getMessage()]);
            return 0;
        }

        $sched = new Scheduler();
        $batch = date('YmdHis') . bin2hex(random_bytes(3)); // one alert = one batch; the same text later is a new one
        $queued = 0;
        foreach ($users as $u) {
            $phone = trim((string)($u['phone'] ?? ''));
            $email = trim((string)($u['email'] ?? ''));
            if ($phone === '' && $email === '') {
                continue;
            }
            try {
                $sched->enqueue([
                    'entity_type'    => $entityType !== '' ? $entityType : 'user',
                    'entity_id'      => $entityType !== '' ? $entityId : (int)$u['id'],
                    'rule_key'       => $ruleKey,
                    // A staff member is addressed like an agent: agent_phone / agent_email below.
                    'recipient_type' => 'agent',
                    'channel'        => $phone !== '' && $email !== '' ? 'both' : ($phone !== '' ? 'whatsapp' : 'email'),
                    'due_at'         => date('Y-m-d H:i:s'),
                    'payload'        => [
                        'name'        => (string)$u['name'],
                        'agent_name'  => (string)$u['name'],
                        'agent_phone' => $phone,
                        'agent_email' => $email,
                        'raw_wa'      => $text,
                        'raw_subject' => $subject,
                        'raw_html'    => $html,
                    ],
                    'dedupe_key'     => mb_substr("$ruleKey:$entityType:$entityId:" . (int)$u['id'] . ":$batch", 0, 128),
                ], false); // queue only: the every-minute scheduler cron sends it
                $queued++;
            } catch (Throwable $e) {
                Log::write('notify', 'staff_alert_failed', $entityType !== '' ? $entityType : 'user', $entityId,
                    ['rule' => $ruleKey, 'user' => (int)$u['id'], 'error' => $e->getMessage()]);
            }
        }
        return $queued;
    }
}
