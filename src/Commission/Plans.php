<?php
declare(strict_types=1);

namespace Glue\Commission;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Throwable;

/**
 * Commissions paid in instalments, as the customer pays (migration 061).
 *
 * "If a customer pays in three instalments and the agent is due a 10%
 *  commission, we pay out that 10% in three corresponding instalments after
 *  receiving payment from the customer."
 *
 * A PLAN is one commission on one sale: who earns it (a partner or an agent),
 * the total, and the customer's instalments. Each instalment carries the
 * payee's share of it, proportional to its amount — so 10% of a sale paid
 * 5.395,76 + 5 × 5.340,00 pays 10% of each, and the shares add up to the total
 * to the cent (the last one takes the rounding).
 *
 * An instalment is EARNED when the customer pays it: the office says so, or —
 * for a plan that follows a Sibill invoice — the sync sees the matching flow
 * PAID. Earning files the share as an ordinary commission statement
 * (Statements::fileFromPlan), so from there it is the flow the office already
 * uses: the payee is told, sends an invoice (or not), the office pays.
 *
 * Nothing is paid out ahead of the customer: a waiting instalment is only a
 * forecast, and cancelling a plan cancels only what has not been earned.
 */
final class Plans
{
    public const STATUSES = ['active', 'completed', 'cancelled'];

    // ---- the office files a plan ---------------------------------------------------

