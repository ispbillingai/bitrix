<?php
declare(strict_types=1);

namespace Glue\Pay;

use Glue\Config;
use RuntimeException;

/**
 * SmallPay 3.14 (Lynx) REST client — the payment side of the CRM.
 *
 * SmallPay's model: every sale becomes a "posizione debitoria" (debt position)
 * made of an optional one-off deposit plus a quota charged repeatedly to the
 * customer's card. We hand it a position and a paymentId we chose; it hands
 * back a cashier URL to send the customer, and from then on it does the
 * collecting. We only ever ask it how things are going.
 *
 *   Spec: 20240304_SMALLPAY_Specifiche_API_integrazione_LYNX_v3.14.pdf (repo root)
 *   Docs: docs/smallpay.md
 *   Support: smallpay@lynxspa.com
 *
 * Authentication is not a token. Every call carries idMerchant plus a hashPass:
 *
 *   sha1('paymentId=' . $paymentId . 'domain=' . $domain
 *        . 'serviceSmallpay=' . $service . 'uniqueId=' . $uniqueId)
 *
 * The shape is fixed but the CONTENT is per-endpoint: a field the call doesn't
 * use is present in the hash as an empty value, not omitted. Retrieving a
 * position hashes an empty serviceSmallpay; checking the configuration hashes
 * an empty paymentId. Getting that wrong is an Unauthorized / "Hash generation
 * error", so each method below states which fields it signs and signature()
 * takes them explicitly rather than defaulting to "whatever we have".
 *
 * uniqueId is the shared secret (Anagrafica page of the SmallPay Market portal).
 * It is never sent — only mixed into the hash — so it must never be logged
 * either; nothing here puts it in an exception message.
 */
final class SmallPay
{
    public const ENV_PRODUCTION = 'production';
    public const ENV_STAGING    = 'staging';

    private const BASE_URLS = [
        self::ENV_PRODUCTION => 'https://api-na.smallpay.it/market-api',
        self::ENV_STAGING    => 'https://api-staging.smallpay.it/market-api',
    ];

    private string $baseUrl;
    private int $idMerchant;
    private string $uniqueId;
    private string $service;
    private string $domain;
    private int $timeout;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? Config::section('smallpay');

        $env  = (string)($cfg['env'] ?? self::ENV_STAGING);
        $base = trim((string)($cfg['base_url'] ?? ''));
        $this->baseUrl = rtrim($base !== '' ? $base : (self::BASE_URLS[$env] ?? self::BASE_URLS[self::ENV_STAGING]), '/');

        $this->idMerchant = (int)($cfg['id_merchant'] ?? 0);
        $this->uniqueId   = trim((string)($cfg['unique_id'] ?? ''));
        $this->service    = trim((string)($cfg['service_id'] ?? ''));
        $this->domain     = trim((string)($cfg['domain'] ?? ''));
        $this->timeout    = max(5, (int)($cfg['timeout'] ?? 30));

