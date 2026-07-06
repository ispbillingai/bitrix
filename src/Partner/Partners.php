<?php
declare(strict_types=1);

namespace Glue\Partner;

use Glue\Db;
use Glue\Event\Log;
use Throwable;

/**
 * Partners (referrers). A partner refers customers via ?ref=CODE; a referred lead
 * that becomes a WON deal earns the partner a commission (a % of the deal value)
 * as a 'pending' accrual an admin later approves and marks paid. Partners are not
 * CRM users — separate table + separate login (partner.php). See migration 019.
 */
final class Partners
{
    // ---- accounts -------------------------------------------------------------

    /** @return array<int,array> all partners, newest first. */
    public static function all(): array
    {
        return Db::pdo()->query("SELECT * FROM partners ORDER BY id DESC")->fetchAll() ?: [];
    }

    public static function find(int $id): ?array
    {
        $s = Db::pdo()->prepare("SELECT * FROM partners WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function byRefCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $s = Db::pdo()->prepare("SELECT * FROM partners WHERE ref_code = ? AND active = 1");
        $s->execute([$code]);
        return $s->fetch() ?: null;
    }

    /**
     * Create a partner. Generates a unique ref_code if none given. Returns id.
     * @param array $d name|email|phone|commission_pct|ref_code|password
     */
    public static function create(array $d): int
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name required');
        }
        $ref = trim((string)($d['ref_code'] ?? '')) ?: self::genCode($name);
        // Ensure uniqueness.
        while (self::refExists($ref)) {
            $ref = self::genCode($name);
        }
        $hash = !empty($d['password']) ? password_hash((string)$d['password'], PASSWORD_BCRYPT) : null;

