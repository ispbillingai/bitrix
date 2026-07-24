<?php
declare(strict_types=1);

namespace Glue\Sign;

/**
 * Independent verification — the part that has to work years from now, on a
 * machine that may no longer be ours.
 *
 * Nothing here trusts the database's own opinion. The signed PDF is re-read from
 * disk, its /ByteRange is walked, the CMS blob is parsed by hand and the
 * signature is checked against the certificate embedded in the file itself. The
 * database is then compared against that result, not the other way round.
 *
 * That means a verification can disagree with the CRM — which is the point.
 */
final class Verify
{
    private const OID_MESSAGE_DIGEST = '1.2.840.113549.1.9.4';

    /**
     * Full report for one document.
     *
     * A document that has not been signed yet is reported as `pending`, not as a
     * failure — "no signature" and "a signature that does not check out" are very
     * different answers and must not look the same.
     *
     * @return array{
     *   found:bool, pending:bool, document:?array, signature:?array,
     *   checks:array<int,array{key:string,ok:bool,detail:string}>,
     *   chain:array, cms:?array, ok:bool
     * }
     */
    public static function document(array $doc): array
    {
        $checks = [];
        $pending = (string)$doc['status'] !== 'signed';
        $sig = Documents::signature((int)$doc['id']);

        // 1. the original, still byte-identical to what was uploaded
        $origPath = Documents::originalPath($doc);
        if ($origPath === null) {
            $checks[] = self::check('original_present', false, 'the original file is missing from storage');
        } else {
            $actual = hash_file('sha256', $origPath);
            $checks[] = self::check('original_intact', hash_equals((string)$doc['orig_sha256'], $actual),
                hash_equals((string)$doc['orig_sha256'], $actual)
                    ? 'matches the fingerprint recorded at upload'
                    : 'the stored file no longer matches its recorded fingerprint');
        }

        // 2. the sealed PDF, still byte-identical to what was produced
        $cms = null;
        $signedPath = Documents::signedPath($doc);
        if ($pending) {
            // Nothing to check yet — say so, and do not count it as a failure.
        } elseif ($signedPath === null) {
            $checks[] = self::check('sealed_present', false, 'the sealed PDF is missing from storage');
        } else {
            $bytes = (string)file_get_contents($signedPath);
            $actual = hash('sha256', $bytes);
            $intact = hash_equals((string)$doc['signed_sha256'], $actual);
            $checks[] = self::check('sealed_intact', $intact, $intact
                ? 'matches the fingerprint recorded at signing'
                : 'the sealed PDF has changed since it was produced');

            // 3. the cryptographic signature inside that PDF
            $cms = self::signedPdf($bytes);
            $checks[] = self::check('signature_valid', $cms['ok'], $cms['detail']);
            if ($cms['ok']) {
                $checks[] = self::check('covers_whole_file', $cms['covers_all'], $cms['covers_all']
                    ? 'the signature covers the entire file apart from the signature itself'
                    : 'part of the file lies outside the signed byte ranges');
            }
        }

        // 4. the operation log
        $chain = Audit::verify((int)$doc['id']);
        $checks[] = self::check('log_chain', $chain['ok'], $chain['ok']
            ? sprintf('%d entries, chain intact', $chain['entries'])
            : sprintf('broken at entry %d — %s', (int)$chain['broken_at'], (string)$chain['reason']));

        // 5. the evidence record behind the signature
        if ($sig) {
            $recomputed = hash('sha256', (string)$sig['evidence_json']);
            $ok = hash_equals((string)$sig['evidence_sha256'], $recomputed);
            $checks[] = self::check('evidence_intact', $ok, $ok
                ? 'the signing evidence matches its recorded fingerprint'
                : 'the signing evidence has been altered');
        }

        $ok = true;
        foreach ($checks as $c) {
            $ok = $ok && $c['ok'];
        }

        return [
            'found' => true, 'pending' => $pending, 'document' => $doc, 'signature' => $sig,
            'checks' => $checks, 'chain' => $chain, 'cms' => $cms, 'ok' => $ok,
        ];
    }

    /**
     * Verify the CAdES signature embedded in a signed PDF, using only the file.
     *
     * @return array{ok:bool, detail:string, covers_all:bool, signer:?array, signed_at:?string}
     */
    public static function signedPdf(string $pdf): array
    {
        $fail = fn(string $why): array => [
            'ok' => false, 'detail' => $why, 'covers_all' => false, 'signer' => null, 'signed_at' => null,
        ];

        if (!preg_match('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $m)) {
            return $fail('no signature byte range found in the file');
        }
        [$a, $b, $c, $d] = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]];
        if ($a !== 0 || $b <= 0 || $c < $b || $d < 0 || $c + $d > strlen($pdf)) {
            return $fail('the signature byte range is not consistent with the file');
        }

