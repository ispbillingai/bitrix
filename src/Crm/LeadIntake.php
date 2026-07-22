<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;

/**
 * Turns whatever an outside system POSTs at us into a lead.
 *
 * Two endpoints use it: `webhooks/lead.php` (the documented partner API — a
 * company sending us their leads from their own software) and
 * `webhooks/form-intake.php` (a website form / Jotform posting directly). They
 * differ only in how strict they are about the payload; the field naming, the
 * de-duplication and the write itself are the same, and live here.
 *
 * Field naming is deliberately forgiving: integrators send `telefono` or
 * `phone`, `messaggio` or `message`, in whatever casing their form produces.
 * We accept all of it and normalise to the one set of keys Leads::create wants.
 */
final class LeadIntake
{
    /**
     * Canonical field => accepted incoming names (compared case-insensitively,
     * first non-empty match wins).
     */
    private const ALIASES = [
        // 'nome' is a FIRST name, not a full one — listing it under 'name' would
        // make "nome=Luca, cognome=Bianchi" store just "Luca".
        'name'        => ['name', 'full_name', 'fullname', 'nome_completo', 'nominativo', 'q3_name'],
        'first_name'  => ['first_name', 'firstname', 'nome'],
        'last_name'   => ['last_name', 'lastname', 'surname', 'cognome'],
        'phone'       => ['phone', 'telephone', 'mobile', 'tel', 'telefono', 'cellulare', 'whatsapp'],
        'email'       => ['email', 'e-mail', 'mail', 'e_mail'],
        'company'     => ['company', 'azienda', 'ragione_sociale', 'business_name'],
        'vat_number'  => ['vat_number', 'vat', 'vat_id', 'partita_iva', 'piva', 'p_iva'],
        'source'      => ['source', 'origin', 'origine', 'fonte', 'partner'],
        'source_url'  => ['source_url', 'website', 'site', 'site_url', 'page_url', 'url', 'sito', 'sito_web', 'referrer'],
        'external_id' => ['external_id', 'externalid', 'reference', 'ref', 'request_id', 'remote_id'],
        'title'       => ['title', 'subject', 'oggetto', 'titolo'],
        'comments'    => ['comments', 'comment', 'message', 'messaggio', 'note', 'notes', 'richiesta', 'description'],
        'zone'        => ['zone', 'zona', 'area', 'region', 'regione', 'provincia'],
        'lang'        => ['lang', 'language', 'lingua', 'locale'],
    ];