        if ($this->idMerchant <= 0 || $this->uniqueId === '' || $this->domain === '') {
            throw new RuntimeException('SmallPay is not configured (Settings → SmallPay): id_merchant, unique_id and domain are all required');
        }
    }

    /**
     * Enough config present to attempt a call? Lets the scheduler and the
     * dashboard skip quietly instead of throwing on every tick.
     */
    public static function configured(): bool
    {
        return (int)Config::get('smallpay.id_merchant', 0) > 0
            && trim((string)Config::get('smallpay.unique_id', '')) !== ''
            && trim((string)Config::get('smallpay.domain', '')) !== ''
            && trim((string)Config::get('smallpay.service_id', '')) !== '';
    }

    /** Is the integration switched on AND configured? Nothing charges anyone unless both. */
    public static function enabled(): bool
    {
        return (bool)Config::get('smallpay.enabled', false) && self::configured();
    }

    public function domain(): string
    {
        return $this->domain;
    }

    /** True when pointed at SmallPay's staging engine — shown in the UI so nobody mistakes a test for a sale. */
    public function isStaging(): bool
    {
        return !str_contains($this->baseUrl, 'api-na.smallpay.it');
    }

    // ---- the signature ------------------------------------------------------

    /**
     * hashPass over the four fields, in SmallPay's fixed order. Pass '' for any
     * field this particular endpoint does not use — that is what the spec's
     * per-endpoint formulas mean, and it is not the same as leaving it out.
     */
    public function signature(string $paymentId, string $service): string
    {
        return sha1('paymentId=' . $paymentId
            . 'domain=' . $this->domain
            . 'serviceSmallpay=' . $service
            . 'uniqueId=' . $this->uniqueId);
    }

    /**
     * The hash SmallPay signs its ANSWERS with — same idea, but timestamp in
     * place of serviceSmallpay. Used to authenticate the status callback, where
     * anyone on the internet can POST us "this customer stopped paying".
     */
    public function responseSignature(string $paymentId, string $timestamp): string
    {
        return sha1('paymentId=' . $paymentId
            . 'domain=' . $this->domain
            . 'timestamp=' . $timestamp
            . 'uniqueId=' . $this->uniqueId);
    }

    /**
     * Constant-time check of a response/callback hashPass. Returns false rather
     * than throwing: an unverified callback is a thing to log and drop, not an
     * exception to bubble into a 500 that makes SmallPay retry forever.
     */
    public function verifyResponse(array $body): bool
    {
        $given = (string)($body['hashPass'] ?? '');
        if ($given === '') {
            return false;
        }
        $expected = $this->responseSignature(
            (string)($body['paymentId'] ?? ''),
            (string)($body['timestamp'] ?? '')
        );
        return hash_equals($expected, $given);
    }

    /** merchantInfo block, signed for one specific call. */
    private function merchantInfo(string $paymentId, string $service): array
    {
        return [
            'idMerchant' => $this->idMerchant,
            'hashPass'   => $this->signature($paymentId, $service),
        ];
    }

    // ---- the sale flow ------------------------------------------------------

    /**
     * §3.3 Controllo configurazione vendita — validates that the merchant, the
     * service and the gateway are set up, WITHOUT creating anything. This is the
     * only call that is safe to fire at a live merchant account just to see if
     * the credentials work, so it is what the Settings test button uses.
     *
     * Signs: paymentId='' , serviceSmallpay=<service>. Answers 204 on success.
     */
    public function checkSellConfig(): bool
    {
        $this->requireService();
        $this->post('public/api/sites/' . rawurlencode($this->domain) . '/checkSellConfigs', [
            'merchantInfo'    => $this->merchantInfo('', $this->service),
            'serviceSmallpay' => $this->service,
        ]);
        return true; // a non-2xx would have thrown
    }

    /**
     * §3.1 Generazione posizione debitoria — file the position and get back the
     * cashier URL to send the customer.
     *
     * Signs: paymentId=<paymentId>, serviceSmallpay=<service>.
     *
     * $opts:
     *   payer              ['firstName','lastName','phoneNumber','eMailAddress']
     *   totalAmount        cents, the whole sale
     *   firstPaymentAmount cents charged up front (0 for none)
     *   totalRecurrences   how many repeats; 0 with flexPay = open-ended
     *   description        what the customer is paying for
     *   redirectUrl        where the customer lands after the cashier page
     *   callbackUrl        where SmallPay POSTs status changes (§3.4)
     *   flexPay            true = perpetual recurrence (a support contract)
     *   modifyInstallments let SmallPay re-plan rates a card's expiry can't cover
     *
     * Returns SmallPay's body: paymentId, operationId, paymentUrl, timestamp,
     * hashPass. Note the spec marks payer fields optional in the table and then
     * lists "firstName cannot be blank" among the validation errors, so we send
     * them as given and let SmallPay be the judge.
     */
    public function createPosition(string $paymentId, array $opts): array
    {
        $this->requireService();
        $payer = (array)($opts['payer'] ?? []);

        $body = [
            'merchantInfo'      => $this->merchantInfo($paymentId, $this->service),
            'payer'             => [
                'firstName'    => (string)($payer['firstName'] ?? ''),
                'lastName'     => (string)($payer['lastName'] ?? ''),
                'phoneNumber'  => (string)($payer['phoneNumber'] ?? ''),
                'eMailAddress' => (string)($payer['eMailAddress'] ?? ''),
            ],
            'serviceSmallpay'   => $this->service,
            'totalRecurrences'  => max(0, (int)($opts['totalRecurrences'] ?? 0)),
            'totalAmount'       => max(0, (int)($opts['totalAmount'] ?? 0)),
            'description'       => (string)($opts['description'] ?? ''),
            'redirectUrl'       => (string)($opts['redirectUrl'] ?? ''),
            // Whether SmallPay may re-plan the schedule when the card expires
            // before the last rate. Off means such a sale is simply refused.
            'modifyInstallments' => (bool)($opts['modifyInstallments'] ?? true),
        ];
        if ((int)($opts['firstPaymentAmount'] ?? 0) > 0) {
            $body['firstPaymentAmount'] = (int)$opts['firstPaymentAmount'];
        }
        if (($cb = (string)($opts['callbackUrl'] ?? '')) !== '') {
            $body['statusUpdateCallbackUrl'] = $cb;
        }
        if (!empty($opts['flexPay'])) {
            $body['flagFlexpay'] = true;
        }
        if (($user = (string)($opts['user'] ?? '')) !== '') {
            $body['user'] = $user;
        }

        return $this->post('public/api/sites/' . rawurlencode($this->domain) . '/recurrences/' . rawurlencode($paymentId), $body);
    }

    /**
     * §3.2 Verifica stato posizione debitoria — the position's status plus every
     * rate and where each one got to. This is the pull that keeps the CRM honest
     * when a callback goes missing.
     *
     * Signs: paymentId=<paymentId>, serviceSmallpay='' (empty — see the class note).
     */
    public function retrievePosition(string $paymentId): array
    {
        return $this->post(
            'public/api/sites/' . rawurlencode($this->domain) . '/retrieveRecurrences/' . rawurlencode($paymentId),
            $this->merchantInfo($paymentId, '')
        );
    }

    /**
     * §3.5 Rigenerazione primo pagamento — when the first payment was refused,
     * email the customer a fresh cashier link. SmallPay sends the mail itself,
     * to the address given when the position was created.
     *
     * Signs: paymentId='' , serviceSmallpay=<service>.
     */
    public function regenerateFirstPayment(string $paymentId): array
    {
        $this->requireService();
        return $this->post('sites/' . rawurlencode($this->domain) . '/payments/regenerate', [
            'merchantInfo' => $this->merchantInfo('', $this->service),
            'paymentId'    => $paymentId,
        ]);
    }

    /**
     * §3.6 Rilancio posizioni debitorie — retry rates that came back INSOLUTO.
     * $installmentIds are SmallPay installmentIds from retrievePosition().
     * Answers totalInstallmentsSubmitted / installmentsProcessed / ...Unprocessed.
     */
    public function relaunchInstallments(array $installmentIds): array
    {
        return $this->installmentAction('relaunch', $installmentIds);
    }

    /**
     * §3.7 Pagamento per cassa — mark rates as settled at the desk so SmallPay
     * stops trying to take them off the card.
     */
    public function payInCash(array $installmentIds): array
    {
        return $this->installmentAction('paidInCash', $installmentIds);
    }

    /**
     * §3.8 Eliminazione posizioni debitorie — drop rates that were never taken.
     * SmallPay only allows this for rates still in DA PAGARE.
     */
    public function deleteInstallments(array $installmentIds): array
    {
        return $this->installmentAction('delete', $installmentIds);
    }

    /**
     * §3.9 Disattivazione FlexPay — stop an open-ended contract. This is how a
     * support contract is cancelled: the perpetual mandate ends and no further
     * month is charged. Rates already taken are not touched.
     *
     * Signs: paymentId='' , serviceSmallpay=<service>.
     */
    public function unsubscribeFlexPay(string $paymentId): array
    {
        $this->requireService();
        return $this->post(
            'sites/' . rawurlencode($this->domain) . '/perpetualUnSubscribe/' . rawurlencode($paymentId),
            ['merchantInfo' => $this->merchantInfo('', $this->service)]
        );
    }

    /** The three §3.6-3.8 calls differ only in their last path segment. */
    private function installmentAction(string $action, array $installmentIds): array
    {
        $this->requireService();
        $ids = array_values(array_filter(array_map('strval', $installmentIds), 'strlen'));
        if (!$ids) {
            throw new RuntimeException("SmallPay $action: no installments given");
        }
        return $this->post('sites/' . rawurlencode($this->domain) . '/installments/' . $action, [
            'merchantInfo' => $this->merchantInfo('', $this->service),
            'installments' => $ids,
        ]);
    }

    private function requireService(): void
    {
        if ($this->service === '') {
            throw new RuntimeException("SmallPay service_id is not set — copy 'Id Servizio crm' from Servizi in the SmallPay Market portal");
        }
    }

    // ---- transport ----------------------------------------------------------

    /**
     * One POST. Returns the decoded body ([] for SmallPay's empty 204s); throws
     * on transport failure, non-2xx, or a body that is neither JSON nor empty.
     *
     * The spec prints the sale-flow endpoints under `public/api/sites/...` and
     * the maintenance ones (§3.5-3.9) under a bare `sites/...`. That reads like
     * a documentation slip rather than two real prefixes, so a 404 on a bare
     * path is retried once with the `public/api/` prefix before giving up —
     * whichever way round it turns out to be, the call lands.
     */
    private function post(string $path, array $body): array
    {
        try {
            return $this->send($path, $body);
        } catch (SmallPayHttpError $e) {
            if ($e->status === 404 && !str_starts_with($path, 'public/api/')) {
                return $this->send('public/api/' . $path, $body);
            }
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function send(string $path, array $body): array
    {
        $url  = $this->baseUrl . '/' . ltrim($path, '/');
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("SmallPay transport error on $path: $err");
        }
        $raw = trim((string)$raw);

        // 204 on checkSellConfigs, and the §3.5-3.9 calls answer 200 with no body.
        if ($raw === '') {
            if ($http >= 200 && $http < 300) {
                return [];
            }
            throw new SmallPayHttpError("SmallPay HTTP $http on $path (empty body)", $http);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new SmallPayHttpError("SmallPay non-JSON response on $path (HTTP $http)", $http);
        }
        if ($http < 200 || $http >= 300) {
            throw new SmallPayHttpError('SmallPay ' . self::errorText($decoded, $http) . " on $path", $http);
        }
        return $decoded;
    }

    /**
     * SmallPay reports problems as {timestamp,status,error,message,errorsDescription[]}
     * where message is a concatenation and errorsDescription holds the useful
     * detail on a Validation Error. Flatten both into one readable line.
     */
    private static function errorText(array $json, int $http): string
    {
        $parts = array_filter([
            (string)($json['error'] ?? '') ?: "HTTP $http",
            (string)($json['message'] ?? ''),
        ], 'strlen');
        $detail = array_filter(array_map('strval', (array)($json['errorsDescription'] ?? [])), 'strlen');
        if ($detail) {
            $parts[] = '(' . implode('; ', $detail) . ')';
        }
        return implode(': ', $parts);
    }
}
