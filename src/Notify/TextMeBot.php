<?php
declare(strict_types=1);

namespace Glue\Notify;

use Glue\Config;
use Glue\Db;
use Glue\Settings;
use Throwable;

/**
 * WhatsApp via TextMeBot — carried over from the parking app. Reads its config
 * from the 'textmebot' block by default.
 *
 * TextMeBot enforces a minimum delay between two messages on the same API key;
 * a second message sent too soon is rejected (and lost), and WhatsApp bans a
 * number that sends in bursts. sendWhatsapp() therefore spaces ALL sends
 * app-wide AND across processes.
 *
 * Every sender RESERVES the next free moment before calling (reserveSlot): a
 * row in `settings` holds when the line is next free, claimed under a row lock,
 * so two processes that arrive at the same instant — cron draining the queue
 * while somebody saves a lead, a webhook next to a campaign — take consecutive
 * slots instead of both finding the line free and firing together. The slot is
 * then checked against when the previous message actually left, because a
 * process spends a moment between claiming and calling.
 *
 * If TextMeBot still answers with a rate-limit error, the send is retried up to
 * twice after waiting another gap — so "two events at the same time" (e.g. agent
 * assigned: customer + seller message) both go through instead of the second
 * being dropped.
 */
final class TextMeBot
{
    private const RETRIES = 2;

    /**
     * The floor under every gap, whatever the settings say: ten seconds between
     * two WhatsApps, however unrelated the two notifications are. The gateway
     * itself only asks for five; the rest is the office's rule for not looking
     * like a machine to WhatsApp.
     */
    public const MIN_GAP = 10;

    /** Where the next free moment to send is kept, shared by every process. */
    private const SLOT_KEY = 'textmebot.next_slot_at';

