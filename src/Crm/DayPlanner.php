<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\StaffAlert;
use Glue\Reminder\Templates;

/**
 * "Plan tomorrow" — the evening prompt, its confirmation link, and the chasing
 * that follows when nobody clicks it.
 *
 * Every technician is asked, at a set hour, to sort out the next day: take what
 * they want out of the unassigned pool, group it by zone, move what does not
 * fit. The message carries a link that is the whole point of the exercise — the
 * CRM cannot see "has planned", so the technician says so by clicking, and
 * until they do the CRM keeps asking.
 *
 * Deliberately one row per technician per planned day (`planning_confirmations`,
 * unique on user + date). That makes the whole thing idempotent: a cron that
 * runs twice in the same minute, or a server that was down at 17:00 and catches
 * up at 17:04, cannot ask the same person twice for the same day.
 */
final class DayPlanner
{
    /** How many chasing messages at most, however long they ignore it. */
    private const MAX_NUDGES = 6;

    /**
     * Off until the office switches it on in Settings. This sends real WhatsApps
     * and emails to real technicians every evening, so it does not start doing
     * that because a deploy landed — the hours and the escalation cap want
     * looking at first, and the person who owns that decision is not the person
     * who deployed it.
     */
    public static function enabled(): bool
    {
        return (bool)Config::get('planning.enabled', false);
    }

    /**
     * The evening prompt. Called every minute by the scheduler; does nothing
     * until the configured hour, then once per technician for tomorrow.
     *
     * @return int how many people were asked
     */
    public static function runPrompt(): int
    {
        if (!self::enabled() || !self::pastTime((string)Config::get('planning.prompt_at', '17:00'))) {
            return 0;
        }
        $planDate = date('Y-m-d', strtotime('+1 day'));
        $sent = 0;
        foreach (self::technicians() as $u) {
            $row = self::rowFor((int)$u['id'], $planDate);
            if ($row === null || $row['confirmed_at'] !== null || (int)$row['nudges'] > 0) {
                continue; // already asked (or already done) for this day
            }
            if (self::send((int)$u['id'], (string)$u['name'], $row, $planDate, 'planning_prompt')) {
                $sent++;
            }
        }
        if ($sent > 0) {
            Log::write('crm', 'planning_prompt_sent', 'user', 0, ['date' => $planDate, 'people' => $sent]);
        }
        return $sent;
    }

