<?php
declare(strict_types=1);

namespace Glue\Notify;

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

    public function whatsapp(string $phone, string $text, ?int $reminderId = null, ?int $campaignId = null): bool
    {
        $phone = self::normalizePhone($phone);
        if ($phone === '' || !$this->wa->enabled()) {
            $this->record('whatsapp', $phone, null, $text, false,
                ['skipped' => $phone === '' ? 'no_phone' : 'textmebot_disabled'], $reminderId, $campaignId);
            return false;
        }
        $res = $this->wa->sendWhatsapp($phone, $text);
        $this->record('whatsapp', $phone, null, $text, (bool)$res['ok'], $res, $reminderId, $campaignId);
        return (bool)$res['ok'];
    }

    public function email(string $to, string $subject, string $html, ?int $reminderId = null, ?int $campaignId = null): bool
    {
        if (trim($to) === '') {
            $this->record('email', $to, $subject, $html, false, ['skipped' => 'no_email'], $reminderId, $campaignId);
            return false;
        }
        $res = $this->mail->send($to, $subject, $html);
        $this->record('email', $to, $subject, $html, (bool)$res['ok'], $res, $reminderId, $campaignId);
        return (bool)$res['ok'];
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

    /** Best-effort E.164. Keeps a leading +, strips spaces/dashes/parens. */
    public static function normalizePhone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $plus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        return ($plus ? '+' : '') . $digits;
    }
}