    /**
     * @param array $d payee ("partner:3"), title, customer_name, sibill_invoice_id,
     *                 rate_amount[] + rate_due[] (when no invoice), mode (amount|pct),
     *                 commission_total, commission_pct, pct_base (net|gross), vat_rate,
     *                 no_invoice, notes, source_statement_id
     * @param array|null $file the calculation (optional)
     * @return array{ok:bool, id:int, error:?string, earned?:int}
     */
    public static function create(array $d, ?array $file, ?int $userId): array
    {
        $fail = fn(string $e): array => ['ok' => false, 'id' => 0, 'error' => $e];

        // Splitting an existing statement: its payee, calculation and invoice
        // rule carry over, whatever the form said.
        $source = null;
        if ((int)($d['source_statement_id'] ?? 0) > 0) {
            $source = Statements::find((int)$d['source_statement_id']);
            if (!$source || !self::splittable($source)) {
                return $fail('not_splittable');
            }
            $d['payee'] = $source['payee_type'] . ':' . $source['payee_id'];
        }

        [$type, $pid] = array_pad(explode(':', (string)($d['payee'] ?? ''), 2), 2, '0');
        $payee = in_array($type, Statements::PAYEE_TYPES, true) ? Statements::payee($type, (int)$pid) : null;
        if (!$payee || ((int)$payee['active'] !== 1 && !$source)) {
            return $fail('no_payee');
        }

        // ---- the customer's instalments ----
        $invoice = null;
        $rates   = [];
        $invId   = (int)($d['sibill_invoice_id'] ?? 0);
        if ($invId > 0) {
            $invoice = self::sibillInvoice($invId);
            if (!$invoice || !$invoice['flows']) {
                return $fail('invoice');
            }
            // Amounts and dates from the mirror, never from the form.
            foreach ($invoice['flows'] as $f) {
                $rates[] = ['amount' => round((float)$f['amount'], 2), 'due' => $f['due_date'] ?: null, 'flow' => (string)$f['sibill_id']];
            }
        } else {
            $amounts = (array)($d['rate_amount'] ?? []);
            $dues    = (array)($d['rate_due'] ?? []);
            foreach ($amounts as $i => $raw) {
                $raw = trim((string)$raw);
                if ($raw === '') {
                    continue;
                }
                $a = Statements::parseAmount($raw);
                if ($a <= 0) {
                    return $fail('rates');
                }
                $rates[] = ['amount' => $a, 'due' => self::date((string)($dues[$i] ?? '')), 'flow' => null];
            }
        }
        if (!$rates || count($rates) > 120) {
            return $fail('rates');
        }
        $sale = round(array_sum(array_column($rates, 'amount')), 2);
        if ($sale <= 0) {
            return $fail('rates');
        }

        // ---- the commission ----
        $pct = null; $base = null; $vat = null;
        if (($d['mode'] ?? 'amount') === 'pct') {
            $pct = self::parsePct((string)($d['commission_pct'] ?? ''));
            if ($pct <= 0 || $pct > 100) {
                return $fail('pct');
            }
            $base = ($d['pct_base'] ?? 'net') === 'gross' ? 'gross' : 'net';
            $vatRaw = trim((string)($d['vat_rate'] ?? ''));
            $vat  = $base === 'net' ? ($vatRaw === '' ? 22.0 : self::parsePct($vatRaw)) : null;
            if ($vat !== null && ($vat < 0 || $vat > 100)) {
                return $fail('pct');
            }
            $total = round(($base === 'net' ? $sale / (1 + $vat / 100) : $sale) * $pct / 100, 2);
        } else {
            $total = Statements::parseAmount((string)($d['commission_total'] ?? ''));
        }
        if ($total <= 0) {
            return $fail('amount');
        }
        if ($total > $sale) {
            return $fail('too_much');
        }
        $shares = self::shares(array_column($rates, 'amount'), $total);

        $customer = mb_substr(trim((string)($d['customer_name'] ?? '')), 0, 190)
            ?: (string)($invoice['counterpart_name'] ?? '');
        // Typed, or the split statement's own, or "Provvigioni <customer>".
        $title = mb_substr(trim((string)($d['title'] ?? '')), 0, 190)
            ?: mb_substr(trim((string)($source['title'] ?? '')), 0, 190)
            ?: mb_substr('Provvigioni ' . $customer, 0, 190);
        if (trim($title) === 'Provvigioni') {
            return $fail('title');
        }

        $calc = Statements::storeFile($file, $err);
        if ($err !== null) {
            return $fail('file_' . $err);
        }
        if (!$calc && $source && !empty($source['calc_path'])) {
            $calc = ['path' => (string)$source['calc_path'], 'name' => (string)$source['calc_name']];
        }
        $noInv = $source ? (int)$source['invoice_required'] === 0 : !empty($d['no_invoice']);
        $notes = trim((string)($d['notes'] ?? '')) ?: ($source['notes'] ?? null);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO commission_plans
                    (payee_type, payee_id, title, customer_name, contact_id, sibill_invoice_id, sale_amount,
                     commission_pct, pct_base, vat_rate, commission_total, invoice_required, notes,
                     calc_path, calc_name, source_statement_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $type, (int)$pid, $title, $customer !== '' ? $customer : null,
                ($invoice['contact_id'] ?? null) ?: (((int)($d['contact_id'] ?? 0)) ?: null),
                $invoice ? (int)$invoice['id'] : null, $sale,
                $pct, $base, $vat, $total, $noInv ? 0 : 1, $notes ?: null,
                $calc['path'] ?? null, $calc['name'] ?? null, $source ? (int)$source['id'] : null, $userId ?: null,
            ]);
            $id  = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare(
                'INSERT INTO commission_plan_rates (plan_id, seq, due_date, customer_amount, commission_amount, sibill_flow_ref)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($rates as $i => $r) {
                $ins->execute([$id, $i + 1, $r['due'], $r['amount'], $shares[$i], $r['flow']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($calc && !$source) {
                @unlink(Statements::uploadDir() . '/' . basename((string)$calc['path']));
            }
            throw $e;
        }

        Log::write('commission', 'plan_created', 'commission_plan', $id, [
            'payee' => "$type:$pid", 'total' => $total, 'rates' => count($rates),
            'sibill_invoice' => $invoice['id'] ?? null, 'from_statement' => $source['id'] ?? null, 'by' => $userId,
        ]);
        if ($source) {
            Statements::cancel((int)$source['id'], 'Diviso in rate: provvigione a rate n. ' . $id, $userId);
        }
        self::notifyPlan($id);

        // Instalments the customer has already paid are earned at once.
        $earned = $invoice ? self::syncSibill($id) : 0;
        return ['ok' => true, 'id' => $id, 'error' => null, 'earned' => $earned];
    }

    /**
     * The payee's share of each instalment: proportional to its amount, rounded
     * to the cent, the last one taking what rounding left so the shares add up
     * to the total exactly.
     *
     * @param float[] $amounts
     * @return float[]
     */
    public static function shares(array $amounts, float $total): array
    {
        $sale = array_sum($amounts);
        $out  = [];
        $sum  = 0.0;
        $n    = count($amounts);
        foreach (array_values($amounts) as $i => $a) {
            if ($i === $n - 1) {
                $out[] = round($total - $sum, 2);
                break;
            }
            $s = $sale > 0 ? round($a * $total / $sale, 2) : 0.0;
            $out[] = $s;
            $sum = round($sum + $s, 2);
        }
        return $out;
    }

    /** A statement the office may still turn into a plan: unpaid, and no invoice for the whole of it yet. */
    public static function splittable(array $st): bool
    {
        if (!empty($st['plan_id'])) {
            return false;
        }
        if ($st['status'] === 'sent') {
            return true;
        }
        return $st['status'] === 'invoiced' && (int)($st['invoice_required'] ?? 1) === 0
            && (string)($st['invoice_number'] ?? '') === '';
    }

    // ---- the customer pays ----------------------------------------------------------

    /**
     * The customer paid this instalment: file the payee's share as a statement.
     * $source 'office' (a person said so) or 'sibill' (the sync saw it paid).
     *
     * @return array{ok:bool, error:?string, statement_id?:int}
     */
    public static function earn(int $rateId, ?string $paidOn, string $source, ?int $userId, bool $notify = true): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT * FROM commission_plan_rates WHERE id = ? FOR UPDATE');
            $q->execute([$rateId]);
            $rate = $q->fetch();
            $plan = $rate ? self::find((int)$rate['plan_id']) : null;
            if (!$rate || !$plan || $rate['status'] !== 'waiting' || $plan['status'] !== 'active') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'state'];
            }
            $on = self::date((string)$paidOn) ?? date('Y-m-d');
            if ($on > date('Y-m-d')) {
                // A person typing tomorrow is a mistake; Sibill's expected date
                // on a flow already marked paid just means "paid", as of now.
                if ($source !== 'sibill') {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'future'];
                }
                $on = date('Y-m-d');
            }
            $n   = self::rateCount((int)$plan['id']);
            $sid = Statements::fileFromPlan($plan, $rate, $n, $on, $userId);
            $pdo->prepare(
                "UPDATE commission_plan_rates
                    SET status = 'earned', customer_paid_on = ?, paid_source = ?, earned_at = NOW(), earned_by = ?, statement_id = ?
                  WHERE id = ?"
            )->execute([$on, $source === 'sibill' ? 'sibill' : 'office', $userId ?: null, $sid, $rateId]);
            self::refreshStatus((int)$plan['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Log::write('commission', 'plan_rate_earned', 'commission_plan', (int)$plan['id'], [
            'rate' => (int)$rate['seq'], 'statement' => $sid, 'amount' => (float)$rate['commission_amount'],
            'paid_on' => $on, 'source' => $source, 'by' => $userId,
        ]);
        if ($notify) {
            self::notifyEarned($plan, [['rate' => $rate, 'statement_id' => $sid]], $n);
        }
        return ['ok' => true, 'error' => null, 'statement_id' => $sid];
    }

    /**
     * "The customer did not pay after all": a mistake by the office is put
     * back. Only an instalment the office marked (one Sibill marked would be
     * earned again by the next sync) and only while its statement is unpaid.
     */
    public static function undo(int $rateId, ?int $userId): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare(
                'SELECT r.*, s.status AS st_status FROM commission_plan_rates r
                   LEFT JOIN commission_statements s ON s.id = r.statement_id
                  WHERE r.id = ? FOR UPDATE'
            );
            $q->execute([$rateId]);
            $rate = $q->fetch();
            $plan = $rate ? self::find((int)$rate['plan_id']) : null;
            if (!$rate || !$plan || $rate['status'] !== 'earned' || $rate['paid_source'] !== 'office'
                || !in_array((string)$rate['st_status'], ['sent', 'invoiced'], true) || $plan['status'] === 'cancelled') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'state'];
            }
            $pdo->prepare("UPDATE commission_statements SET status = 'cancelled', cancel_note = ?, cancelled_at = NOW() WHERE id = ?")
                ->execute(['Incasso della rata annullato', (int)$rate['statement_id']]);
            $pdo->prepare(
                "UPDATE commission_plan_rates
                    SET status = 'waiting', customer_paid_on = NULL, paid_source = NULL, earned_at = NULL, earned_by = NULL, statement_id = NULL
                  WHERE id = ?"
            )->execute([$rateId]);
            $pdo->prepare("UPDATE commission_plans SET status = 'active', completed_at = NULL WHERE id = ? AND status = 'completed'")
                ->execute([(int)$plan['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Log::write('commission', 'plan_rate_undone', 'commission_plan', (int)$plan['id'],
            ['rate' => (int)$rate['seq'], 'statement' => (int)$rate['statement_id'], 'by' => $userId]);
        return ['ok' => true, 'error' => null];
    }

    /** Withdraw a plan: what the customer has not paid yet will not be paid out. Earned statements stay. */
    public static function cancel(int $planId, string $note, ?int $userId): array
    {
        $plan = self::find($planId);
        if (!$plan || $plan['status'] !== 'active') {
            return ['ok' => false, 'error' => 'state'];
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $n = $pdo->prepare("UPDATE commission_plan_rates SET status = 'cancelled' WHERE plan_id = ? AND status = 'waiting'");
            $n->execute([$planId]);
            $pdo->prepare("UPDATE commission_plans SET status = 'cancelled', cancel_note = ?, cancelled_at = NOW() WHERE id = ?")
                ->execute([mb_substr(trim($note), 0, 255) ?: null, $planId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Log::write('commission', 'plan_cancelled', 'commission_plan', $planId,
            ['rates' => $n->rowCount(), 'note' => $note, 'by' => $userId]);
        return ['ok' => true, 'error' => null];
    }

    /** completed once no instalment is still waiting for the customer. */
    private static function refreshStatus(int $planId): void
    {
        Db::pdo()->prepare(
            "UPDATE commission_plans p SET p.status = 'completed', p.completed_at = NOW()
              WHERE p.id = ? AND p.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM commission_plan_rates r WHERE r.plan_id = p.id AND r.status = 'waiting')"
        )->execute([$planId]);
    }

    // ---- Sibill -----------------------------------------------------------------------

    /**
     * Earn every instalment whose Sibill flow is now PAID. Run after each Sibill
     * sync (Sibill\Invoices::sync) and when a plan is filed. A plan whose
     * invoice was re-planned or removed upstream gets a note for the office
     * instead of guesses: flows are matched by Sibill's own id only.
     *
     * @return int instalments earned
     */
    public static function syncSibill(?int $planId = null): int
    {
        $pdo = Db::pdo();
        $sql = "SELECT * FROM commission_plans WHERE status = 'active' AND sibill_invoice_id IS NOT NULL"
             . ($planId ? ' AND id = ' . (int)$planId : '');
        $earned = 0;
        foreach ($pdo->query($sql)->fetchAll() ?: [] as $plan) {
            $pidn = (int)$plan['id'];
            try {
                $inv = self::sibillInvoice((int)$plan['sibill_invoice_id']);
                $flows = [];
                foreach ($inv['flows'] ?? [] as $f) {
                    $flows[(string)$f['sibill_id']] = $f;
                }
                $missing = 0;
                $done = [];
                foreach (self::rates($pidn) as $r) {
                    if ($r['status'] !== 'waiting' || (string)$r['sibill_flow_ref'] === '') {
                        continue;
                    }
                    $f = $flows[(string)$r['sibill_flow_ref']] ?? null;
                    if (!$f) {
                        $missing++;
                        continue;
                    }
                    if ($f['payment_status'] !== 'PAID') {
                        continue;
                    }
                    $res = self::earn((int)$r['id'], $f['settled_date'] ?: date('Y-m-d'), 'sibill', null, false);
                    if ($res['ok']) {
                        $earned++;
                        $done[] = ['rate' => $r, 'statement_id' => (int)$res['statement_id']];
                    }
                }
                $note = !$inv ? 'invoice_gone' : ($missing > 0 ? 'flows_changed' : null);
                if ($note !== ($plan['sync_note'] ?? null)) {
                    $pdo->prepare('UPDATE commission_plans SET sync_note = ? WHERE id = ?')->execute([$note, $pidn]);
                    if ($note !== null) {
                        Log::write('commission', 'plan_sibill_changed', 'commission_plan', $pidn, ['note' => $note, 'missing' => $missing]);
                    }
                }
                if ($done) {
                    self::notifyEarned($plan, $done, self::rateCount($pidn));
                }
            } catch (Throwable $e) {
                Log::write('commission', 'plan_sync_failed', 'commission_plan', $pidn, ['error' => $e->getMessage()]);
            }
        }
        return $earned;
    }

    /** A mirrored Sibill invoice with its flows in payment order, or null. */
    public static function sibillInvoice(int $id): ?array
    {
        $pdo = Db::pdo();
        $s = $pdo->prepare("SELECT * FROM sibill_invoices WHERE id = ? AND doc_type = 'INVOICE'");
        $s->execute([$id]);
        $inv = $s->fetch();
        if (!$inv) {
            return null;
        }
        $f = $pdo->prepare(
            'SELECT sibill_id, amount, payment_status, payment_method, due_date, settled_date
               FROM sibill_flows WHERE invoice_id = ? ORDER BY due_date IS NULL, due_date, sibill_id'
        );
        $f->execute([$id]);
        $inv['flows'] = $f->fetchAll() ?: [];
        return $inv;
    }

    /**
     * Invoices the office can pick for a plan: by customer name, number or VAT,
     * newest first, each with its instalments.
     */
    public static function searchSibill(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . $q . '%';
        $s = Db::pdo()->prepare(
            "SELECT id FROM sibill_invoices
              WHERE doc_type = 'INVOICE' AND (counterpart_name LIKE ? OR number = ? OR counterpart_vat LIKE ?)
              ORDER BY creation_date DESC, id DESC LIMIT " . max(1, min(50, $limit))
        );
        $s->execute([$like, $q, $like]);
        $out = [];
        foreach ($s->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            $inv = self::sibillInvoice((int)$id);
            if ($inv) {
                $out[] = $inv;
            }
        }
        return $out;
    }

    // ---- reading ----------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $s = Db::pdo()->prepare(
            "SELECT p.*, CASE WHEN p.payee_type = 'partner' THEN pt.name
                              ELSE COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) END AS payee_name,
                    i.number AS invoice_number, i.creation_date AS invoice_date
               FROM commission_plans p
               LEFT JOIN partners pt ON p.payee_type = 'partner' AND pt.id = p.payee_id
               LEFT JOIN users u     ON p.payee_type = 'agent'   AND u.id = p.payee_id
               LEFT JOIN sibill_invoices i ON i.id = p.sibill_invoice_id
              WHERE p.id = ?"
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** A plan's instalments, each with the state of the statement it became. */
    public static function rates(int $planId): array
    {
        $s = Db::pdo()->prepare(
            'SELECT r.*, s.status AS st_status, s.amount AS st_amount, s.paid_on AS st_paid_on,
                    s.paid_amount AS st_paid_amount, s.invoice_number AS st_invoice_number
               FROM commission_plan_rates r
               LEFT JOIN commission_statements s ON s.id = r.statement_id
              WHERE r.plan_id = ? ORDER BY r.seq'
        );
        $s->execute([$planId]);
        return $s->fetchAll() ?: [];
    }

    public static function rateCount(int $planId): int
    {
        $s = Db::pdo()->prepare('SELECT COUNT(*) FROM commission_plan_rates WHERE plan_id = ?');
        $s->execute([$planId]);
        return (int)$s->fetchColumn();
    }

    /**
     * Plans for the office (every payee) or one payee, active first, each with
     * its rates and the sums a card shows.
     *
     * @param array $f payee ?[type, id], status ?string ('open' = active only)
     */
    public static function all(array $f = []): array
    {
        $where = [];
        $args  = [];
        if (!empty($f['payee']) && is_array($f['payee'])) {
            $where[] = 'p.payee_type = ? AND p.payee_id = ?';
            $args[]  = (string)$f['payee'][0];
            $args[]  = (int)$f['payee'][1];
        }
        if (($f['status'] ?? '') === 'open') {
            $where[] = "p.status = 'active'";
        } elseif (in_array($f['status'] ?? '', self::STATUSES, true)) {
            $where[] = 'p.status = ?';
            $args[]  = (string)$f['status'];
        }
        $s = Db::pdo()->prepare(
            "SELECT p.*, CASE WHEN p.payee_type = 'partner' THEN pt.name
                              ELSE COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) END AS payee_name,
                    i.number AS invoice_number, i.creation_date AS invoice_date
               FROM commission_plans p
               LEFT JOIN partners pt ON p.payee_type = 'partner' AND pt.id = p.payee_id
               LEFT JOIN users u     ON p.payee_type = 'agent'   AND u.id = p.payee_id
               LEFT JOIN sibill_invoices i ON i.id = p.sibill_invoice_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY (p.status = 'active') DESC, p.id DESC LIMIT 300"
        );
        $s->execute($args);
        $rows = $s->fetchAll() ?: [];
        foreach ($rows as &$p) {
            $p['rates'] = self::rates((int)$p['id']);
            $p['sum_waiting'] = $p['sum_earned'] = $p['sum_paid'] = 0.0;
            $p['n_waiting'] = $p['n_earned'] = 0;
            foreach ($p['rates'] as $r) {
                if ($r['status'] === 'waiting') {
                    $p['sum_waiting'] += (float)$r['commission_amount'];
                    $p['n_waiting']++;
                } elseif ($r['status'] === 'earned') {
                    $p['sum_earned'] += (float)$r['commission_amount'];
                    $p['n_earned']++;
                    if ($r['st_status'] === 'paid') {
                        $p['sum_paid'] += (float)($r['st_paid_amount'] ?? $r['commission_amount']);
                    }
                }
            }
        }
        unset($p);
        return $rows;
    }

    /**
     * What is still waiting for customers to pay — the commission not earned
     * yet, over active plans. One payee, or everyone.
     * @return array{amount:float, n:int}
     */
    public static function waiting(?string $type = null, ?int $id = null): array
    {
        $sql = "SELECT COALESCE(SUM(r.commission_amount), 0) v, COUNT(*) n
                  FROM commission_plan_rates r JOIN commission_plans p ON p.id = r.plan_id
                 WHERE r.status = 'waiting' AND p.status = 'active'";
        $args = [];
        if ($type !== null) {
            $sql .= ' AND p.payee_type = ? AND p.payee_id = ?';
            $args = [$type, (int)$id];
        }
        $s = Db::pdo()->prepare($sql);
        $s->execute($args);
        $r = $s->fetch();
        return ['amount' => round((float)($r['v'] ?? 0), 2), 'n' => (int)($r['n'] ?? 0)];
    }

    public static function countFor(string $type, int $id): int
    {
        $s = Db::pdo()->prepare('SELECT COUNT(*) FROM commission_plans WHERE payee_type = ? AND payee_id = ?');
        $s->execute([$type, $id]);
        return (int)$s->fetchColumn();
    }

    // ---- notices (queued, like every commission notice) -------------------------------

    /** "Your commission on this sale is paid as the customer pays" — to the payee. */
    private static function notifyPlan(int $planId): void
    {
        try {
            $p = self::find($planId);
            $payee = $p ? Statements::payee((string)$p['payee_type'], (int)$p['payee_id']) : null;
            if (!$p || !$payee) {
                return;
            }
            $lines = [];
            foreach (self::rates($planId) as $r) {
                $lines[] = $r['seq'] . ') ' . ($r['due_date'] ? date('d/m/Y', strtotime((string)$r['due_date'])) : '—')
                         . ' — ' . Statements::money((float)$r['commission_amount']);
            }
            $isPartner = $p['payee_type'] === 'partner';
            (new Scheduler())->enqueue([
                'entity_type'    => 'commission_plan',
                'entity_id'      => $planId,
                'rule_key'       => 'commission_plan',
                'recipient_type' => $isPartner ? 'partner' : 'agent',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s'),
                'payload'        => self::payload($p, $payee) + [
                    'amount'    => Statements::money((float)$p['commission_total']),
                    'rates'     => (string)count($lines),
                    'schedule'  => implode("\n", $lines),
                    'schedule_html' => implode('<br>', array_map(fn($l) => htmlspecialchars($l, ENT_QUOTES), $lines)),
                ],
                'dedupe_key'     => 'commission_plan:' . $planId,
            ]);
        } catch (Throwable $e) {
            Log::write('commission', 'statement_notify_failed', 'commission_plan', $planId, ['rule' => 'commission_plan', 'error' => $e->getMessage()]);
        }
    }

    /**
     * One notice for everything one event earned on a plan — a sync that finds
     * three instalments paid sends one message, not three.
     *
     * @param array $done [['rate' => row, 'statement_id' => int], ...]
     */
    private static function notifyEarned(array $plan, array $done, int $n): void
    {
        if (!$done) {
            return;
        }
        $seqs = array_map(fn($d) => (int)$d['rate']['seq'], $done);
        $sum  = array_sum(array_map(fn($d) => (float)$d['rate']['commission_amount'], $done));
        $cust = array_sum(array_map(fn($d) => (float)$d['rate']['customer_amount'], $done));
        $nums = implode(', ', $seqs);
        $last = count($seqs) > 1 ? array_pop($seqs) : null;
        $list = implode(', ', $seqs) . ($last !== null ? ' e ' . $last : '');
        $payee = Statements::payee((string)$plan['payee_type'], (int)$plan['payee_id']);
        $sid   = (int)$done[0]['statement_id'];
        Statements::notifyPayee(
            $sid,
            (int)$plan['invoice_required'] === 1 ? 'commission_rate_earned' : 'commission_rate_earned_noinv',
            [
                // straight to the statement to invoice, not to the plan
                'link'            => Config::appBaseUrl() . ($plan['payee_type'] === 'partner'
                                         ? '/partner.php?tab=commissions' : '/dashboard.php?tab=my_commissions')
                                     . '&st=' . $sid . '#cm-' . $sid,
            ] + ($payee ? self::payload($plan, $payee) : []) + [
                'amount'          => Statements::money($sum),
                'rate'            => (count($done) > 1 ? 'rate ' : 'rata ') . $list . ' di ' . $n,
                'rate_no'         => $nums,
                'rates'           => (string)$n,
                'customer_amount' => Statements::money($cust),
                'title'           => (string)$plan['title'],
            ]
        );
    }

    private static function payload(array $p, array $payee): array
    {
        $isPartner = $p['payee_type'] === 'partner';
        return [
            'name'       => (string)$payee['name'],
            'payee_name' => (string)$payee['name'],
            'id'         => (string)$p['id'],
            'title'      => (string)$p['title'],
            'customer'   => (string)($p['customer_name'] ?? ''),
            'link'       => Config::appBaseUrl() . ($isPartner ? '/partner.php?tab=commissions' : '/dashboard.php?tab=my_commissions')
                          . '#cp-' . (int)$p['id'],
        ] + ($isPartner
            ? ['partner_name' => (string)$payee['name'], 'partner_phone' => (string)($payee['phone'] ?? ''), 'partner_email' => (string)($payee['email'] ?? '')]
            : ['agent_name' => (string)$payee['name'], 'agent_phone' => (string)($payee['phone'] ?? ''), 'agent_email' => (string)($payee['email'] ?? '')]);
    }

    // ---- formats ----------------------------------------------------------------------

    /** "10", "10,5", "7.25 %" -> float; unreadable -> 0. */
    public static function parsePct(string $raw): float
    {
        $v = str_replace(',', '.', (string)preg_replace('/[^\d.,]/', '', $raw));
        return is_numeric($v) ? round((float)$v, 3) : 0.0;
    }

    private static function date(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        [$y, $mo, $d] = array_map('intval', explode('-', $s));
        return checkdate($mo, $d, $y) ? $s : null;
    }
}
