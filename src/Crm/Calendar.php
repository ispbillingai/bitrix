<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;

/**
 * The CRM's own calendar — no Google, no outside account, no OAuth.
 *
 * Everything it shows already lives in `appointments`: sales appointments
 * (kind = 'sales') and technical interventions (kind = 'intervention'). This
 * class is the two readings that a diary needs and the CRM did not have — a
 * month at a time for the grid, and an iCalendar feed so the same diary lands
 * on a phone.
 *
 * The feed is what replaces "integrate with Google Calendar". A technician
 * subscribes to their own .ics URL once and their round appears in whatever
 * calendar app they already use — Google, Apple, Outlook — alongside their own
 * life, refreshed automatically. Nobody hands over a password, the CRM stays
 * the only place a visit is booked, and there is no token to expire.
 */
final class Calendar
{
    /**
     * Everything booked in one month, oldest first.
     *
     * @param string $month 'YYYY-MM'
     * @param string $kind  'all' | 'intervention' | 'sales'
     * @return array<int,array>
     */
    public static function month(string $month, string $kind = 'all', ?int $staffId = null): array
    {
        $ts = strtotime($month . '-01');
        if (!$ts) {
            $ts = strtotime(date('Y-m-01'));
        }
        $from = date('Y-m-01 00:00:00', $ts);
        $to   = date('Y-m-t 23:59:59', $ts);

        $sql =
            'SELECT a.id, a.kind, a.title, a.location, a.starts_at, a.ends_at, a.status,
                    a.customer_name, a.customer_phone, a.contact_id, a.lead_id,
                    a.assist_request_id, a.ticket_id, a.notes,
                    u.full_name AS staff_name, u.username AS staff_username
               FROM appointments a
               LEFT JOIN users u ON u.id = a.agent_id
              WHERE a.starts_at BETWEEN ? AND ?
                AND a.status IN ("confirmed", "done")';
        $args = [$from, $to];
        if ($kind === 'intervention' || $kind === 'sales') {
            $sql .= ' AND a.kind = ?';
            $args[] = $kind;
        }
        if ($staffId) {
            $sql .= ' AND a.agent_id = ?';
            $args[] = $staffId;
        }
        $sql .= ' ORDER BY a.starts_at ASC, a.id ASC';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll() ?: [];
    }

    /** The month's rows grouped by 'Y-m-d', for a grid that renders one cell at a time. */
    public static function byDay(string $month, string $kind = 'all', ?int $staffId = null): array
    {
        $out = [];
        foreach (self::month($month, $kind, $staffId) as $r) {
            $out[date('Y-m-d', strtotime((string)$r['starts_at']))][] = $r;
        }
        return $out;
    }

    // ---- the subscribable feed --------------------------------------------------

    /** This user's feed token, minted on first use. */
    public static function tokenFor(int $userId): string
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare('SELECT calendar_token FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $tok = trim((string)$stmt->fetchColumn());
        if ($tok !== '') {
            return $tok;
        }
        return self::resetToken($userId);
    }

    /** Mint a fresh token — the old URL stops working at once. */
    public static function resetToken(int $userId): string
    {
        $tok = bin2hex(random_bytes(24));
        Db::pdo()->prepare('UPDATE users SET calendar_token = ? WHERE id = ?')->execute([$tok, $userId]);
        return $tok;
    }

