<?php
declare(strict_types=1);

namespace Glue\Sibill;

use Glue\Config;
use RuntimeException;

/**
 * Thin Sibill Integration API client.
 *
 * Auth is a bearer token issued by Sibill for one organisation; every resource
 * except /companies hangs off a company id, which we read from config so callers
 * just say get('documents').
 *
 *   https://integration.sibill.com/api/v1/companies/{company_id}/documents
 *
 * Docs: https://docs.sibill.com/api-reference/
 *
 * Read-only by design: this integration answers "was it paid?", it never issues
 * a document. The token can write, so keep it that way deliberately — a POST to
 * /documents/invoice files a real electronic invoice with the SDI and there is
 * no sandbox to rehearse it in.
 */
final class Client
{
    /** Sibill caps page_size at 100. */
    private const MAX_PAGE = 100;

    private string $baseUrl;
    private string $token;
    private string $companyId;
    private int $timeout;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? Config::section('sibill');
        $this->baseUrl   = rtrim((string)($cfg['base_url'] ?? 'https://integration.sibill.com'), '/');
        $this->token     = trim((string)($cfg['api_key'] ?? ''));
        $this->companyId = trim((string)($cfg['company_id'] ?? ''));
        $this->timeout   = max(5, (int)($cfg['timeout'] ?? 30));

        if ($this->token === '' || str_contains($this->token, 'CHANGE_ME')) {
            throw new RuntimeException('Sibill api_key is not configured (Settings → Sibill)');
        }
    }

    /** True when there is enough config to try a call — lets callers skip quietly. */
    public static function configured(): bool
    {
        $key = trim((string)Config::get('sibill.api_key', ''));
        return $key !== '' && !str_contains($key, 'CHANGE_ME');
    }

    public function companyId(): string
    {
        if ($this->companyId === '') {
            throw new RuntimeException('Sibill company_id is not set — run the connection test to discover it');
        }
        return $this->companyId;
    }

    /** The organisations this token can see. Used by the connection test. */
    public function companies(): array
    {
        $res = $this->request('GET', '/api/v1/companies');
        return $res['data'] ?? [];
    }

    /**
     * GET a company-scoped collection, following the cursor until it runs out.
     * $onPage receives each page's rows and returns false to stop early (used to
     * cut the walk short once we are past the sync horizon).
     */
    public function each(string $resource, array $query, callable $onPage): int
    {
        $path = '/api/v1/companies/' . $this->companyId() . '/' . ltrim($resource, '/');
        $query['page_size'] = min(self::MAX_PAGE, max(1, (int)($query['page_size'] ?? self::MAX_PAGE)));
        $seen = 0;
        $cursor = null;

        // Hard stop: the cap is far above any real dataset and only exists so a
        // malformed cursor can never spin the scheduler forever.
        for ($page = 0; $page < 500; $page++) {
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }
            $res  = $this->request('GET', $path, $query);
            $rows = $res['data'] ?? [];
            $seen += count($rows);

            if ($onPage($rows) === false) {
                return $seen;
            }
            $meta = $res['page'] ?? [];
            if (empty($meta['has_next_page']) || empty($meta['cursor'])) {
                return $seen;
            }
            $cursor = (string)$meta['cursor'];
        }
        return $seen;
    }

    /**
     * One HTTP call. Returns the decoded body; throws on transport, non-2xx or
     * non-JSON. Sibill reports problems as {"errors":[{title, detail, source}]}.
     */
    public function request(string $method, string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if ($query) {
            // Nested filters travel as filter[direction]=ISSUED; http_build_query
            // percent-encodes the brackets, which Sibill accepts.
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Sibill transport error on $path: $err");
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new RuntimeException("Sibill non-JSON response on $path (HTTP $http)");
        }
        if ($http < 200 || $http >= 300 || isset($json['errors'])) {
            throw new RuntimeException("Sibill API error on $path (HTTP $http): " . self::errorText($json));
        }
        return $json;
    }

    /** Flatten the errors array into one readable line. */
    private static function errorText(array $json): string
    {
        $parts = [];
        foreach ($json['errors'] ?? [] as $e) {
            $where = $e['source']['pointer'] ?? '';
            $parts[] = trim(($e['title'] ?? 'error') . ' ' . ($e['detail'] ?? '') . ($where !== '' ? " ($where)" : ''));
        }
        return $parts ? implode('; ', $parts) : 'unknown error';
    }
}
