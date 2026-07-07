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

    /** $phoneE164 like +254712345678. Returns ['ok'=>bool, 'http'=>int, ...]. */
    public function sendWhatsapp(string $phoneE164, string $text): array
    {
        $gap = max(0, (int)($this->cfg['min_gap_seconds'] ?? 8));

        $res = [];
        for ($attempt = 0; $attempt <= self::RETRIES; $attempt++) {
            $this->waitForSlot($gap);
            $res = $this->callApi($phoneE164, $text);
            if ($res['ok'] || !self::looksRateLimited($res)) {
                return $res;
            }
            // Rate-limited despite the gap: back off a full gap and try again.
            if ($attempt < self::RETRIES) {
                sleep(max($gap, 5));
            }
        }
        return $res;
    }

    /**
     * App-wide spacing between sends. The last-send timestamp lives in the
     * `settings` table so web requests, webhooks and cron all share it. The
     * slot is claimed *before* sending so two concurrent requests don't both
     * fire immediately. Never throws (missing table => just send).
     */
    private function waitForSlot(int $gap): void
    {
        if ($gap <= 0) {
            return;
        }
        try {
            $last = (int)Settings::get('textmebot.last_send_at', 0);
            $wait = $last + $gap - time();
            if ($wait > 0) {
                sleep(min($wait, $gap));
            }
            Settings::set('textmebot.last_send_at', (string)time());
        } catch (Throwable) {
            // settings unavailable — don't block the send
        }
    }

    /** TextMeBot's "too fast" answer: non-success body mentioning a wait/limit. */
    private static function looksRateLimited(array $res): bool
    {
        $body = strtolower((string)($res['body'] ?? ''));
        return str_contains($body, 'wait')
            || str_contains($body, 'too many')
            || (int)($res['http'] ?? 0) === 429;
    }

    private function callApi(string $phoneE164, string $text): array
    {
        $url = ($this->cfg['endpoint'] ?? '') . '?' . http_build_query([
            'recipient' => $phoneE164,
            'apikey'    => $this->cfg['api_key'] ?? '',
            'text'      => $text,
        ]);

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
