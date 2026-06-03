<?php
declare(strict_types=1);

namespace Glue\Bitrix;

use Glue\Config;
use RuntimeException;

/**
 * Thin Bitrix24 REST client over an INBOUND webhook URL.
 *
 * base_url is the full webhook root WITH trailing slash:
 *   https://portal.bitrix24.eu/rest/1/TOKEN/
 * A call appends "<method>.json", e.g. crm.lead.add.json.
 *
 * Docs: https://apidocs.bitrix24.com/
 */
final class Client
{
    private string $baseUrl;
    private bool $verifySsl;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? Config::section('bitrix');
        $this->baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/') . '/';
        $this->verifySsl = (bool)($cfg['verify_ssl'] ?? true);

        if ($this->baseUrl === '/' || str_contains($this->baseUrl, 'CHANGE_ME')) {
            throw new RuntimeException('Bitrix base_url is not configured in config.php');
        }
    }

    /**
     * Call any REST method. Returns the decoded 'result' on success.
     * Throws RuntimeException on transport or Bitrix-level error.
     */
    public function call(string $method, array $params = []): mixed
    {
        $url = $this->baseUrl . ltrim($method, '/') . '.json';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Bitrix transport error on $method: $err");
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new RuntimeException("Bitrix non-JSON response on $method (HTTP $http): $body");
        }
        if (isset($json['error'])) {
            $desc = $json['error_description'] ?? $json['error'];
            throw new RuntimeException("Bitrix API error on $method: $desc");
        }
        return $json['result'] ?? null;
    }

    // ---- CRM convenience wrappers -------------------------------------------

    /** Create a lead. $fields uses Bitrix field names (TITLE, NAME, PHONE, ...). */
    public function addLead(array $fields): int
    {
        return (int)$this->call('crm.lead.add', [
            'fields' => $fields,
            'params' => ['REGISTER_SONET_EVENT' => 'Y'],
        ]);
    }

    public function getLead(int $id): ?array
    {
        $r = $this->call('crm.lead.get', ['id' => $id]);
        return is_array($r) ? $r : null;
    }

    public function getDeal(int $id): ?array
    {
        $r = $this->call('crm.deal.get', ['id' => $id]);
        return is_array($r) ? $r : null;
    }

    /** Fetch a Bitrix user (the agent) — name, work phone, email, position, photo. */
    public function getUser(int $id): ?array
    {
        $r = $this->call('user.get', ['ID' => $id]);
        return is_array($r) && isset($r[0]) ? $r[0] : null;
    }

    /**
     * Pull primary phone/email out of a CRM entity's multifield arrays.
     * Returns ['phone' => ?string, 'email' => ?string].
     */
    public static function primaryContacts(array $entity): array
    {
        $first = static function (?array $multi): ?string {
            if (!$multi) {
                return null;
            }
            foreach ($multi as $row) {
                if (!empty($row['VALUE'])) {
                    return (string)$row['VALUE'];
                }
            }
            return null;
        };
        return [
            'phone' => $first($entity['PHONE'] ?? null),
            'email' => $first($entity['EMAIL'] ?? null),
        ];
    }
}
