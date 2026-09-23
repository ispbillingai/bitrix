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
     * Who hears the operational traffic — quote requests, assistance requests,
     * customer documents, restock alerts.
     *
     * It is the back office's job, so Amministrazione hears it; the
     * Administrator is kept on the list because before the roles were split
     * every one of these people WAS an admin, and dropping them would have
     * silently stopped the alerts on the day of the deploy. Move an account to
     * Amministrazione and it keeps receiving; leave it an Administrator and it
     * does too.
     */
    public const OFFICE = ['admin', 'office'];

    /**
     * @param string|array $role one role, or several (self::OFFICE)
     * @param string $ruleKey    what it is, for the Reminders list (rk_<key> in lang/ui.*.php)
     * @param string $entityType what it is about ('quote_request', 'assist_request'…); '' for nothing in particular
     * @return int how many people were queued (0: nobody of that role has a phone or an email)
     */
    public static function toRole($role, string $ruleKey, string $text, string $subject, string $html,
                                  string $entityType = '', int $entityId = 0): int
    {
        $roles = array_values(array_filter(array_map('strval', (array)$role)));
        if (!$roles) {
            return 0;
        }
        try {
            $in = implode(',', array_fill(0, count($roles), '?'));
            $stmt = Db::pdo()->prepare(
                "SELECT id, COALESCE(NULLIF(TRIM(full_name), ''), username) AS name, phone, email
                   FROM users WHERE role IN ($in) AND active = 1 ORDER BY id"
            );
            $stmt->execute($roles);
            $users = $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            Log::write('notify', 'staff_alert_failed', $entityType !== '' ? $entityType : 'user', $entityId,
                ['rule' => $ruleKey, 'error' => $e->getMessage()]);
            return 0;
        }
        return self::queue($users, $ruleKey, $text, $subject, $html, $entityType, $entityId);
    }

    /**
     * The same, to ONE person — a job handed to a colleague, the evening
     * planning prompt, an invitation to a chat. Queued for the same reason: it
     * happens inside a web request (or a cron pass) that must not wait on
     * WhatsApp.
     *
     * @param string $prefer 'both' (default), or 'whatsapp'/'email' to use that
     *        channel when the person has it and fall back to the other when
     *        they do not — for a nudge that would be noise sent twice.
     */
    public static function toUser(int $userId, string $ruleKey, string $text, string $subject, string $html,
                                  string $entityType = '', int $entityId = 0, string $prefer = 'both'): int
    {
        try {
            $stmt = Db::pdo()->prepare(
                "SELECT id, COALESCE(NULLIF(TRIM(full_name), ''), username) AS name, phone, email
                   FROM users WHERE id = ? AND active = 1"
            );
            $stmt->execute([$userId]);
            $users = $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            Log::write('notify', 'staff_alert_failed', $entityType !== '' ? $entityType : 'user', $entityId,
                ['rule' => $ruleKey, 'user' => $userId, 'error' => $e->getMessage()]);
            return 0;
        }
        return self::queue($users, $ruleKey, $text, $subject, $html, $entityType, $entityId, $prefer);
    }

    /**
     * Which channels one alert actually uses. 'both' is the default because an
     * alert somebody must act on should be hard to miss; a preference narrows
     * it to one channel where the person has it, and falls back rather than
     * dropping the message when they do not.
     */
    private static function channelFor(string $phone, string $email, string $prefer): string
    {
        if ($phone === '') { return 'email'; }
        if ($email === '') { return 'whatsapp'; }
        return $prefer === 'whatsapp' || $prefer === 'email' ? $prefer : 'both';
    }

    /** One reminder row per recipient, carrying the finished text. */
    private static function queue(array $users, string $ruleKey, string $text, string $subject, string $html,
                                  string $entityType, int $entityId, string $prefer = 'both'): int
    {
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
                    'channel'        => self::channelFor($phone, $email, $prefer),
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
