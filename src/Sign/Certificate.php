<?php
declare(strict_types=1);

namespace Glue\Sign;

use Glue\Config;
use Glue\Event\Log;

/**
 * The signing key pair — the one part of this system whose independence you can
 * actually buy. Two modes, same code path:
 *
 *   configured  sign.pkcs12_path (+ sign.pkcs12_pass), or sign.cert_path plus
 *               sign.key_path in PEM. This is where the eIDAS certificate goes
 *               once purchased; nothing else in the app changes.
 *   fallback    no certificate configured, so one is generated and kept under
 *               storage/sign/keys. Signatures are then cryptographically sound
 *               but only prove "this CRM sealed it" — they carry no external
 *               trust. Every document records which one signed it.
 *
 * The issuer name and serial are lifted straight out of the certificate's DER
 * (openssl_x509_parse re-formats them, and the CMS SignerInfo has to match the
 * certificate byte for byte).
 */
final class Certificate
{
    private string $certDer;
    private string $certPem;
    /** @var \OpenSSLAsymmetricKey */
    private $key;
    /** @var string[] extra chain certificates, DER */
    private array $chainDer = [];
    private array $info;
    private bool $selfSigned;

    private static ?self $instance = null;

    public static function load(): self
    {
        return self::$instance ??= new self();
    }

    /** Forget the cached instance — used after the certificate is replaced. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private function __construct()
    {
        [$certPem, $keyPem, $chainPem] = self::sourceMaterial();

        $cert = openssl_x509_read($certPem);
        if ($cert === false) {
            throw new \RuntimeException('signing certificate could not be read: ' . self::opensslErrors());
        }
        $key = openssl_pkey_get_private($keyPem, (string)Config::get('sign.key_pass', ''));
        if ($key === false) {
            throw new \RuntimeException('signing private key could not be read: ' . self::opensslErrors());
        }
        if (openssl_x509_check_private_key($cert, $key) !== true) {
            throw new \RuntimeException('signing certificate and private key do not match');
        }

        openssl_x509_export($cert, $exported);
        $this->certPem = $exported;
        $this->certDer = self::pemToDer($exported);
        $this->key     = $key;
        $this->info    = openssl_x509_parse($cert) ?: [];
        $this->selfSigned = ($this->info['issuer'] ?? null) == ($this->info['subject'] ?? []);

        foreach ($chainPem as $pem) {
            $der = self::pemToDer($pem);
            if ($der !== '') {
                $this->chainDer[] = $der;
            }
        }
    }

    // ---- what the CMS builder needs ----------------------------------------------

    public function certDer(): string
    {
        return $this->certDer;
    }

    public function certPem(): string
    {
        return $this->certPem;
    }

    /** @return string[] the signing certificate followed by any chain certificates */
    public function allCertsDer(): array
    {
        return array_merge([$this->certDer], $this->chainDer);
    }

    /** @return \OpenSSLAsymmetricKey */
    public function privateKey()
    {
        return $this->key;
    }

    /**
     * The issuer Name, exactly as encoded in the certificate. tbsCertificate is
     * SEQUENCE { [0] version OPTIONAL, serialNumber, signature, issuer, ... } —
     * so skip the optional version, take the INTEGER as the serial, skip the
     * signature AlgorithmIdentifier, and the next element is the issuer.
     *
     * @return array{issuer:string, serial:string}
     */
    private function tbsFields(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $fallback = ['issuer' => '', 'serial' => ''];

        $off = 0;
        $certSeq = Asn1::read($this->certDer, $off);
        if ($certSeq === null) {
            return $cached = $fallback;
        }
        $inner = 0;
        $tbs = Asn1::read($certSeq['content'], $inner);
        if ($tbs === null) {
            return $cached = $fallback;
        }
        $parts = Asn1::children($tbs['content']);
        // Drop the [0] EXPLICIT version if present (absent means v1).
        if ($parts && ord($parts[0][0]) === 0xA0) {
            array_shift($parts);
        }
        if (count($parts) < 3) {
            return $cached = $fallback;
        }
        return $cached = [
            'serial' => $parts[0],  // INTEGER, re-used verbatim
            'issuer' => $parts[2],  // Name (the SEQUENCE of RDNs)
        ];
    }

