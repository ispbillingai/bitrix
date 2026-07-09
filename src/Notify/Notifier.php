<?php
declare(strict_types=1);

namespace Glue\Notify;

use Glue\Config;
use Glue\Db;

/**
 * Single send point for the whole app. Wraps TextMeBot + Mailer and logs every
 * attempt into the messages outbox so delivery is auditable. Phone numbers are
 * normalised to E.164-ish form before WhatsApp.
 */
final class Notifier
{
    private TextMeBot $wa;
    private Mailer $mail;

    public function __construct()
    {
        $this->wa = new TextMeBot();
        $this->mail = new Mailer();
    }

    public function whatsapp(string $phone, string $text, ?int $reminderId = null, ?int $campaignId = null, ?string $documentUrl = null): bool
    {
        return (bool)$this->whatsappResult($phone, $text, $reminderId, $campaignId, $documentUrl)['ok'];
    }

    public function email(string $to, string $subject, string $html, ?int $reminderId = null, ?int $campaignId = null): bool
    {
        return (bool)$this->emailResult($to, $subject, $html, $reminderId, $campaignId)['ok'];
    }

    /**
     * Same as whatsapp() but returns the full provider response — ['ok'=>bool,
     * 'error'=>?string, 'http'=>int, 'body'=>..]. Use this when the caller wants
     * to show the user *why* a send failed (e.g. the Settings test buttons).
     */
    public function whatsappResult(string $phone, string $text, ?int $reminderId = null, ?int $campaignId = null, ?string $documentUrl = null): array
    {
        $phone = self::normalizePhone($phone);
        if ($phone === '' || !$this->wa->enabled()) {
            $why = $phone === '' ? 'no_phone' : 'textmebot_disabled';
            $res = ['ok' => false, 'error' => $why, 'skipped' => $why];
            $this->record('whatsapp', $phone, null, $text, false, $res, $reminderId, $campaignId);
            return $res;
        }
        $res = $this->wa->sendWhatsapp($phone, $text, $documentUrl);
        $this->record('whatsapp', $phone, null, $text, (bool)$res['ok'], $res, $reminderId, $campaignId);
        return $res;
    }

    /** Same as email() but returns the full provider response — ['ok'=>bool, 'error'=>?string]. */
    public function emailResult(string $to, string $subject, string $html, ?int $reminderId = null, ?int $campaignId = null): array
    {
        if (trim($to) === '') {
            $res = ['ok' => false, 'error' => 'no_email', 'skipped' => 'no_email'];
            $this->record('email', $to, $subject, $html, false, $res, $reminderId, $campaignId);
            return $res;
        }
        $res = $this->mail->send($to, $subject, $html);
        $this->record('email', $to, $subject, $html, (bool)$res['ok'], $res, $reminderId, $campaignId);
        return $res;
    }

    private function record(
        string $channel, string $recipient, ?string $subject, ?string $body,
        bool $ok, array $providerResponse, ?int $reminderId, ?int $campaignId
    ): void {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO messages (reminder_id, campaign_id, channel, recipient, subject, body, status, provider_response)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $reminderId, $campaignId, $channel, $recipient, $subject, $body,
            $ok ? 'sent' : 'failed',
            json_encode($providerResponse, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Best-effort E.164. Strips spaces/dashes/parens and resolves the country code:
     *   +393391234567 / 00393391234567  -> +393391234567  (already international)
     *   3391234567                       -> +393391234567  (local: prepend default)
     *
     * The default dial code comes from app.default_country_code (digits only, e.g.
     * "39" for Italy), so a customer can enter a plain local number and WhatsApp
     * still gets a valid international number. A single leading trunk "0" on a
     * local number is dropped (Italian mobiles have none; landlines keep it via
     * the explicit-international forms above).
     */
    public static function normalizePhone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // Already international: leading + or 00 prefix.
        if (str_starts_with($raw, '+')) {
            $digits = preg_replace('/\D+/', '', $raw) ?? '';
            return $digits === '' ? '' : '+' . $digits;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }

        // Local number (no + or 00): prepend the configured default country code.
        // We do NOT try to detect an already-present country code here — for Italy
        // that's ambiguous (a mobile like 339... starts with the "39" dial code),
        // so callers wanting an explicit foreign number must use + or 00. A single
        // leading trunk "0" is dropped before the national number.
        $cc = preg_replace('/\D+/', '', (string)Config::get('app.default_country_code', '39')) ?? '';
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '';
        }
        return '+' . $cc . $digits;
    }
}