    /** The staff member a feed token belongs to, or null. */
    public static function userForToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT id, full_name, username, active FROM users WHERE calendar_token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $u = $stmt->fetch();
        return ($u && (int)$u['active'] === 1) ? $u : null;
    }

    /**
     * The address a phone subscribes to. The plain .php endpoint, not the
     * /calendar.ics rewrite: the rewrite is an Apache nicety, and a URL a
     * technician pastes once into their phone must work on whatever the CRM is
     * actually served by.
     */
    public static function feedUrl(int $userId): string
    {
        return rtrim(Config::appBaseUrl(), '/') . '/calendar-feed.php?k=' . self::tokenFor($userId);
    }

    /**
     * One staff member's diary as iCalendar text.
     *
     * Deliberately narrow: from a month back (so a phone that syncs late still
     * shows last week) to a year ahead, and only what is actually booked. A
     * cancelled visit is published as CANCELLED rather than dropped, so a phone
     * that already holds the event removes it instead of keeping a ghost.
     */
    public static function ics(int $userId, string $calName): string
    {
        $stmt = Db::pdo()->prepare(
            'SELECT a.id, a.kind, a.title, a.location, a.starts_at, a.ends_at, a.status,
                    a.customer_name, a.customer_phone, a.notes, a.updated_at
               FROM appointments a
              WHERE a.agent_id = ? AND a.starts_at IS NOT NULL
                AND a.starts_at >= (NOW() - INTERVAL 1 MONTH)
                AND a.starts_at <= (NOW() + INTERVAL 1 YEAR)
              ORDER BY a.starts_at ASC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll() ?: [];

        // Times are stored in the server's local zone; the feed states that zone
        // rather than pretending UTC, which is what puts an event an hour out
        // twice a year.
        $tz   = date_default_timezone_get();
        $host = parse_url(Config::appBaseUrl(), PHP_URL_HOST) ?: 'crm.local';

        $out = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//' . $calName . '//CRM//IT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::esc($calName),
            'X-WR-TIMEZONE:' . $tz,
            // Apple and Google both honour this; without it a phone re-reads the
            // feed on its own whim, which for some clients means once a day.
            'REFRESH-INTERVAL;VALUE=DURATION:PT30M',
            'X-PUBLISHED-TTL:PT30M',
        ];

        foreach ($rows as $r) {
            $startTs = strtotime((string)$r['starts_at']);
            $endTs   = $r['ends_at'] ? strtotime((string)$r['ends_at']) : $startTs + 3600;
            $isInterv = (string)$r['kind'] === Interventions::KIND;
            $status  = (string)$r['status'];

            $summary = trim((string)$r['title']) !== '' ? (string)$r['title'] : (string)$r['customer_name'];
            $summary = ($isInterv ? '🔧 ' : '📅 ') . $summary
                . (trim((string)$r['customer_name']) !== '' ? ' — ' . $r['customer_name'] : '');

            $desc = [];
            if (trim((string)$r['customer_name']) !== '') { $desc[] = (string)$r['customer_name']; }
            if (trim((string)$r['customer_phone']) !== '') { $desc[] = (string)$r['customer_phone']; }
            if (trim((string)$r['notes']) !== '') { $desc[] = (string)$r['notes']; }

            $out[] = 'BEGIN:VEVENT';
            $out[] = 'UID:appt-' . (int)$r['id'] . '@' . $host;
            $out[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z', strtotime((string)($r['updated_at'] ?: $r['starts_at'])));
            $out[] = 'DTSTART;TZID=' . $tz . ':' . date('Ymd\THis', $startTs);
            $out[] = 'DTEND;TZID=' . $tz . ':' . date('Ymd\THis', $endTs);
            $out[] = 'SUMMARY:' . self::esc($summary);
            if (trim((string)$r['location']) !== '') {
                $out[] = 'LOCATION:' . self::esc((string)$r['location']);
            }
            if ($desc) {
                $out[] = 'DESCRIPTION:' . self::esc(implode("\n", $desc));
            }
            $out[] = 'STATUS:' . ($status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED');
            // A booked visit is busy time; a cancelled one is not.
            $out[] = 'TRANSP:' . ($status === 'cancelled' ? 'TRANSPARENT' : 'OPAQUE');
            $out[] = 'END:VEVENT';
        }

        $out[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $out)) . "\r\n";
    }

    /** RFC 5545 text escaping: backslash, semicolon, comma, newline. */
    private static function esc(string $s): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $s
        );
    }

    /**
     * RFC 5545 line folding at 75 octets. Split on bytes, not characters, then
     * nudge the cut off a UTF-8 continuation byte — folding mid-sequence is
     * legal by the letter of the spec but renders as a broken glyph on phones,
     * and the customer names here are full of accents.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $parts = [];
        $i = 0;
        $len = strlen($line);
        $limit = 75;
        while ($i < $len) {
            $take = min($limit, $len - $i);
            if ($i + $take < $len) {
                while ($take > 1 && (ord($line[$i + $take]) & 0xC0) === 0x80) {
                    $take--;
                }
            }
            $parts[] = ($i === 0 ? '' : ' ') . substr($line, $i, $take);
            $i += $take;
            $limit = 74; // continuation lines carry a leading space
        }
        return implode("\r\n", $parts);
    }
}