    /** DER of the issuer Name, for IssuerAndSerialNumber. */
    public function issuerDer(): string
    {
        return $this->tbsFields()['issuer'];
    }

    /** DER of the serial INTEGER, for IssuerAndSerialNumber. */
    public function serialDer(): string
    {
        return $this->tbsFields()['serial'];
    }

    // ---- human-readable identity, for the certificate page and the DB -------------

    public function subjectText(): string
    {
        return self::dnText($this->info['subject'] ?? []);
    }

    public function issuerText(): string
    {
        return self::dnText($this->info['issuer'] ?? []);
    }

    public function commonName(): string
    {
        $s = $this->info['subject'] ?? [];
        $cn = $s['CN'] ?? ($s['O'] ?? '');
        return is_array($cn) ? (string)reset($cn) : (string)$cn;
    }

    public function serialHex(): string
    {
        $hex = (string)($this->info['serialNumberHex'] ?? '');
        if ($hex === '' && isset($this->info['serialNumber'])) {
            $hex = strtoupper(bin2hex(Asn1::intFromDecimal((string)$this->info['serialNumber'])));
        }
        return strtoupper($hex);
    }

    /** SHA-256 over the certificate DER — how the certificate is identified. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->certDer);
    }

    public function notAfter(): ?int
    {
        $t = $this->info['validTo_time_t'] ?? null;
        return $t ? (int)$t : null;
    }

    public function notBefore(): ?int
    {
        $t = $this->info['validFrom_time_t'] ?? null;
        return $t ? (int)$t : null;
    }

    public function isSelfSigned(): bool
    {
        return $this->selfSigned;
    }

    public function isExpired(): bool
    {
        $end = $this->notAfter();
        return $end !== null && $end < time();
    }

    /** Days until expiry (negative once expired), or null when unknown. */
    public function daysLeft(): ?int
    {
        $end = $this->notAfter();
        return $end === null ? null : (int)floor(($end - time()) / 86400);
    }

    /** A one-line summary for the dashboard's Settings page. */
    public function summary(): array
    {
        return [
            'subject'     => $this->subjectText(),
            'issuer'      => $this->issuerText(),
            'serial'      => $this->serialHex(),
            'fingerprint' => $this->fingerprint(),
            'not_after'   => $this->notAfter() ? date('Y-m-d H:i', (int)$this->notAfter()) : null,
            'days_left'   => $this->daysLeft(),
            'self_signed' => $this->selfSigned,
            'expired'     => $this->isExpired(),
        ];
    }

    // ---- loading / generating -----------------------------------------------------

    /**
     * @return array{0:string, 1:string, 2:string[]} cert PEM, key PEM, chain PEMs
     */
    private static function sourceMaterial(): array
    {
        $p12 = (string)Config::get('sign.pkcs12_path', '');
        if ($p12 !== '' && is_readable($p12)) {
            $blob = (string)file_get_contents($p12);
            $out = [];
            if (!openssl_pkcs12_read($blob, $out, (string)Config::get('sign.pkcs12_pass', ''))) {
                throw new \RuntimeException('PKCS#12 could not be opened (wrong password?): ' . self::opensslErrors());
            }
            return [(string)($out['cert'] ?? ''), (string)($out['pkey'] ?? ''), (array)($out['extracerts'] ?? [])];
        }

        $certPath = (string)Config::get('sign.cert_path', '');
        $keyPath  = (string)Config::get('sign.key_path', '');
        if ($certPath !== '' && $keyPath !== '' && is_readable($certPath) && is_readable($keyPath)) {
            $chain = [];
            $chainPath = (string)Config::get('sign.chain_path', '');
            if ($chainPath !== '' && is_readable($chainPath)) {
                preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
                    (string)file_get_contents($chainPath), $m);
                $chain = $m[0] ?? [];
            }
            return [(string)file_get_contents($certPath), (string)file_get_contents($keyPath), $chain];
        }

