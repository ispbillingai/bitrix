<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Notify\Notifier;
use Glue\Reminder\Templates;
use Throwable;

/**
 * 90-day VAT-number (partita IVA) exclusivity. The first agent/partner to enter
 * a lead with a VAT number "owns" it for crm.vat_lock_days days; a second entry
 * of the same VAT by someone else is blocked and both an on-screen message and
 * an automated WhatsApp/email tell the enterer when it becomes available again.
 * Messages use the editable 'vat_thanks' / 'vat_taken' templates.
 */
final class VatLock
{
    /** Uppercase alphanumeric only, so "IT 01234567890" == "it01234567890". */
    public static function normalize(string $vat): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $vat) ?? '');
    }

    public static function days(): int
    {
        return max(1, (int)Config::get('crm.vat_lock_days', 90));
    }

    /** The unexpired claim row for a VAT (with owner label), or null. */
    public static function activeClaim(string $vat): ?array
    {
        $vat = self::normalize($vat);
        if ($vat === '') {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM vat_claims WHERE vat_number = ? AND expires_at > NOW()');
        $stmt->execute([$vat]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Try to claim a VAT for an enterer. Returns:
     *   ['ok' => true,  'fresh' => bool]                       claim held by this enterer
     *   ['ok' => false, 'available_at' => 'Y-m-d H:i:s', ...]  locked by someone else
     * A 'fresh' claim means it was newly taken now (send the thank-you); an
     * existing own claim (same enterer re-entering) is ok but not fresh.
     */
    public static function claim(string $vat, string $ownerKind, int $ownerId, ?int $leadId = null): array
    {
        $vat = self::normalize($vat);
        if ($vat === '') {
            return ['ok' => true, 'fresh' => false];
        }
        $cur = self::activeClaim($vat);
        if ($cur !== null) {
            if ($cur['owner_kind'] === $ownerKind && (int)$cur['owner_id'] === $ownerId) {
                return ['ok' => true, 'fresh' => false];
            }
            return ['ok' => false, 'available_at' => $cur['expires_at'],
                'owner_kind' => $cur['owner_kind'], 'owner_id' => (int)$cur['owner_id'],
                'lead_id' => $cur['lead_id'] !== null ? (int)$cur['lead_id'] : null];
        }
        $expires = date('Y-m-d H:i:s', time() + self::days() * 86400);
        Db::pdo()->prepare(
            'INSERT INTO vat_claims (vat_number, owner_kind, owner_id, lead_id, claimed_at, expires_at)
             VALUES (?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE owner_kind = VALUES(owner_kind), owner_id = VALUES(owner_id),
                 lead_id = VALUES(lead_id), claimed_at = NOW(), expires_at = VALUES(expires_at)'
        )->execute([$vat, $ownerKind, $ownerId, $leadId, $expires]);
        return ['ok' => true, 'fresh' => true, 'available_at' => $expires];
    }

    /** Point an existing claim at the lead that was created for it. */
    public static function attachLead(string $vat, int $leadId): void
    {
        Db::pdo()->prepare('UPDATE vat_claims SET lead_id = ? WHERE vat_number = ?')
            ->execute([$leadId, self::normalize($vat)]);
    }

    /** Free the claim held by a lead (used when a test lead is deleted). */
    public static function releaseForLead(int $leadId): void
    {
        Db::pdo()->prepare('DELETE FROM vat_claims WHERE lead_id = ?')->execute([$leadId]);
    }

    /**
     * "Thank you for entering a new lead…" to the enterer.
     *
     * Returns FALSE when it could not be delivered — which in practice means the
     * account holding the claim has neither a phone nor an email on its profile.
     * That case used to pass silently, and it is exactly how a lead entered under
     * a shared office login reserved a customer for 90 days without anyone ever
     * being told. The caller says so on screen now.
     */
    public static function notifyThanks(string $ownerKind, int $ownerId, string $vat, string $customerName): bool
    {
        return self::notify('vat_thanks', $ownerKind, $ownerId, [
            'vat' => self::normalize($vat), 'customer_name' => $customerName,
            'lock_days' => (string)self::days(),
            'until' => date('d/m/Y', time() + self::days() * 86400),
        ]);
    }

    /** "VAT already entered by another associate…" to the blocked enterer. */
    public static function notifyTaken(string $ownerKind, int $ownerId, string $vat, string $availableAt): bool
    {
        return self::notify('vat_taken', $ownerKind, $ownerId, [
            'vat' => self::normalize($vat), 'lock_days' => (string)self::days(),
            'available_date' => date('d/m/Y', strtotime($availableAt) ?: time()),
        ]);
    }

    /**
     * The name behind a claim, for saying who was (or was not) reachable.
     * Falls back to "agent #7" / "partner #3" when the row is gone.
     */
    public static function ownerLabel(string $ownerKind, int $ownerId): string
    {
        $table   = $ownerKind === 'partner' ? 'partners' : 'users';
        $nameCol = $ownerKind === 'partner' ? 'name' : "COALESCE(NULLIF(full_name,''), username)";
        $stmt = Db::pdo()->prepare("SELECT $nameCol FROM $table WHERE id = ?");
        $stmt->execute([$ownerId]);
        return (string)($stmt->fetchColumn() ?: ($ownerKind . ' #' . $ownerId));
    }

    /**
     * Send an editable template to an agent (users) or partner (partners) on both
     * channels. TRUE when at least one channel accepted it; FALSE when the owner
     * is unknown, has no phone and no email, or both sends failed — the caller
     * needs to be able to tell the difference between "sent" and "nowhere to go".
     */
    private static function notify(string $ruleKey, string $ownerKind, int $ownerId, array $vars): bool
    {
        try {
            $table = $ownerKind === 'partner' ? 'partners' : 'users';
            $nameCol = $ownerKind === 'partner' ? 'name' : "COALESCE(NULLIF(full_name,''), username)";
            $stmt = Db::pdo()->prepare("SELECT $nameCol AS name, phone, email FROM $table WHERE id = ?");
            $stmt->execute([$ownerId]);
            $who = $stmt->fetch();
            if (!$who) {
                self::logUndelivered($ruleKey, $ownerKind, $ownerId, 'unknown_owner');
                return false;
            }
            if (trim((string)$who['phone']) === '' && trim((string)$who['email']) === '') {
                self::logUndelivered($ruleKey, $ownerKind, $ownerId, 'no_channel');
                return false;
            }
            $vars += [
                'enterer_name' => (string)$who['name'],
                'name'         => (string)$who['name'],
                'company'      => (string)Config::get('app.company_name', (string)Config::get('mail.from_name', 'our company')),
            ];
            $lang = Templates::lang(Config::get('app.default_lang', 'it'));
            $notifier = new Notifier();
            $sent = false;
            if (trim((string)$who['phone']) !== '') {
                $sent = $notifier->whatsapp((string)$who['phone'], Templates::whatsapp($ruleKey, $vars, $lang)) || $sent;
            }
            if (trim((string)$who['email']) !== '') {
                $mail = Templates::email($ruleKey, $vars, $lang);
                $sent = $notifier->email((string)$who['email'], $mail['subject'], $mail['html']) || $sent;
            }
            if (!$sent) {
                self::logUndelivered($ruleKey, $ownerKind, $ownerId, 'send_failed');
            }
            return $sent;
        } catch (Throwable) {
            // notification failure must never block lead entry
            return false;
        }
    }

    /** Why a VAT notice never left, so the answer is in the log next time. */
    private static function logUndelivered(string $ruleKey, string $ownerKind, int $ownerId, string $why): void
    {
        \Glue\Event\Log::write('crm', 'vat_notice_undelivered', null, null,
            ['rule' => $ruleKey, 'owner_kind' => $ownerKind, 'owner_id' => $ownerId, 'reason' => $why]);
    }
}
