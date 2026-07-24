<?php
declare(strict_types=1);

namespace Glue\Sign;

use Glue\Config;
use Glue\Event\Log;

/**
 * RFC 3161 timestamping — the one place where buying independence is cheap.
 *
 * Our own signature proves the document has not changed since we sealed it, but
 * *we* choose the clock, so on its own it does not prove *when*. A timestamp
 * authority signs the hash of our signature with its own key and its own time,
 * and only the hash ever leaves the building. Off by default: set sign.tsa_url
 * (e.g. https://freetsa.org/tsr) to switch it on.
 *
 * Failure is never fatal. A document that could not be timestamped is still
 * signed and still valid — it just records our own time, and the audit trail
 * says the TSA was unreachable.
 */
final class Timestamp
{
    private const OID_SHA256   = '2.16.840.1.101.3.4.2.1';
    private const OID_TST_INFO = '1.2.840.113549.1.9.16.1.4';

    public static function enabled(): bool
    {
        return trim((string)Config::get('sign.tsa_url', '')) !== '';
    }

    public static function url(): string
    {
        return trim((string)Config::get('sign.tsa_url', ''));
    }

    /**
     * Timestamp $signature (the raw signature bytes from the SignerInfo, which
     * is what a signature-timestamp attribute covers).
     *
     * @return string|null DER TimeStampToken, or null when disabled or on any failure
     */
    public static function token(string $signature): ?string
    {
        $url = self::url();
        if ($url === '') {
            return null;
        }

        $req = self::request(hash('sha256', $signature, true));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $req,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)Config::get('sign.tsa_timeout', 15),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/timestamp-query',
                'Accept: application/timestamp-reply',
            ],
        ]);
        $user = (string)Config::get('sign.tsa_user', '');
        if ($user !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . (string)Config::get('sign.tsa_pass', ''));
        }
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $code >= 400) {
            Log::write('sign', 'tsa_failed', null, null,
                ['url' => $url, 'http' => $code, 'error' => $err ?: 'empty reply']);
            return null;
        }

        $token = self::extractToken($body, $status);
        if ($token === null) {
            Log::write('sign', 'tsa_rejected', null, null, ['url' => $url, 'status' => $status]);
            return null;
        }
        return $token;
    }

    /**
     * TimeStampReq ::= SEQUENCE { version, messageImprint, …, nonce, certReq }
     * certReq = TRUE so the reply carries the TSA certificate and the token can
     * be checked years later without asking the TSA for anything.
     */
    private static function request(string $digest): string
    {
        return Asn1::seq(
            Asn1::int(1)
            . Asn1::seq(Asn1::algo(self::OID_SHA256, true) . Asn1::octet($digest))
            . Asn1::intFromBytes(random_bytes(8))   // nonce — ties reply to request
            . Asn1::bool(true)                      // certReq
        );
    }

    /**
     * TimeStampResp ::= SEQUENCE { status PKIStatusInfo, timeStampToken OPTIONAL }
     * status 0 = granted, 1 = grantedWithMods; anything else is a refusal.
     */
    private static function extractToken(string $der, ?int &$status = null): ?string
    {
        $status = null;
        $off = 0;
        $resp = Asn1::read($der, $off);
        if ($resp === null || $resp['tag'] !== Asn1::SEQUENCE) {
            return null;
        }
        $parts = Asn1::children($resp['content']);
        if (!$parts) {
            return null;
        }

        $inner = 0;
        $info = Asn1::read($parts[0], $inner);
        if ($info !== null) {
            $statusParts = Asn1::children($info['content']);
            if ($statusParts) {
                $so = 0;
                $s = Asn1::read($statusParts[0], $so);
                $status = $s !== null && $s['content'] !== '' ? ord($s['content'][strlen($s['content']) - 1]) : null;
            }
        }
        if ($status !== 0 && $status !== 1) {
            return null;
        }
        return $parts[1] ?? null;
    }

    /**
     * The genTime the TSA actually asserted, dug out of the token's TSTInfo:
     * ContentInfo → SignedData → encapContentInfo → OCTET STRING → TSTInfo →
     * the GeneralizedTime after version/policy/messageImprint/serialNumber.
     *
     * @return int|null unix timestamp, or null when the token cannot be read
     */
    public static function tokenTime(string $token): ?int
    {
        $off = 0;
        $ci = Asn1::read($token, $off);
        if ($ci === null) {
            return null;
        }
        $ciParts = Asn1::children($ci['content']);          // [ OID, [0] SignedData ]
        if (count($ciParts) < 2) {
            return null;
        }
        $o = 0;
        $tagged = Asn1::read($ciParts[1], $o);
        if ($tagged === null) {
            return null;
        }
        $o = 0;
        $signedData = Asn1::read($tagged['content'], $o);
        if ($signedData === null) {
            return null;
        }
        $sdParts = Asn1::children($signedData['content']);  // version, digestAlgs, encapContentInfo, …
        if (count($sdParts) < 3) {
            return null;
        }
        $o = 0;
        $encap = Asn1::read($sdParts[2], $o);
        if ($encap === null) {
            return null;
        }
        $encapParts = Asn1::children($encap['content']);    // [ eContentType, [0] eContent ]
        if (count($encapParts) < 2) {
            return null;
        }
        $o = 0;
        $eContentTag = Asn1::read($encapParts[1], $o);
        if ($eContentTag === null) {
            return null;
        }
        $o = 0;
        $octet = Asn1::read($eContentTag['content'], $o);
        if ($octet === null) {
            return null;
        }
        $o = 0;
        $tstInfo = Asn1::read($octet['content'], $o);
        if ($tstInfo === null) {
            return null;
        }
        foreach (Asn1::children($tstInfo['content']) as $field) {
            if (ord($field[0]) === Asn1::GEN_TIME) {
                $fo = 0;
                $gt = Asn1::read($field, $fo);
                $ts = $gt ? strtotime(rtrim($gt['content'], 'Z') . ' UTC') : false;
                return $ts === false ? null : $ts;
            }
        }
        return null;
    }
}
