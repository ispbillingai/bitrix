<?php
declare(strict_types=1);

namespace Glue\Portal;

use Glue\Config;
use Glue\Db;
use Glue\Notify\Notifier;
use Glue\Reminder\Scheduler;

/**
 * Customer portal accounts. A customer IS a contact (contacts table); these
 * helpers add login on top: a magic-link token created by the agent, an optional
 * password the customer sets to make access permanent, and the welcome message.
 *
 * The agent triggers invite(); the customer opens the link (findByToken), then
 * optionally sets a password (setPassword) for later email/phone + password login.
 */
final class Account
{
    /**
     * Enable portal access for a contact and (re)issue a magic-link token.
     * @return string the raw token (used to build the link)
     */
    public static function invite(int $contactId, int $ttlDays = 14): string
    {
        $token   = bin2hex(random_bytes(16));
        $expires = date('Y-m-d H:i:s', time() + $ttlDays * 86400);
        Db::pdo()->prepare(
            'UPDATE contacts SET portal_enabled = 1, portal_token = ?, portal_token_expires = ? WHERE id = ?'
        )->execute([$token, $expires, $contactId]);
        return $token;
    }

    /** Public URL the customer opens to enter the portal. */
    public static function magicLink(string $token): string
    {
        $base = rtrim((string)Config::get('app.base_url', ''), '/');
        return "$base/portal.php?token=" . urlencode($token);
    }

    /**
     * Send the portal invitation (magic link) to the customer, email + WhatsApp.
     * due_at is now, so enqueue() delivers it inline (no cron wait); the send is
     * best-effort and recorded in the outbox.
     */
    public static function sendInvite(int $contactId, string $token): void
    {
        $c = self::find($contactId);
        if (!$c) {
            return;
        }
        (new Scheduler())->enqueue([
            'entity_type'    => 'contact',
            'entity_id'      => $contactId,
            'rule_key'       => 'portal_invite',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'payload'        => ['link' => self::magicLink($token)],
            'lang'           => $c['lang'] ?? null,
            'dedupe_key'     => 'portal_invite:contact:' . $contactId . ':' . substr($token, 0, 12),
        ]);
    }

    /** A valid, non-expired magic-link token resolves to its contact. */
    public static function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM contacts
             WHERE portal_token = ? AND portal_enabled = 1
               AND (portal_token_expires IS NULL OR portal_token_expires >= NOW())
             LIMIT 1'
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /** Email/phone + password login (only once the customer has set a password). */
    public static function login(string $loginId, string $password): ?array
    {
        $loginId = trim($loginId);
        if ($loginId === '' || $password === '') {
            return null;
        }
        $phone = Notifier::normalizePhone($loginId);
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM contacts
             WHERE portal_enabled = 1 AND password_hash IS NOT NULL
               AND (email = :email OR (phone <> "" AND phone = :phone))
             LIMIT 1'
        );
        $stmt->execute([':email' => $loginId, ':phone' => $phone]);
        $c = $stmt->fetch();
        if ($c && password_verify($password, (string)$c['password_hash'])) {
            return $c;
        }
        return null;
    }

    public static function setPassword(int $contactId, string $password): bool
    {
        if (strlen($password) < 6) {
            return false;
        }
        Db::pdo()->prepare('UPDATE contacts SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $contactId]);
        return true;
    }

    public static function touchLogin(int $contactId): void
    {
        Db::pdo()->prepare('UPDATE contacts SET last_login_at = NOW() WHERE id = ?')->execute([$contactId]);
    }

    public static function find(int $contactId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM contacts WHERE id = ?');
        $stmt->execute([$contactId]);
        return $stmt->fetch() ?: null;
    }

    private static function company(): string
    {
        return (string)Config::get('mail.from_name', '') ?: (string)Config::get('app.company_name', 'our company');
    }
}
