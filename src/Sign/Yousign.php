<?php
declare(strict_types=1);

namespace Glue\Sign;

use Glue\Config;

/**
 * Thin Yousign API v3 client (eIDAS electronic signature). Reads its credentials
 * from the dashboard Settings (yousign.api_key, yousign.environment) so the key
 * lives in the DB, never in code. Auth is a Bearer API key.
 *
 * Sandbox:    https://api-sandbox.yousign.app/v3
 * Production: https://api.yousign.app/v3
 *
 * For now this exposes enabled() + test() (used by the Settings "Test" button);
 * the full sign flow (create request → upload PDF → add signer → activate →
 * webhook → download signed PDF) builds on the same request() helper.
 */
final class Yousign
{
    private string $apiKey;
    private string $env;

    public function __construct(?string $apiKey = null, ?string $env = null)
    {
        $this->apiKey = trim((string)($apiKey ?? Config::get('yousign.api_key', '')));
        $this->env    = (string)($env ?? Config::get('yousign.environment', 'sandbox')) === 'production'
            ? 'production' : 'sandbox';
    }

    public function baseUrl(): string
    {
        return $this->env === 'production'
            ? 'https://api.yousign.app/v3'
            : 'https://api-sandbox.yousign.app/v3';
    }

    public function environment(): string
    {
        return $this->env;
    }

    /** True when an API key looks configured (not blank / not a placeholder). */
    public function enabled(): bool
    {
        return $this->apiKey !== '' && !str_contains($this->apiKey, 'YOUR_') && stripos($this->apiKey, 'changeme') === false;
    }

    /**
     * Authenticated JSON request. Returns
     *   ['ok'=>bool, 'http'=>int, 'body'=>array|null, 'raw'=>string|false, 'error'=>string]
     * Never throws.
     */
    public function request(string $method, string $path, ?array $json = null): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $body = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'ok'    => $http >= 200 && $http < 300,
            'http'  => $http,
            'body'  => is_array($body) ? $body : null,
            'raw'   => $raw,
            'error' => $err,
        ];
    }

    /**
     * Verify the API key works against the configured environment. Returns
     * ['ok'=>bool, 'env'=>string, 'error'=>?string].
     */
    public function test(): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'env' => $this->env, 'error' => 'No API key configured'];
        }
        $r = $this->request('GET', '/signature_requests?limit=1');
        if ($r['ok']) {
            return ['ok' => true, 'env' => $this->env, 'error' => null];
        }
        // Surface a useful reason (Yousign returns 'detail'/'message' on errors).
        $why = $r['error'] !== '' ? $r['error'] : ('HTTP ' . $r['http']);
        $detail = $r['body']['detail'] ?? $r['body']['message'] ?? null;
        if ($detail) {
            $why .= ' — ' . (is_string($detail) ? $detail : json_encode($detail));
        } elseif ($r['http'] === 401 || $r['http'] === 403) {
            $why .= ' — key rejected (check the key and that it matches the ' . $this->env . ' environment)';
        }
        return ['ok' => false, 'env' => $this->env, 'error' => $why];
    }
}