    /** How many times to re-try claiming it when another process is mid-claim. */
    private const SLOT_TRIES = 4;

    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? Config::section('textmebot');
    }

    public function enabled(): bool
    {
        $key = $this->cfg['api_key'] ?? '';
        return $key !== '' && !str_contains($key, 'YOUR_TEXTMEBOT');
    }

    /**
     * $phoneE164 like +254712345678. $mediaUrl (optional) is a PUBLIC URL of the
     * file TextMeBot attaches; $mediaKind says how: an 'image' rides on its
     * `file` parameter and shows in the chat, anything else on `document`, which
     * is what makes a PDF arrive as a file. Returns ['ok'=>bool, 'http'=>int, ...].
     */
    public function sendWhatsapp(string $phoneE164, string $text, ?string $mediaUrl = null, string $mediaKind = 'image'): array
    {
        $gap = self::gap($this->cfg);

        $res = [];
        for ($attempt = 0; $attempt <= self::RETRIES; $attempt++) {
            // Take a slot before calling. Two processes that arrive together —
            // cron draining the queue while somebody saves a lead, a webhook and
            // a campaign — get slots a full gap apart instead of both deciding
            // the line is free and firing at the same instant.
            $this->waitForSlot(self::reserveSlot($gap), $gap);
            $res = $this->callApi($phoneE164, $text, $mediaUrl, $mediaKind);
            $this->recordSend($gap);
            if ($res['ok'] || !self::looksRateLimited($res)) {
                return $res;
            }
            // Rate-limited despite the gap: back off a full gap and try again.
            if ($attempt < self::RETRIES) {
                sleep($gap);
            }
        }
        return $res;
    }

    /**
     * The wait between two WhatsApps, whoever is sending.
     *
     * Never below MIN_GAP — "se ti accorgi che ci sono invii simultanei di
     * notifiche anche per processi diversi, attendi almeno 10 secondi". The
     * office's own pacing setting sits on top of it.
     */
    public static function gap(?array $cfg = null): int
    {
        $cfg = $cfg ?? Config::section('textmebot');
        return max(self::MIN_GAP, (int)($cfg['min_gap_seconds'] ?? self::MIN_GAP));
    }

    /**
     * How long until the line is free, without taking the slot. What a request
     * somebody is waiting on asks before deciding to deliver inline at all
     * (Reminder\Scheduler::maySendInline) — nobody should watch a page sleep
     * out a minute of pacing when the cron tick will carry the message anyway.
     */
    public static function slotWaitSeconds(): int
    {
        try {
            $s = Db::pdo()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
            $s->execute([self::SLOT_KEY]);
            return max(0, (int)($s->fetchColumn() ?: 0) - time());
        } catch (Throwable) {
            return 0;   // settings unavailable: don't block the send on a guess
        }
    }

    /**
     * Claim the next free moment to send, and leave the one after it for whoever
     * comes next. Returns the unix time this caller may send at.
     *
     * The old shape — read the last send time, sleep the remainder — had a race
     * with nothing to lose it to while messages were seconds apart, and a real
     * one once they were a minute apart: two processes read the same timestamp,
     * both found the line free, and both sent at once. That is the burst the
     * gateway bans for. The row is locked for the microseconds it takes to work
     * out the slot and write the next one, never while sleeping.
     */
    private static function reserveSlot(int $gap): int
    {
        $now = time();
        if ($gap <= 0) {
            return $now;
        }
        $pdo = null;
        try {
            $pdo = Db::pdo();
            // The row has to exist before it can be locked — but create it OUTSIDE
            // the transaction. An INSERT IGNORE inside takes an insert-intention
            // lock that deadlocks against the locking read below when three
            // processes arrive together (measured: InnoDB 1213 on one of three).
            $pdo->prepare('INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)')
                ->execute([self::SLOT_KEY, (string)($now - 1)]);
        } catch (Throwable) {
            // already there, or no database — the attempts below decide
        }

        for ($try = 0; $pdo !== null && $try < self::SLOT_TRIES; $try++) {
            $own = false;
            try {
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }
                $sel = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ? FOR UPDATE');
                $sel->execute([self::SLOT_KEY]);
                $slot = max((int)($sel->fetchColumn() ?: 0), time());
                $pdo->prepare('UPDATE settings SET `value` = ? WHERE `key` = ?')
                    ->execute([(string)($slot + $gap), self::SLOT_KEY]);
                if ($own) {
                    $pdo->commit();
                }
                return $slot;
            } catch (Throwable) {
                // Deadlock or lock-wait: somebody else is reserving this very
                // moment, which is exactly the case this exists for. Let them
                // finish and ask again.
                try {
                    if ($own && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable) {
                    // InnoDB had already rolled it back
                }
                usleep(50000 * ($try + 1));
            }
        }

        // Could not coordinate at all. Assume the line is busy rather than fire
        // blind: a message a gap late is a nuisance, a message sent on top of
        // another is what gets the number banned.
        return $now + $gap;
    }

    /** Last send time within THIS process — Settings is request-cached, so two
     *  sends in one request would otherwise read the same stale timestamp. */
    private static int $lastSendAt = 0;

    /**
     * Sleep until the slot this sender reserved — and then until the gap has
     * really passed since the previous message LEFT.
     *
     * The slots are handed out in one burst when several processes arrive
     * together, so they are spaced from the moment of claiming; the message
     * before this one left a little after its own slot (loading, DNS, curl).
     * Measured at the gateway that showed up as 8.8s under a 10s rule. The
     * top-up measures from the departure that actually happened.
     */
    private function waitForSlot(int $slotAt, int $gap): void
    {
        $wait = $slotAt - time();
        if ($wait > 0) {
            sleep($wait);
        }
        for ($i = 0; $i < 3 && $gap > 0; $i++) {
            $due = self::lastSendAt() + $gap - time();
            if ($due <= 0) {
                return;
            }
            sleep(min($due, $gap));
        }
    }

    /** When the last message actually left, shared across processes, read fresh. */
    private static function lastSendAt(): int
    {
        $at = self::$lastSendAt;
        try {
            $s = Db::pdo()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
            $s->execute(['textmebot.last_send_at']);
            $at = max($at, (int)($s->fetchColumn() ?: 0));
        } catch (Throwable) {
            // no database — the in-process stamp still spaces this process
        }
        return $at;
    }

    /** Stamp "a send just happened" in-process and in shared settings. */
    private function recordSend(int $gap): void
    {
        // Rounded UP: a send at 12:00:00.6 that stamps 12:00:00 lets the next one
        // leave 0.6s early, and the rule is a floor, not an average.
        self::$lastSendAt = (int)ceil(microtime(true));
        try {
            Settings::set('textmebot.last_send_at', (string)self::$lastSendAt);
        } catch (Throwable) {
            // settings unavailable — in-process stamp still spaces this request
        }
        // Push the line forward from the moment the call ACTUALLY went out, not
        // from the slot that was reserved before it. A process spends a little
        // time between the two — loading, resolving, curl — and without this the
        // gap measured at the gateway came out just under the one asked for
        // (9.0s for a 10s rule). Never pulls the line earlier.
        try {
            Db::pdo()->prepare(
                'UPDATE settings SET `value` = GREATEST(CAST(`value` AS UNSIGNED), UNIX_TIMESTAMP() + ? + 1)
                  WHERE `key` = ?'
            )->execute([$gap, self::SLOT_KEY]);
        } catch (Throwable) {
            // no database to coordinate through; the reservation already spaced us
        }
    }

    /**
     * TextMeBot's "too fast" answer. The live gateway returns HTTP 403 with a body
     * like "ERROR: There is currently a limit of 1 messages per 5 seconds to
     * prevent a ban". Match that plus the usual rate-limit phrasings.
     */
    private static function looksRateLimited(array $res): bool
    {
        $body = strtolower((string)($res['body'] ?? ''));
        $http = (int)($res['http'] ?? 0);
        return $http === 429 || $http === 403
            || str_contains($body, 'limit of')
            || str_contains($body, 'per 5 seconds')
            || str_contains($body, 'wait')
            || str_contains($body, 'too many');
    }

    private function callApi(string $phoneE164, string $text, ?string $mediaUrl = null, string $mediaKind = 'image'): array
    {
        $params = [
            'recipient' => $phoneE164,
            'apikey'    => $this->cfg['api_key'] ?? '',
            'text'      => $text,
        ];
        if ($mediaUrl !== null && $mediaUrl !== '') {
            // A photo goes on `file`; a PDF or any other document on `document`,
            // or TextMeBot answers success and the customer receives nothing.
            $params[$mediaKind === 'document' ? 'document' : 'file'] = $mediaUrl;
        }
        $url = ($this->cfg['endpoint'] ?? '') . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $ok = ($body !== false) && $http === 200 && stripos((string)$body, 'success') !== false;
        return [
            'ok'    => $ok,
            'http'  => $http,
            'body'  => $body,
            'error' => $err,
        ];
    }
}
