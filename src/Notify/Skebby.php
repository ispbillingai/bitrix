<?php
declare(strict_types=1);

namespace Glue\Notify;

use Glue\Config;
use Glue\Settings;
use Throwable;

/**
 * SMS through Skebby (messenger.skebby.it), migration 076.
 *
 * The client asked for it with the scope attached: "per adesso lo voglio usare
 * solo per la verifica dei documenti in maniera esclusiva, poi attraverso una
 * spunta nelle impostazioni potrò scegliere anche per le campagne pubblicitarie
 * o altro." So every send asks uses() whether SMS is switched on for THAT use,
 * and only the signing code is on today. Opening another one is a tick in
 * Impostazioni and a line in self::USES, not a change to the senders.
 *
 * Why it matters for the signing code in particular: an SMS does not depend on
 * the WhatsApp number, which this client has had disconnected twice in a week,
 * and it carries no pacing gap — the code arrives at once instead of queueing
 * behind a minute of WhatsApp spacing ([[textmebot-rate-limit]]).
 *
 * API: Basic auth once to /token, which returns "USER_KEY;ACCESS_TOKEN" that
 * does not expire; both are then sent as headers. Credentials live in settings,
 * never in the code.
 */
final class Skebby
{
    private const BASE = 'https://api.skebby.it/API/v1.0/REST';

    /** Where the API lives. Overridable so the flow can be exercised off-line. */
    private static function base(): string
    {
        return rtrim((string)Config::get('skebby.base_url', '') ?: self::BASE, '/');
    }

    /**
     * Skebby's three qualities. GP is the one that can carry a sender name, and
     * the one a verification code should go on: it is the delivery the operator
     * guarantees. TI and SI are cheaper and, for SI, the sender is a number.
     */
    public const QUALITIES = ['GP' => 'high', 'TI' => 'medium', 'SI' => 'low'];

    /**
     * What SMS can be switched on for, and the setting that does it. The signing
     * code is the one the client wanted first; the others are the "o altro" and
     * are off until somebody ticks them.
     */
    public const USES = [
        'doc_otp'   => 'skebby.use_doc_otp',     // the code that verifies a document
        'campaigns' => 'skebby.use_campaigns',   // bulk marketing
        'notices'   => 'skebby.use_notices',     // the rest of the reminders
    ];

    /** The rules that belong to "verifica dei documenti". */
    private const DOC_OTP_RULES = ['doc_sign_otp'];

    /** Configured at all: without these three there is nothing to send with. */
    public static function enabled(): bool
    {
        return trim((string)Config::get('skebby.username', '')) !== ''
            && trim((string)Config::get('skebby.password', '')) !== ''
            && (bool)Config::get('skebby.enabled', false);
    }

    /** Is SMS switched on for this use? */
    public static function uses(string $use): bool
    {
        $key = self::USES[$use] ?? '';
        return $key !== '' && self::enabled() && (bool)Config::get($key, false);
    }

    /**
     * Does this notification go by SMS instead of WhatsApp?
     *
     * Asked by the dispatcher for every queued message, so the switch in
     * Impostazioni is what decides, message by message, with no rule hard-wired
     * anywhere else.
     */
    public static function handles(string $ruleKey): bool
    {
        if (in_array($ruleKey, self::DOC_OTP_RULES, true)) {
            return self::uses('doc_otp');
        }
        return self::uses('notices');
    }

    /** The name the SMS arrives from, as registered with Skebby. */
    public static function sender(): string
    {
        return trim((string)Config::get('skebby.sender', ''));
    }

    public static function quality(): string
    {
        $q = strtoupper(trim((string)Config::get('skebby.quality', 'GP')));
        return isset(self::QUALITIES[$q]) ? $q : 'GP';
    }

