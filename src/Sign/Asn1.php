<?php
declare(strict_types=1);

namespace Glue\Sign;

/**
 * The smallest DER encoder/decoder that a CAdES signature needs.
 *
 * PHP's openssl_* functions hand back finished PKCS#7 blobs and give no way to
 * add the signed attributes CAdES requires (signingCertificateV2), so we build
 * the CMS structure ourselves and only borrow openssl_sign() for the raw RSA
 * operation. That means encoding a handful of DER types by hand — and reading
 * just enough of them back to pull a token out of a TSA reply.
 *
 * Everything here is DER (definite length, minimal encoding), never BER.
 */
final class Asn1
{
    public const SEQUENCE     = 0x30;
    public const SET          = 0x31;
    public const INTEGER      = 0x02;
    public const BIT_STRING   = 0x03;
    public const OCTET_STRING = 0x04;
    public const NUL          = 0x05;
    public const OID          = 0x06;
    public const UTF8_STRING  = 0x0C;
    public const IA5_STRING   = 0x16;
    public const UTC_TIME     = 0x17;
    public const GEN_TIME     = 0x18;
    public const BOOLEAN      = 0x01;

    // ---- encoding ---------------------------------------------------------------

    /** Tag + DER length + content. */
    public static function tlv(int $tag, string $content): string
    {
        return chr($tag) . self::len(strlen($content)) . $content;
    }