    /**
     * The request body as an array, whatever the sender used: JSON, form-encoded,
     * or Jotform's `rawRequest` blob. Returns [] when the body is unusable.
     */
    public static function payload(): array
    {
        $raw  = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = $_POST ?: [];
        }
        // Jotform wraps the real answers in a "rawRequest" JSON string.
        if (isset($data['rawRequest']) && is_string($data['rawRequest'])) {
            $jf = json_decode($data['rawRequest'], true);
            if (is_array($jf)) {
                $data = array_merge($jf, $data);
            }
        }
        return $data;
    }

    /**
     * Map an inbound payload onto the keys Leads::create understands.
     * Every value is a trimmed string; missing fields come back as ''.
     */
    public static function normalize(array $data): array
    {
        $out = [];
        foreach (self::ALIASES as $field => $names) {
            $out[$field] = self::pick($data, $names);
        }

        // "Mario Rossi" may arrive whole or as nome + cognome.
        if ($out['name'] === '') {
            $out['name'] = trim($out['first_name'] . ' ' . $out['last_name']);
        }
        unset($out['first_name'], $out['last_name']);

        // Lowercased here (not only in Leads::create) so the duplicate lookup
        // below compares against what is actually stored.
        $out['source'] = mb_strtolower($out['source']);
        $out['email']  = mb_strtolower($out['email']);
        $out['phone']  = self::phone($out['phone']);

        // A sender's own id is unique only inside their own system, and senders
        // share a `source` (they all land in 'website'), so the site they posted
        // from is folded in before it is used as the de-duplication key —
        // otherwise two partners both numbering from 1 would collide and the
        // second one's lead would come back as a "duplicate" of the first.
        $out['external_id'] = self::qualifyExternalId($out['external_id'], $out['source_url']);

        return $out;
    }

    /**
     * The lead this payload was already turned into, if any — so a retried or
     * double-fired webhook doesn't produce two leads (and two welcome messages).
     *
     * Matched on the sender's own id when they send one; otherwise on the same
     * source reaching us with the same phone/email inside $windowMinutes, which
     * is what a double-submitted form looks like.
     */
    public static function findDuplicate(array $lead, int $windowMinutes = 15): ?int
    {
        $source     = (string)($lead['source'] ?? '');
        $externalId = (string)($lead['external_id'] ?? '');

        if ($source !== '' && $externalId !== '') {
            $stmt = Db::pdo()->prepare(
                'SELECT id FROM leads WHERE source = ? AND external_id = ? ORDER BY id ASC LIMIT 1'
            );
            $stmt->execute([$source, $externalId]);
            return (int)($stmt->fetchColumn() ?: 0) ?: null;
        }

        $phone = (string)($lead['phone'] ?? '');
        $email = (string)($lead['email'] ?? '');
        if ($windowMinutes < 1 || ($phone === '' && $email === '')) {
            return null;
        }
        // Prepares are not emulated (see Db), so every placeholder appears once
        // and the already-validated window is inlined.
        $mins = (int)$windowMinutes;
        $stmt = Db::pdo()->prepare(
            "SELECT id FROM leads
              WHERE source = :source
                AND created_at > NOW() - INTERVAL $mins MINUTE
                AND ((:has_phone = 1 AND customer_phone = :phone)
                  OR (:has_email = 1 AND customer_email = :email))
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([
            ':source'    => $source,
            ':has_phone' => $phone !== '' ? 1 : 0,
            ':phone'     => $phone,
            ':has_email' => $email !== '' ? 1 : 0,
            ':email'     => $email,
        ]);
        return (int)($stmt->fetchColumn() ?: 0) ?: null;
    }

    /**
     * Create the lead unless this payload was already delivered.
     * @return array{lead_id:int,duplicate:bool}
     */
    public static function submit(array $lead, int $windowMinutes = 15): array
    {
        $existing = self::findDuplicate($lead, $windowMinutes);
        if ($existing !== null) {
            return ['lead_id' => $existing, 'duplicate' => true];
        }
        return ['lead_id' => Leads::create($lead), 'duplicate' => false];
    }

    /**
     * Namespace the sender's id with the host it came from ("michaeltech.it:4711")
     * so ids only have to be unique within each sender's own system. Left alone
     * when we weren't told the site — nothing to namespace it with.
     */
    private static function qualifyExternalId(string $externalId, string $sourceUrl): string
    {
        if ($externalId === '' || $sourceUrl === '') {
            return $externalId;
        }
        $host = self::host($sourceUrl);
        if ($host === '' || str_starts_with($externalId, $host . ':')) {
            return $externalId;
        }
        $qualified = $host . ':' . $externalId;
        // The column holds 64 chars; stay deterministic if a long host + id blows past it.
        return strlen($qualified) <= 64 ? $qualified : substr($host, 0, 23) . ':' . sha1($qualified);
    }

    /** Bare hostname of a URL, lowercased and without "www.". '' if unusable. */
    public static function host(string $url): string
    {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '') {
            // No scheme ("michaeltech.it/contatti") — parse_url calls that a path.
            $host = (string)strtok(ltrim($url, '/'), '/');
        }
        $host = mb_strtolower(trim($host));
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** First non-empty value among $names, compared case-insensitively. */
    private static function pick(array $data, array $names): string
    {
        foreach ($names as $wanted) {
            foreach ($data as $key => $value) {
                if (is_scalar($value) && strcasecmp((string)$key, $wanted) === 0 && trim((string)$value) !== '') {
                    return trim((string)$value);
                }
            }
        }
        return '';
    }

    /**
     * Tidy a phone number for storage: keep a leading +, drop the spaces, dots
     * and brackets people type. 00-prefixed international numbers become +.
     * Nothing more — WhatsApp sending applies the country code itself.
     */
    private static function phone(string $phone): string
    {
        if ($phone === '') {
            return '';
        }
        $plus  = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (!$plus && str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }
        return ($plus ? '+' : '') . $digits;
    }
}
