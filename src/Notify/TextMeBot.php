<?php
declare(strict_types=1);

namespace Glue\Notify;

use Glue\Config;
use Glue\Settings;
use Throwable;

/**
 * WhatsApp via TextMeBot — carried over from the parking app. Reads its config
 * from the 'textmebot' block by default.
 *
 * TextMeBot enforces a minimum delay between two messages on the same API key;
 * a second message sent too soon is rejected (and lost). sendWhatsapp() therefore
 * spaces ALL sends app-wide: it remembers the last send time in `settings`
 * ('textmebot.last_send_at', shared across requests/cron) and sleeps out the
 * remainder of textmebot.min_gap_seconds before calling the API. If TextMeBot
 * still answers with a rate-limit error, the send is retried up to twice after
 * waiting another gap — so "two events at the same time" (e.g. agent assigned:
 * customer + seller message) both go through instead of the second being dropped.
 */
final class TextMeBot
{
    private const RETRIES = 2;

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
     * $phoneE164 like +254712345678. $mediaUrl (optional) is a public URL of an
     * image TextMeBot attaches to the message (its `file` parameter; PDFs would
     * use `document` instead). Returns ['ok'=>bool, 'http'=>int, ...].
     */
    public function sendWhatsapp(string $phoneE164, string $text, ?string $mediaUrl = null): array
    {
        // TextMeBot bans on "1 message per 5 seconds"; keep a safe margin over 5s.
        $gap = max(0, (int)($this->cfg['min_gap_seconds'] ?? 6));

        $res = [];
        for ($attempt = 0; $attempt <= self::RETRIES; $attempt++) {
            $this->waitForSlot($gap);
            $res = $this->callApi($phoneE164, $text, $mediaUrl);
            $this->recordSend();
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

    /** Last send time within THIS process — Settings is request-cached, so two
     *  sends in one request would otherwise read the same stale timestamp. */
    private static int $lastSendAt = 0;

    /**
     * Block until at least $gap seconds have passed since the previous send.
     * The timestamp is tracked both in-process (static, for two sends in one
     * request) and in the `settings` table (shared across web requests, webhooks
     * and cron). Never throws — settings being unavailable just skips the shared
     * part. Does NOT record the send time; recordSend() does that after the call
     * so a failed/blocked attempt doesn't push the next one further out.
     */
    private function waitForSlot(int $gap): void
    {
        if ($gap <= 0) {
            return;
        }
        $last = self::$lastSendAt;
        try {
            $last = max($last, (int)Settings::get('textmebot.last_send_at', 0));
        } catch (Throwable) {
            // settings unavailable — fall back to the in-process timestamp
        }
        if ($last > 0) {
            $wait = $last + $gap - time();
            if ($wait > 0) {
                sleep($wait);
            }
        }
    }

    /** Stamp "a send just happened" in-process and in shared settings. */
    private function recordSend(): void
    {
        self::$lastSendAt = time();
        try {
            Settings::set('textmebot.last_send_at', (string)self::$lastSendAt);
        } catch (Throwable) {
            // settings unavailable — in-process stamp still spaces this request
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

    private function callApi(string $phoneE164, string $text, ?string $mediaUrl = null): array
    {
        $params = [
            'recipient' => $phoneE164,
            'apikey'    => $this->cfg['api_key'] ?? '',
            'text'      => $text,
        ];
        if ($mediaUrl !== null && $mediaUrl !== '') {
            $params['file'] = $mediaUrl;
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