        $s = Db::pdo()->prepare(
            "INSERT INTO partners (name, email, phone, ref_code, commission_pct, password_hash, active)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $s->execute([
            $name,
            trim((string)($d['email'] ?? '')) ?: null,
            trim((string)($d['phone'] ?? '')) ?: null,
            $ref,
            self::clampPct($d['commission_pct'] ?? 10),
            $hash,
            !empty($d['active']) || !isset($d['active']) ? 1 : 0,
        ]);
        $id = (int)Db::pdo()->lastInsertId();
        Log::write('partner', 'partner_created', 'partner', $id, ['name' => $name, 'ref' => $ref]);
        return $id;
    }

    /** Update a partner. Blank password keeps the existing one. */
    public static function update(int $id, array $d): void
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            return;
        }
        $ref = trim((string)($d['ref_code'] ?? ''));
        if ($ref === '') {
            $cur = self::find($id);
            $ref = $cur['ref_code'] ?? self::genCode($name);
        }
        $active = !empty($d['active']) ? 1 : 0;
        $pct = self::clampPct($d['commission_pct'] ?? 10);

        if (!empty($d['password'])) {
            Db::pdo()->prepare(
                "UPDATE partners SET name=?, email=?, phone=?, ref_code=?, commission_pct=?, active=?, password_hash=? WHERE id=?"
            )->execute([$name, trim((string)($d['email'] ?? '')) ?: null, trim((string)($d['phone'] ?? '')) ?: null,
                $ref, $pct, $active, password_hash((string)$d['password'], PASSWORD_BCRYPT), $id]);
        } else {
            Db::pdo()->prepare(
                "UPDATE partners SET name=?, email=?, phone=?, ref_code=?, commission_pct=?, active=? WHERE id=?"
            )->execute([$name, trim((string)($d['email'] ?? '')) ?: null, trim((string)($d['phone'] ?? '')) ?: null,
                $ref, $pct, $active, $id]);
        }
        Log::write('partner', 'partner_updated', 'partner', $id, ['name' => $name]);
    }

    /** Email/phone + password login for the partner area. Returns the row or null. */
    public static function login(string $loginId, string $password): ?array
    {
        $loginId = trim($loginId);
        if ($loginId === '' || $password === '') {
            return null;
        }
        $s = Db::pdo()->prepare(
            "SELECT * FROM partners WHERE active = 1 AND password_hash IS NOT NULL
               AND (email = :e OR (phone <> '' AND phone = :p)) LIMIT 1"
        );
        $s->execute([':e' => $loginId, ':p' => $loginId]);
        $row = $s->fetch();
        if ($row && password_verify($password, (string)$row['password_hash'])) {
            unset($row['password_hash']);
            return $row;
        }
        return null;
    }

    // ---- referrals ------------------------------------------------------------

    /** Attribute a lead to a partner (called from the public form when ?ref= is set). */
    public static function attributeLead(int $leadId, int $partnerId): void
    {
        Db::pdo()->prepare("UPDATE leads SET referred_by_partner_id = ? WHERE id = ?")
            ->execute([$partnerId, $leadId]);
        Log::write('partner', 'lead_referred', 'lead', $leadId, ['partner_id' => $partnerId]);
    }

    /** A partner's referred leads with stage/status (for the partner area + admin). */
    public static function referrals(int $partnerId): array
    {
        $s = Db::pdo()->prepare(
            "SELECT l.id, l.customer_name, l.stage_code, l.status, l.received_at
               FROM leads l WHERE l.referred_by_partner_id = ? ORDER BY l.id DESC"
        );
        $s->execute([$partnerId]);
        return $s->fetchAll() ?: [];
    }

    // ---- accruals -------------------------------------------------------------

    /**
     * Create the commission accrual for a WON deal, if it came from a partner
     * referral and no accrual exists yet. Idempotent via UNIQUE(deal_id).
     * Called from Deals::moveStage() on the won transition.
     */
    public static function accrueForWonDeal(int $dealId): void
    {
        try {
            $pdo = Db::pdo();
            $deal = $pdo->prepare("SELECT id, lead_id, amount FROM deals WHERE id = ?");
            $deal->execute([$dealId]);
            $d = $deal->fetch();
            if (!$d || empty($d['lead_id'])) {
                return;
            }
            // Which partner referred the originating lead?
            $lead = $pdo->prepare("SELECT referred_by_partner_id FROM leads WHERE id = ?");
            $lead->execute([(int)$d['lead_id']]);
            $partnerId = (int)($lead->fetchColumn() ?: 0);
            if ($partnerId <= 0) {
                return;
            }
            $partner = self::find($partnerId);
            if (!$partner) {
                return;
            }
            $pct  = (float)$partner['commission_pct'];
            $base = (float)($d['amount'] ?? 0);
            $amount = round($base * $pct / 100, 2);

            // INSERT IGNORE so a re-won / double-fire never duplicates (UNIQUE deal_id).
            $pdo->prepare(
                "INSERT IGNORE INTO partner_accruals
                    (partner_id, lead_id, deal_id, base_amount, commission_pct, amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            )->execute([$partnerId, (int)$d['lead_id'], $dealId, $base, $pct, $amount]);

            Log::write('partner', 'accrual_created', 'deal', $dealId,
                ['partner_id' => $partnerId, 'amount' => $amount, 'pct' => $pct]);
        } catch (Throwable $e) {
            Log::write('partner', 'accrual_failed', 'deal', $dealId, ['error' => $e->getMessage()]);
        }
    }

    /** Accruals for a partner (optionally filtered by status). */
    public static function accruals(int $partnerId, ?string $status = null): array
    {
        $sql = "SELECT a.*, d.title AS deal_title, l.customer_name
                  FROM partner_accruals a
                  LEFT JOIN deals d ON d.id = a.deal_id
                  LEFT JOIN leads l ON l.id = a.lead_id
                 WHERE a.partner_id = ?";
        $args = [$partnerId];
        if ($status !== null) {
            $sql .= " AND a.status = ?";
            $args[] = $status;
        }
        $sql .= " ORDER BY a.id DESC";
        $s = Db::pdo()->prepare($sql);
        $s->execute($args);
        return $s->fetchAll() ?: [];
    }

    /** Totals per status for a partner: ['pending'=>x,'approved'=>y,'paid'=>z,'total'=>...]. */
    public static function totals(int $partnerId): array
    {
        $s = Db::pdo()->prepare(
            "SELECT status, COALESCE(SUM(amount),0) amt FROM partner_accruals
              WHERE partner_id = ? AND status <> 'cancelled' GROUP BY status"
        );
        $s->execute([$partnerId]);
        $out = ['pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0];
        foreach ($s->fetchAll() as $r) {
            $out[$r['status']] = (float)$r['amt'];
        }
        $out['total'] = $out['pending'] + $out['approved'] + $out['paid'];
        return $out;
    }

    /** Admin: move an accrual to a new state, stamping the time. */
    public static function setAccrualStatus(int $accrualId, string $status): void
    {
        if (!in_array($status, ['pending', 'approved', 'paid', 'cancelled'], true)) {
            return;
        }
        $stamp = $status === 'approved' ? ', approved_at = NOW()'
               : ($status === 'paid' ? ', paid_at = NOW()' : '');
        Db::pdo()->prepare("UPDATE partner_accruals SET status = ?$stamp WHERE id = ?")
            ->execute([$status, $accrualId]);
        Log::write('partner', 'accrual_' . $status, 'accrual', $accrualId, []);
    }

    // ---- helpers --------------------------------------------------------------

    private static function refExists(string $code): bool
    {
        $s = Db::pdo()->prepare("SELECT 1 FROM partners WHERE ref_code = ?");
        $s->execute([$code]);
        return (bool)$s->fetchColumn();
    }

    private static function genCode(string $name): string
    {
        $slug = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name) ?: 'PART', 0, 4));
        return $slug . strtoupper(bin2hex(random_bytes(2)));
    }

    private static function clampPct($v): float
    {
        $v = (float)$v;
        if ($v < 0) { $v = 0; }
        if ($v > 100) { $v = 100; }
        return round($v, 2);
    }
}
