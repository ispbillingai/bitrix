<?php
declare(strict_types=1);

namespace Glue\Portal;

use Glue\Db;
use Glue\Reminder\Scheduler;

/**
 * One-time codes for the portal — currently the electronic signature of a quote.
 * issue() generates a 6-digit code, stores it, and sends it to the customer over
 * email + WhatsApp; verify() checks it (single use, time-limited, attempt-capped).
 */
final class Otp
{
    private const TTL_MIN      = 10;
    private const MAX_ATTEMPTS = 5;

    /** Generate, persist and send a code. Returns true if at least one channel sent. */
    public static function issue(int $contactId, int $dealId, string $purpose = 'sign'): bool
    {
        $contact = Account::find($contactId);
        if (!$contact) {
            return false;
        }
        // Invalidate any earlier unused codes for the same purpose/deal.
        Db::pdo()->prepare(
            'UPDATE otp_codes SET used_at = NOW()
             WHERE contact_id = ? AND deal_id <=> ? AND purpose = ? AND used_at IS NULL'
        )->execute([$contactId, $dealId ?: null, $purpose]);

        $code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + self::TTL_MIN * 60);
        Db::pdo()->prepare(
            'INSERT INTO otp_codes (contact_id, deal_id, purpose, code, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$contactId, $dealId ?: null, $purpose, $code, $expires]);

        // Queued, not sent inline: a hung SMTP must never block the signing page.
        // The cron delivers within a minute; the code stays valid for TTL_MIN.
        (new Scheduler())->enqueue([
            'entity_type'    => 'contact',
            'entity_id'      => $contactId,
            'rule_key'       => 'sign_otp',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'payload'        => ['code' => $code, 'minutes' => (string)self::TTL_MIN],
            'lang'           => $contact['lang'] ?? null,
            'dedupe_key'     => 'sign_otp:' . $contactId . ':' . $dealId . ':' . $code,
        ]);
        return true;
    }

    /**
     * Check a code. Returns 'ok', 'expired', 'locked', or 'invalid'. On success the
     * code is consumed (used_at set) so it can't be replayed.
     */
    public static function verify(int $contactId, int $dealId, string $code, string $purpose = 'sign'): string
    {
        $code = preg_replace('/\D+/', '', trim($code)) ?? '';
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM otp_codes
             WHERE contact_id = ? AND deal_id <=> ? AND purpose = ? AND used_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$contactId, $dealId ?: null, $purpose]);
        $row = $stmt->fetch();
        if (!$row) {
            return 'invalid';
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            return 'expired';
        }
        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            return 'locked';
        }
        if (!hash_equals((string)$row['code'], $code)) {
            Db::pdo()->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
            return 'invalid';
        }
        Db::pdo()->prepare('UPDATE otp_codes SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        return 'ok';
    }
}
