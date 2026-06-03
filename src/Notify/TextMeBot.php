<?php
declare(strict_types=1);

namespace Glue\Notify;

use Glue\Config;

/**
 * WhatsApp via TextMeBot — carried over from the parking app, unchanged in
 * behaviour. Reads its config from the 'textmebot' block by default.
 */
final class TextMeBot
{
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