        // The gap between the two ranges is where the signature lives; everything
        // else must be covered, or someone appended content after signing.
        $coversAll = ($c + $d) === strlen($pdf);
        $covered = substr($pdf, $a, $b) . substr($pdf, $c, $d);

        $gap = substr($pdf, $b, $c - $b);
        if (!preg_match('/<([0-9A-Fa-f]+)>/', $gap, $hm) || strlen($hm[1]) % 2 !== 0) {
            return $fail('the signature slot does not contain a signature');
        }
        // The slot is zero-padded to a fixed width; the DER's own length header
        // says where the structure really ends, so read it rather than trimming.
        $blob = (string)hex2bin($hm[1]);
        $end = 0;
        if (Asn1::read($blob, $end) === null) {
            return $fail('the signature could not be decoded');
        }
        $der = substr($blob, 0, $end);

        $parsed = self::parseCms($der);
        if ($parsed === null) {
            return $fail('the signature is not a CMS structure this verifier understands');
        }

        // The signed attributes must claim the digest the file actually has.
        $actual = hash('sha256', $covered, true);
        if (!hash_equals($parsed['message_digest'], $actual)) {
            return $fail('the signature was made over different content — the file has been modified');
        }

        $pub = openssl_pkey_get_public(self::derToPem($parsed['cert'], 'CERTIFICATE'));
        if ($pub === false) {
            return $fail('the signing certificate in the file could not be read');
        }
        // Signed attributes are hashed as an explicit SET OF, not as the [0]
        // they are stored under — the same re-tagging the signer did.
        $verified = openssl_verify("\x31" . substr($parsed['signed_attrs'], 1),
            $parsed['signature'], $pub, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return $fail('the signature does not verify against the certificate in the file');
        }