    /** DER length: short form below 128, else long form with a minimal byte count. */
    public static function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $bytes = '';
        while ($n > 0) {
            $bytes = chr($n & 0xFF) . $bytes;
            $n >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function seq(string ...$parts): string
    {
        return self::tlv(self::SEQUENCE, implode('', $parts));
    }

    /**
     * A DER SET OF: the members must be sorted by their encoding, which is what
     * makes the digest over signedAttrs reproducible for the verifier.
     */
    public static function setOf(string ...$parts): string
    {
        sort($parts, SORT_STRING);
        return self::tlv(self::SET, implode('', $parts));
    }

    public static function octet(string $s): string
    {
        return self::tlv(self::OCTET_STRING, $s);
    }

    /** BIT STRING with no unused trailing bits — all we ever need. */
    public static function bitString(string $s): string
    {
        return self::tlv(self::BIT_STRING, "\x00" . $s);
    }

    public static function null(): string
    {
        return "\x05\x00";
    }

    public static function bool(bool $v): string
    {
        return self::tlv(self::BOOLEAN, $v ? "\xFF" : "\x00");
    }

    public static function utf8(string $s): string
    {
        return self::tlv(self::UTF8_STRING, $s);
    }

    public static function ia5(string $s): string
    {
        return self::tlv(self::IA5_STRING, $s);
    }

    /** INTEGER from a PHP int (non-negative in every CMS field we emit). */
    public static function int(int $n): string
    {
        if ($n === 0) {
            return self::tlv(self::INTEGER, "\x00");
        }
        $bytes = '';
        while ($n > 0) {
            $bytes = chr($n & 0xFF) . $bytes;
            $n >>= 8;
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes; // keep it positive
        }
        return self::tlv(self::INTEGER, $bytes);
    }

    /**
     * INTEGER from raw big-endian bytes — for certificate serial numbers, which
     * routinely exceed PHP's int range. Leading zeros are stripped and one is
     * put back if the top bit would otherwise read as a negative number.
     */
    public static function intFromBytes(string $raw): string
    {
        $raw = ltrim($raw, "\x00");
        if ($raw === '') {
            $raw = "\x00";
        }
        if (ord($raw[0]) & 0x80) {
            $raw = "\x00" . $raw;
        }
        return self::tlv(self::INTEGER, $raw);
    }

    /** INTEGER from a decimal string (serials as printed by openssl). */
    public static function intFromDecimal(string $dec): string
    {
        $dec = ltrim(trim($dec), '+');
        if ($dec === '' || !ctype_digit($dec)) {
            return self::int(0);
        }
        // Repeated division by 256 — no bcmath/gmp dependency.
        $bytes = '';
        while ($dec !== '' && $dec !== '0') {
            $rem = 0;
            $quot = '';
            $len = strlen($dec);
            for ($i = 0; $i < $len; $i++) {
                $cur = $rem * 10 + (int)$dec[$i];
                $q = intdiv($cur, 256);
                $rem = $cur % 256;
                if ($quot !== '' || $q > 0) {
                    $quot .= (string)$q;
                }
            }
            $bytes = chr($rem) . $bytes;
            $dec = $quot === '' ? '0' : $quot;
        }
        return self::intFromBytes($bytes === '' ? "\x00" : $bytes);
    }

    /** OBJECT IDENTIFIER from dotted notation, e.g. "1.2.840.113549.1.7.1". */
    public static function oid(string $dotted): string
    {
        $parts = array_map('intval', explode('.', $dotted));
        if (count($parts) < 2) {
            return self::tlv(self::OID, '');
        }
        $body = chr($parts[0] * 40 + $parts[1]);
        foreach (array_slice($parts, 2) as $n) {
            $chunk = chr($n & 0x7F);
            $n >>= 7;
            while ($n > 0) {
                $chunk = chr(0x80 | ($n & 0x7F)) . $chunk;
                $n >>= 7;
            }
            $body .= $chunk;
        }
        return self::tlv(self::OID, $body);
    }

    /** UTCTime as CMS wants it: two-digit year, always UTC, always seconds. */
    public static function utcTime(int $ts): string
    {
        return self::tlv(self::UTC_TIME, gmdate('ymdHis', $ts) . 'Z');
    }

    public static function generalizedTime(int $ts): string
    {
        return self::tlv(self::GEN_TIME, gmdate('YmdHis', $ts) . 'Z');
    }

    /** [n] constructed — the tagged wrappers in ContentInfo/SignedData. */
    public static function tagged(int $n, string $content): string
    {
        return self::tlv(0xA0 | $n, $content);
    }

    /** [n] primitive (implicit) — used for IMPLICIT OCTET STRING content. */
    public static function taggedPrimitive(int $n, string $content): string
    {
        return self::tlv(0x80 | $n, $content);
    }

    /** AlgorithmIdentifier with an absent parameter (the RFC 5754 SHA-2 form). */
    public static function algo(string $oid, bool $withNull = false): string
    {
        return self::seq(self::oid($oid) . ($withNull ? self::null() : ''));
    }

    // ---- decoding ---------------------------------------------------------------

    /**
     * Read one TLV at $offset. Returns [tag, headerLen, contentLen, content] and
     * advances $offset past the whole element. Returns null on a malformed or
     * truncated element rather than throwing — callers treat that as "not the
     * structure I expected" and give up cleanly.
     *
     * @return array{tag:int, header:int, length:int, content:string}|null
     */
    public static function read(string $der, int &$offset): ?array
    {
        $total = strlen($der);
        if ($offset < 0 || $offset + 2 > $total) {
            return null;
        }
        $tag = ord($der[$offset]);
        $p   = $offset + 1;
        $first = ord($der[$p++]);
        if ($first < 0x80) {
            $length = $first;
        } else {
            $n = $first & 0x7F;
            // Indefinite length (0x80) is BER, not DER — reject it.
            if ($n === 0 || $n > 4 || $p + $n > $total) {
                return null;
            }
            $length = 0;
            for ($i = 0; $i < $n; $i++) {
                $length = ($length << 8) | ord($der[$p++]);
            }
        }
        if ($length < 0 || $p + $length > $total) {
            return null;
        }
        $el = [
            'tag'     => $tag,
            'header'  => $p - $offset,
            'length'  => $length,
            'content' => substr($der, $p, $length),
        ];
        $offset = $p + $length;
        return $el;
    }

    /**
     * The children of a constructed element, as raw re-encoded TLVs (so each can
     * be fed straight back into read() or spliced into a new structure).
     *
     * @return string[]
     */
    public static function children(string $content): array
    {
        $out = [];
        $off = 0;
        while ($off < strlen($content)) {
            $start = $off;
            if (self::read($content, $off) === null) {
                break;
            }
            $out[] = substr($content, $start, $off - $start);
        }
        return $out;
    }
}