        return self::selfSignedMaterial();
    }

    /**
     * The no-certificate-yet path: generate a 3072-bit key and a long-lived
     * self-signed certificate once, then reuse it. Kept out of the web root and
     * written 0600 — if this file leaks, every signature it made is forgeable.
     *
     * @return array{0:string, 1:string, 2:string[]}
     */
    private static function selfSignedMaterial(): array
    {
        $dir      = Store::path('keys');
        $certFile = $dir . '/signing-cert.pem';
        $keyFile  = $dir . '/signing-key.pem';

        if (is_readable($certFile) && is_readable($keyFile)) {
            return [(string)file_get_contents($certFile), (string)file_get_contents($keyFile), []];
        }

        $company = (string)Config::get('app.company_name', 'CRM');
        $conf = self::opensslConfig($dir);

        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $conf);
        if ($key === false) {
            throw new \RuntimeException('could not generate a signing key: ' . self::opensslErrors());
        }
        $dn = [
            'countryName'            => (string)Config::get('sign.country', 'IT'),
            'organizationName'       => mb_substr($company, 0, 64),
            'organizationalUnitName' => 'Electronic Signature',
            'commonName'             => mb_substr($company . ' Signing Authority', 0, 64),
        ];
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256'] + $conf);
        if ($csr === false) {
            throw new \RuntimeException('could not build the signing CSR: ' . self::opensslErrors());
        }
        $x509 = openssl_csr_sign($csr, null, $key, 3650,
            ['digest_alg' => 'sha256', 'x509_extensions' => 'v3_sign'] + $conf,
            random_int(1, PHP_INT_MAX));
        if ($x509 === false) {
            throw new \RuntimeException('could not self-sign the certificate: ' . self::opensslErrors());
        }

        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $keyPem, null, $conf);

        // Write the key first and lock it down before the certificate exists, so
        // there is never a moment where a readable key sits next to a usable cert.
        if (file_put_contents($keyFile, $keyPem, LOCK_EX) === false) {
            throw new \RuntimeException('could not store the signing key in ' . $dir);
        }
        @chmod($keyFile, 0600);
        file_put_contents($certFile, $certPem, LOCK_EX);
        @chmod($certFile, 0640);

        // Best-effort: the key pair is on disk and usable whether or not the
        // event log can be reached, and losing the note is better than losing it.
        try {
            Log::write('sign', 'self_signed_cert_generated', null, null, [
                'dir'  => $dir,
                'note' => 'no eIDAS certificate configured — signatures prove integrity, not external identity',
            ]);
        } catch (\Throwable) {
            // ignore
        }

        return [$certPem, $keyPem, []];
    }

    /**
     * openssl.cnf for the generation path. PHP needs one to find its defaults on
     * Windows, and we want a v3_sign section anyway so the generated certificate
     * carries the key usages a signing certificate is supposed to have.
     *
     * @return array{config?:string}
     */
    private static function opensslConfig(string $dir): array
    {
        $path = $dir . '/openssl-sign.cnf';
        if (!is_file($path)) {
            $cnf = "[ req ]\n"
                 . "default_bits = 3072\n"
                 . "distinguished_name = req_dn\n"
                 . "prompt = no\n"
                 . "[ req_dn ]\n"
                 . "[ v3_sign ]\n"
                 . "basicConstraints = critical, CA:FALSE\n"
                 . "keyUsage = critical, digitalSignature, nonRepudiation\n"
                 . "extendedKeyUsage = emailProtection\n"
                 . "subjectKeyIdentifier = hash\n";
            if (@file_put_contents($path, $cnf, LOCK_EX) === false) {
                return [];
            }
        }
        return ['config' => $path];
    }

    // ---- helpers ------------------------------------------------------------------

    public static function pemToDer(string $pem): string
    {
        if (!preg_match('/-----BEGIN [^-]+-----(.*?)-----END [^-]+-----/s', $pem, $m)) {
            return '';
        }
        return (string)base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
    }

    /** "CN=Acme Signing Authority, O=Acme, C=IT" from openssl_x509_parse output. */
    private static function dnText(array $dn): string
    {
        $order = ['CN', 'OU', 'O', 'L', 'ST', 'C', 'emailAddress'];
        $bits = [];
        foreach ($order as $k) {
            if (!isset($dn[$k])) {
                continue;
            }
            $v = is_array($dn[$k]) ? implode('/', $dn[$k]) : (string)$dn[$k];
            $bits[] = $k . '=' . $v;
        }
        return mb_substr(implode(', ', $bits), 0, 255);
    }

    private static function opensslErrors(): string
    {
        $msgs = [];
        while (($e = openssl_error_string()) !== false) {
            $msgs[] = $e;
        }
        return $msgs ? implode('; ', $msgs) : 'no detail from openssl';
    }
}