        $info = openssl_x509_parse(self::derToPem($parsed['cert'], 'CERTIFICATE')) ?: [];
        return [
            'ok'         => true,
            'detail'     => 'verified against the certificate embedded in the file',
            'covers_all' => $coversAll,
            'signer'     => [
                'subject'     => self::dnText($info['subject'] ?? []),
                'issuer'      => self::dnText($info['issuer'] ?? []),
                'serial'      => strtoupper((string)($info['serialNumberHex'] ?? '')),
                'fingerprint' => hash('sha256', $parsed['cert']),
                'not_after'   => isset($info['validTo_time_t']) ? date('Y-m-d', (int)$info['validTo_time_t']) : null,
                'self_signed' => ($info['issuer'] ?? null) == ($info['subject'] ?? []),
            ],
            'signed_at'  => $parsed['signing_time'],
        ];
    }

    /**
     * Pull what we need out of a CMS SignedData: the signer's certificate, the
     * signed attributes, the signature, and the digest the signer claimed.
     *
     * @return array{cert:string, signed_attrs:string, signature:string,
     *               message_digest:string, signing_time:?string}|null
     */
    private static function parseCms(string $der): ?array
    {
        $off = 0;
        $ci = Asn1::read($der, $off);
        if ($ci === null) {
            return null;
        }
        $ciParts = Asn1::children($ci['content']);            // OID, [0] SignedData
        if (count($ciParts) < 2) {
            return null;
        }
        $o = 0;
        $wrap = Asn1::read($ciParts[1], $o);
        if ($wrap === null) {
            return null;
        }
        $o = 0;
        $sd = Asn1::read($wrap['content'], $o);
        if ($sd === null) {
            return null;
        }
        $sdParts = Asn1::children($sd['content']);

        $certs = null;
        $signerInfos = null;
        foreach ($sdParts as $part) {
            $tag = ord($part[0]);
            if ($tag === 0xA0 && $certs === null) {
                $certs = $part;
            } elseif ($tag === Asn1::SET) {
                $signerInfos = $part;   // the last SET is signerInfos
            }
        }
        if ($certs === null || $signerInfos === null) {
            return null;
        }

        $o = 0;
        $certsEl = Asn1::read($certs, $o);
        $certList = $certsEl ? Asn1::children($certsEl['content']) : [];
        if (!$certList) {
            return null;
        }

        $o = 0;
        $siSet = Asn1::read($signerInfos, $o);
        $siList = $siSet ? Asn1::children($siSet['content']) : [];
        if (!$siList) {
            return null;
        }
        $o = 0;
        $si = Asn1::read($siList[0], $o);
        if ($si === null) {
            return null;
        }
        $siParts = Asn1::children($si['content']);

        $signedAttrs = null;
        $signature = null;
        foreach ($siParts as $part) {
            $tag = ord($part[0]);
            if ($tag === 0xA0) {
                $signedAttrs = $part;
            } elseif ($tag === Asn1::OCTET_STRING) {
                $signature = $part;   // the signature value; the sid holds no OCTET STRING
            }
        }
        if ($signedAttrs === null || $signature === null) {
            return null;
        }
        $o = 0;
        $sigEl = Asn1::read($signature, $o);

        $attrs = self::attributes($signedAttrs);
        $digest = $attrs[self::OID_MESSAGE_DIGEST] ?? null;
        if ($digest === null) {
            return null;
        }
        $o = 0;
        $digestEl = Asn1::read($digest, $o);

        return [
            // The signer's certificate is the first in the set; a chain follows it.
            'cert'           => $certList[0],
            'signed_attrs'   => $signedAttrs,
            'signature'      => $sigEl ? $sigEl['content'] : '',
            'message_digest' => $digestEl ? $digestEl['content'] : '',
            'signing_time'   => self::signingTime($attrs),
        ];
    }

    /**
     * Map a signed-attributes block to OID => first value (raw TLV).
     *
     * @return array<string,string>
     */
    private static function attributes(string $tagged): array
    {
        $o = 0;
        $el = Asn1::read($tagged, $o);
        if ($el === null) {
            return [];
        }
        $out = [];
        foreach (Asn1::children($el['content']) as $attr) {
            $ao = 0;
            $seq = Asn1::read($attr, $ao);
            if ($seq === null) {
                continue;
            }
            $parts = Asn1::children($seq['content']);
            if (count($parts) < 2) {
                continue;
            }
            $oo = 0;
            $oidEl = Asn1::read($parts[0], $oo);
            $so = 0;
            $setEl = Asn1::read($parts[1], $so);
            if ($oidEl === null || $setEl === null) {
                continue;
            }
            $values = Asn1::children($setEl['content']);
            if ($values) {
                $out[self::oidText($oidEl['content'])] = $values[0];
            }
        }
        return $out;
    }

    private static function signingTime(array $attrs): ?string
    {
        $raw = $attrs['1.2.840.113549.1.9.5'] ?? null;
        if ($raw === null) {
            return null;
        }
        $o = 0;
        $el = Asn1::read($raw, $o);
        if ($el === null) {
            return null;
        }
        $v = rtrim($el['content'], 'Z');
        // UTCTime is yymmddhhmmss; GeneralizedTime is yyyymmddhhmmss.
        $fmt = $el['tag'] === Asn1::UTC_TIME ? 'ymdHis' : 'YmdHis';
        $dt = \DateTimeImmutable::createFromFormat($fmt, $v, new \DateTimeZone('UTC'));
        return $dt ? $dt->format('Y-m-d H:i:s') . ' UTC' : null;
    }

    /** Decode an OID's content octets back to dotted notation. */
    private static function oidText(string $body): string
    {
        if ($body === '') {
            return '';
        }
        $first = ord($body[0]);
        $parts = [intdiv($first, 40), $first % 40];
        $value = 0;
        for ($i = 1; $i < strlen($body); $i++) {
            $b = ord($body[$i]);
            $value = ($value << 7) | ($b & 0x7F);
            if (!($b & 0x80)) {
                $parts[] = $value;
                $value = 0;
            }
        }
        return implode('.', $parts);
    }

    public static function derToPem(string $der, string $label): string
    {
        return "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
    }

    private static function dnText(array $dn): string
    {
        $bits = [];
        foreach (['CN', 'OU', 'O', 'L', 'ST', 'C'] as $k) {
            if (isset($dn[$k])) {
                $v = is_array($dn[$k]) ? implode('/', $dn[$k]) : (string)$dn[$k];
                $bits[] = $k . '=' . $v;
            }
        }
        return implode(', ', $bits);
    }

    private static function check(string $key, bool $ok, string $detail): array
    {
        return ['key' => $key, 'ok' => $ok, 'detail' => $detail];
    }
}