    /**
     * Send one SMS. Returns ['ok'=>bool, 'error'=>?string, 'credits'=>?int].
     *
     * Never throws: a gateway that is down must fail the message, not the page
     * or the cron tick that was carrying it.
     */
    public function send(string $phoneE164, string $text): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'error' => 'disabled', 'credits' => null];
        }
        $to = Notifier::normalizePhone($phoneE164);
        if ($to === '' || !preg_match('/^\+?\d{6,}$/', $to)) {
            return ['ok' => false, 'error' => 'bad_number', 'credits' => null];
        }
        $auth = $this->auth();
        if ($auth === null) {
            return ['ok' => false, 'error' => 'auth_failed', 'credits' => null];
        }

        $body = [
            'message_type' => self::quality(),
            'message'      => $text,
            'recipient'    => [$to],
            'returnCredits' => true,
        ];
        if (self::sender() !== '') {
            $body['sender'] = self::sender();
        }
        $res = $this->call('POST', '/sms', $auth, $body);

        // A token that stopped working (the account was re-issued one): forget it
        // and try once more, so a stale cache never looks like an outage.
        if (($res['http'] ?? 0) === 401) {
            $this->forgetAuth();
            $auth = $this->auth();
            if ($auth === null) {
                return ['ok' => false, 'error' => 'auth_failed', 'credits' => null];
            }
            $res = $this->call('POST', '/sms', $auth, $body);
        }

        $json = json_decode((string)($res['body'] ?? ''), true);
        $ok   = (int)($res['http'] ?? 0) === 201 && strtoupper((string)($json['result'] ?? '')) === 'OK';
        return [
            'ok'      => $ok,
            'error'   => $ok ? null : trim((string)($json['result'] ?? '') ?: ('http_' . (int)($res['http'] ?? 0))),
            'credits' => isset($json['remaining_credits']) ? (int)$json['remaining_credits'] : null,
        ];
    }

    /**
     * What is left in the account: ['ok'=>bool, 'sms'=>?int, 'money'=>?float].
     * The Impostazioni page shows it, so nobody discovers an empty account from
     * a customer who never got their code.
     */
    public function credit(): array
    {
        $auth = $this->auth();
        if ($auth === null) {
            return ['ok' => false, 'sms' => null, 'money' => null, 'error' => 'auth_failed'];
        }
        $res  = $this->call('GET', '/status?getMoney=true', $auth);
        $json = json_decode((string)($res['body'] ?? ''), true);
        if ((int)($res['http'] ?? 0) !== 200 || !is_array($json)) {
            return ['ok' => false, 'sms' => null, 'money' => null,
                    'error' => 'http_' . (int)($res['http'] ?? 0)];
        }
        return [
            'ok'    => true,
            'sms'   => isset($json['sms']) ? (int)$json['sms'] : null,
            'money' => isset($json['money']) ? (float)$json['money'] : null,
            'error' => null,
        ];
    }

    // ---- credentials ----------------------------------------------------------------

    /**
     * The user key and access token, fetched once and kept. Skebby's token does
     * not expire, so this is a login per account rather than per message.
     *
     * @return array{user_key:string, token:string}|null
     */
    private function auth(): ?array
    {
        $key   = trim((string)Settings::get('skebby.user_key', ''));
        $token = trim((string)Settings::get('skebby.token', ''));
        if ($key !== '' && $token !== '') {
            return ['user_key' => $key, 'token' => $token];
        }

        $user = trim((string)Config::get('skebby.username', ''));
        $pass = (string)Config::get('skebby.password', '');
        if ($user === '' || $pass === '') {
            return null;
        }
        $ch = curl_init(self::base() . '/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => $user . ':' . $pass,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        ]);
        $body = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http !== 200 || !str_contains($body, ';')) {
            return null;
        }
        [$key, $token] = array_map('trim', explode(';', trim($body), 2));
        if ($key === '' || $token === '') {
            return null;
        }
        try {
            Settings::set('skebby.user_key', $key);
            Settings::set('skebby.token', $token);
        } catch (Throwable) {
            // not cacheable this time — the next send logs in again
        }
        return ['user_key' => $key, 'token' => $token];
    }

    /** Drop the cached token, so the next call logs in again. */
    public function forgetAuth(): void
    {
        try {
            Settings::set('skebby.user_key', '');
            Settings::set('skebby.token', '');
        } catch (Throwable) {
            // nothing to forget
        }
    }

    /** @return array{http:int, body:string} */
    private function call(string $method, string $path, array $auth, ?array $json = null): array
    {
        $ch = curl_init(self::base() . $path);
        $headers = [
            'user_key: ' . $auth['user_key'],
            'Access_token: ' . $auth['token'],
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['http' => $http, 'body' => $body === false ? '' : (string)$body];
    }
}