    /**
     * The chase. From the escalation hour onward, anyone who has not confirmed
     * tomorrow's plan is asked again, at a spacing, up to a cap.
     *
     * @return int how many chasing messages went out
     */
    public static function runEscalation(): int
    {
        if (!self::enabled() || !self::pastTime((string)Config::get('planning.escalate_from', '19:00'))) {
            return 0;
        }
        $planDate = date('Y-m-d', strtotime('+1 day'));
        $everyMin = max(15, (int)Config::get('planning.escalate_every_min', 60));
        $maxN     = max(1, min(self::MAX_NUDGES, (int)Config::get('planning.escalate_max', 4)));

        $stmt = Db::pdo()->prepare(
            "SELECT p.*, COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS name
               FROM planning_confirmations p
               JOIN users u ON u.id = p.user_id AND u.active = 1
              WHERE p.plan_date = ? AND p.confirmed_at IS NULL AND p.nudges < ?
                AND (p.last_nudge_at IS NULL OR p.last_nudge_at <= (NOW() - INTERVAL ? MINUTE))"
        );
        $stmt->execute([$planDate, $maxN, $everyMin]);

        $n = 0;
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if (self::send((int)$row['user_id'], (string)$row['name'], $row, $planDate, 'planning_nudge')) {
                $n++;
            }
        }
        return $n;
    }

    /** The technician clicked the link. Returns the row, or null if the token is unknown. */
    public static function confirm(string $token): ?array
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            "SELECT p.*, COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS name
               FROM planning_confirmations p JOIN users u ON u.id = p.user_id
              WHERE p.token = ? LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ($row['confirmed_at'] === null) {
            Db::pdo()->prepare('UPDATE planning_confirmations SET confirmed_at = NOW() WHERE id = ?')
                ->execute([(int)$row['id']]);
            $row['confirmed_at'] = date('Y-m-d H:i:s');
            Log::write('crm', 'planning_confirmed', 'user', (int)$row['user_id'],
                ['date' => (string)$row['plan_date'], 'after_nudges' => (int)$row['nudges']]);
        }
        return $row;
    }

    /** Has this person confirmed the given day? Drives the banner in the calendar. */
    public static function status(int $userId, string $planDate): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM planning_confirmations WHERE user_id = ? AND plan_date = ?'
        );
        $stmt->execute([$userId, $planDate]);
        return $stmt->fetch() ?: null;
    }

    /** Confirm from inside the CRM — the button on the calendar, already logged in. */
    public static function confirmFor(int $userId, string $planDate): bool
    {
        $row = self::rowFor($userId, $planDate);
        if (!$row) {
            return false;
        }
        return self::confirm((string)$row['token']) !== null;
    }

    public static function linkFor(array $row): string
    {
        return rtrim(Config::appBaseUrl(), '/') . '/plan-confirm.php?t=' . (string)$row['token'];
    }

    // ---- internals -------------------------------------------------------------

    /**
     * True once today has passed HH:MM. The scheduler runs every minute, so
     * "past" rather than "equals": a minute missed to a slow cron pass would
     * otherwise skip the whole evening, and the once-per-day row keeps it from
     * firing repeatedly afterwards.
     */
    private static function pastTime(string $hhmm): bool
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($hhmm), $m)) {
            return false;
        }
        return time() >= mktime((int)$m[1], (int)$m[2], 0);
    }

    /** @return array<int,array{id:int,name:string}> */
    private static function technicians(): array
    {
        return Db::pdo()->query(
            "SELECT id, COALESCE(NULLIF(TRIM(full_name), ''), username) AS name
               FROM users
              WHERE active = 1 AND (role = 'tech' OR can_install = 1)
              ORDER BY id"
        )->fetchAll() ?: [];
    }

    /** The row for this person and day, created on first need. */
    private static function rowFor(int $userId, string $planDate): ?array
    {
        $pdo = Db::pdo();
        $get = $pdo->prepare('SELECT * FROM planning_confirmations WHERE user_id = ? AND plan_date = ?');
        $get->execute([$userId, $planDate]);
        if ($row = $get->fetch()) {
            return $row;
        }
        try {
            $pdo->prepare('INSERT INTO planning_confirmations (user_id, plan_date, token) VALUES (?,?,?)')
                ->execute([$userId, $planDate, bin2hex(random_bytes(24))]);
        } catch (\Throwable $e) {
            // Unique key: another pass created it a moment ago. Read theirs.
        }
        $get->execute([$userId, $planDate]);
        return $get->fetch() ?: null;
    }

    /** Render and queue one message, and count the attempt. */
    private static function send(int $userId, string $name, array $row, string $planDate, string $ruleKey): bool
    {
        $lang  = Templates::lang(Config::get('app.default_lang', 'it'));
        $mine  = self::countFor($userId, $planDate);
        $pool  = count(Booking::pool(200));
        $vars  = [
            'name'       => $name,
            'agent_name' => $name,
            'company'    => (string)Config::get('app.company_name', 'CRM'),
            'date'       => date('d/m/Y', (int)strtotime($planDate)),
            'count'      => (string)$mine,
            'pool'       => (string)$pool,
            'link'       => self::linkFor($row),
        ];
        $text = Templates::whatsapp($ruleKey, $vars, $lang);
        $mail = Templates::email($ruleKey, $vars, $lang);

        $queued = StaffAlert::toUser($userId, $ruleKey, $text,
            (string)$mail['subject'], (string)$mail['html'], 'user', $userId);
        if ($queued === 0) {
            return false; // no phone and no email on that account
        }
        Db::pdo()->prepare(
            'UPDATE planning_confirmations SET nudges = nudges + 1, last_nudge_at = NOW() WHERE id = ?'
        )->execute([(int)$row['id']]);
        return true;
    }

    /** How many jobs this person already has on the day being planned. */
    private static function countFor(int $userId, string $planDate): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM appointments
              WHERE agent_id = ? AND status = "confirmed" AND DATE(starts_at) = ?'
        );
        $stmt->execute([$userId, $planDate]);
        return (int)$stmt->fetchColumn();
    }
}
