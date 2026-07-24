<?php
declare(strict_types=1);

namespace Glue\Sign;

/**
 * CAdES-BES SignedData, built by hand.
 *
 * openssl_pkcs7_sign() would produce a valid PKCS#7, but it gives no way to add
 * the signed attributes CAdES asks for — above all signingCertificateV2, which
 * binds the signature to one specific certificate and stops anyone re-pointing
 * it at a different one. So the structure is assembled here and openssl is used
 * only for the raw signature over the signed attributes.
 *
 * Detached: eContent is absent, and the digest is of the PDF's byte ranges.
 * Adding the TSA token as an unsigned attribute lifts this to CAdES-T / PAdES-T.
 *
 *   ContentInfo { id-signedData, [0] SignedData }
 *   SignedData  { v1, {sha256}, {id-data}, [0] certs, {SignerInfo} }
 *   SignerInfo  { v1, IssuerAndSerial, sha256, [0] signedAttrs,
 *                 sigAlg, signature, [1] unsignedAttrs? }
 */
final class Cms
{
    private const OID_SIGNED_DATA   = '1.2.840.113549.1.7.2';
    private const OID_DATA          = '1.2.840.113549.1.7.1';
    private const OID_SHA256        = '2.16.840.1.101.3.4.2.1';
    private const OID_RSA           = '1.2.840.113549.1.1.1';
    private const OID_ECDSA_SHA256  = '1.2.840.10045.4.3.2';

    private const OID_A_CONTENT_TYPE   = '1.2.840.113549.1.9.3';
    private const OID_A_MESSAGE_DIGEST = '1.2.840.113549.1.9.4';
    private const OID_A_SIGNING_TIME   = '1.2.840.113549.1.9.5';
    private const OID_A_SIGNING_CERT_V2 = '1.2.840.113549.1.9.16.2.47';
    private const OID_A_TIMESTAMP      = '1.2.840.113549.1.9.16.2.14';

    /**
     * Sign $content detached.
     *
     * $timestamper, when given, is called with the raw signature bytes and may
     * return a DER TimeStampToken to attach as an unsigned attribute — the order
     * matters, because a signature timestamp covers the signature value, which
     * only exists once the signing is done.
     *
     * @param null|callable(string):?string $timestamper
     * @return string DER ContentInfo, ready to drop into the PDF's /Contents
     */
    public static function sign(string $content, Certificate $cert, int $signingTime, ?callable $timestamper = null): string
    {
        $digest = hash('sha256', $content, true);

        // --- signed attributes -----------------------------------------------------
        // Hashed and signed as an explicit SET OF (tag 0x31); the very same bytes
        // then go into the SignerInfo re-tagged as [0] IMPLICIT (0xA0). Getting
        // this backwards is the classic reason a CMS "signs" but never verifies.
        $signedAttrs = Asn1::setOf(
            self::attribute(self::OID_A_CONTENT_TYPE, Asn1::oid(self::OID_DATA)),
            self::attribute(self::OID_A_SIGNING_TIME, Asn1::utcTime($signingTime)),
            self::attribute(self::OID_A_MESSAGE_DIGEST, Asn1::octet($digest)),
            self::attribute(self::OID_A_SIGNING_CERT_V2, self::signingCertificateV2($cert))
        );

        $key  = $cert->privateKey();
        $sig  = '';
        if (!openssl_sign($signedAttrs, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('openssl_sign failed while signing the document');
        }

        // --- signer info -----------------------------------------------------------
        $issuerAndSerial = Asn1::seq($cert->issuerDer() . $cert->serialDer());

        $signerInfo = Asn1::int(1)
            . $issuerAndSerial
            . Asn1::algo(self::OID_SHA256)
            . self::retag($signedAttrs, 0xA0)                     // SET OF -> [0] IMPLICIT
            . self::signatureAlgorithm($key)
            . Asn1::octet($sig);

        $tsaToken = $timestamper !== null ? $timestamper($sig) : null;
        if ($tsaToken !== null && $tsaToken !== '') {
            $signerInfo .= self::retag(
                Asn1::setOf(self::attribute(self::OID_A_TIMESTAMP, $tsaToken)), 0xA1
            );
        }

        // --- signed data -----------------------------------------------------------
        $certs = implode('', $cert->allCertsDer());

        $signedData = Asn1::seq(
            Asn1::int(1)
            . Asn1::setOf(Asn1::algo(self::OID_SHA256))
            . Asn1::seq(Asn1::oid(self::OID_DATA))                // detached: no eContent
            . Asn1::tagged(0, $certs)
            . Asn1::setOf(Asn1::seq($signerInfo))
        );

        return Asn1::seq(Asn1::oid(self::OID_SIGNED_DATA) . Asn1::tagged(0, $signedData));
    }

    /**
     * IMPLICIT tagging swaps the tag byte and leaves the length and content
     * untouched — so a SET OF (0x31) becomes [0] (0xA0) by rewriting one byte.
     * Re-encoding it instead would change the bytes the signature covers.
     */
    private static function retag(string $der, int $tag): string
    {
        return chr($tag) . substr($der, 1);
    }

    private static function attribute(string $oid, string $value): string
    {
        return Asn1::seq(Asn1::oid($oid) . Asn1::setOf($value));
    }

    /**
     * SigningCertificateV2 ::= SEQUENCE { certs SEQUENCE OF ESSCertIDv2 }
     * ESSCertIDv2 ::= SEQUENCE { hashAlgorithm DEFAULT sha256, certHash,
     *                            issuerSerial OPTIONAL }
     *
     * hashAlgorithm is left out because sha256 is its DEFAULT and DER forbids
     * encoding a default value. issuerSerial pins the issuer and serial too.
     */
    private static function signingCertificateV2(Certificate $cert): string
    {
        $certHash = hash('sha256', $cert->certDer(), true);

        // GeneralNames { [4] EXPLICIT directoryName }
        $generalNames = Asn1::seq(Asn1::tagged(4, $cert->issuerDer()));
        $issuerSerial = Asn1::seq($generalNames . $cert->serialDer());

        $essCertIdV2  = Asn1::seq(Asn1::octet($certHash) . $issuerSerial);
        return Asn1::seq(Asn1::seq($essCertIdV2));
    }

    /**
     * RSA keys sign as rsaEncryption with NULL parameters (what Adobe expects);
     * an EC key — which an eIDAS smartcard-backed certificate may well be —
     * signs as ecdsa-with-SHA256, which takes no parameters at all.
     *
     * @param \OpenSSLAsymmetricKey $key
     */
    private static function signatureAlgorithm($key): string
    {
        $details = openssl_pkey_get_details($key) ?: [];
        if ((int)($details['type'] ?? OPENSSL_KEYTYPE_RSA) === OPENSSL_KEYTYPE_EC) {
            return Asn1::algo(self::OID_ECDSA_SHA256);
        }
        return Asn1::algo(self::OID_RSA, true);
    }
}
