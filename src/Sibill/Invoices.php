<?php
declare(strict_types=1);

namespace Glue\Sibill;

use Glue\Config;
use Glue\Crm\VatLock;
use Glue\Db;
use Glue\Event\Log;
use Glue\Settings;
use PDO;
use Throwable;

/**
 * Keeps the local mirror of Sibill's issued invoices in step, and answers the
 * only question the CRM actually asks of it: has this been paid?
 *
 * Sibill splits every invoice into "flows" (scadenze) — one per instalment, each
 * PAID or TO_PAY. Payment state is therefore derived, never read from a single
 * field: all flows paid = paid, some = partial, none = unpaid. An invoice with
 * no flows at all has no payment plan recorded upstream; we call that 'unknown'
 * and count its full total as still open, so exposure is never understated.
 *
 * The API cannot filter by payment status and has no date-range operator, so the
 * sync simply walks every issued document (~7 requests at 100/page for a few
 * hundred invoices) and rewrites what it finds. Cheap, and immune to an old
 * invoice being paid long after we first saw it.
 */
final class Invoices
{
    /** Document types worth mirroring; anything else upstream is ignored. */
    private const TYPES = ['INVOICE', 'CREDIT_NOTE'];

    // ---- sync ---------------------------------------------------------------

    /**
     * Pull issued documents and rewrite the mirror.
     *
     * @param array $opts months:int limit the walk to invoices dated within the
     *                    last N months (0/absent = everything).
     * @return array{invoices:int,flows:int,paid:int,unpaid:int,pruned:int,partial:int}
     */
    public static function sync(array $opts = []): array
    {
        $client  = new Client();
        $company = $client->companyId();
        $months  = (int)($opts['months'] ?? Config::get('sibill.sync_months', 0));
        $cutoff  = $months > 0 ? date('Y-m-d', strtotime("-$months months")) : null;
        $startedAt = date('Y-m-d H:i:s');

        $stats = ['invoices' => 0, 'flows' => 0, 'paid' => 0, 'partial' => 0, 'unpaid' => 0, 'pruned' => 0];
        $complete = true; // false if the horizon cut the walk short

        $client->each('documents', [
            'expand'            => 'flows,counterpart',
            'sort'              => '-creation_date',
            'filter[direction]' => 'ISSUED',
        ], function (array $rows) use (&$stats, &$complete, $company, $cutoff): bool {
            foreach ($rows as $doc) {
                // Sorted newest-first, so the first document past the horizon
                // means every remaining one is older too.
                if ($cutoff !== null && !empty($doc['creation_date']) && $doc['creation_date'] < $cutoff) {
                    $complete = false;
                    return false;
                }
                if (!in_array((string)($doc['type'] ?? ''), self::TYPES, true)) {
                    continue;
                }
                $s = self::upsert($doc, $company);
                $stats['invoices']++;
                $stats['flows'] += $s['flows'];
                if (isset($stats[$s['state']])) {
                    $stats[$s['state']]++;
                }
            }
            return true;
        });

        // Only prune on a complete walk: after a horizoned run the older rows are
        // untouched by design, not gone upstream.
        if ($complete && $stats['invoices'] > 0) {
            $stats['pruned'] = self::prune($company, $startedAt);
        }

        Log::write('sibill', 'sync', null, null, $stats);
        return $stats;
    }

    /**
     * Sync only if the configured interval has elapsed — this is what the
     * per-minute cron calls. Returns null when it was not due (or not set up).
     * Never throws: a Sibill outage must not stop reminders going out.
     */
    public static function syncIfDue(): ?array
    {
        if (!Client::configured() || !(bool)Config::get('sibill.enabled', false)) {
            return null;
        }
        $every = max(5, (int)Config::get('sibill.sync_minutes', 30));
        $last  = (string)Settings::get('sibill.last_sync_at', '');
        if ($last !== '' && (time() - (strtotime($last) ?: 0)) < $every * 60) {
            return null;
        }
        // Claim the slot before the walk, so a run that dies mid-way still waits
        // a full interval instead of retrying on the very next minute.
        Settings::set('sibill.last_sync_at', date('Y-m-d H:i:s'));

        try {
            return self::sync();
        } catch (Throwable $e) {
            Log::write('sibill', 'sync_error', null, null, ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /** Write one document and its flows. Returns the derived state + flow count. */
    private static function upsert(array $doc, string $company): array
    {
        $pdo = Db::pdo();
        $cp  = is_array($doc['counterpart'] ?? null) ? $doc['counterpart'] : [];
        $vat = VatLock::normalize((string)($cp['vat_number'] ?? $cp['tax_number'] ?? ''));

        $flows = is_array($doc['flows'] ?? null) ? $doc['flows'] : [];
        $roll  = self::rollUp($flows, (float)self::money($doc['gross_amount'] ?? null));
        $link  = self::resolveCrm($vat, (string)($cp['company_name'] ?? ''));

        $pdo->prepare(
            'INSERT INTO sibill_invoices
                (sibill_id, company_id, direction, doc_type, number, creation_date,
                 counterpart_name, counterpart_vat, currency, gross_amount, paid_amount,
                 open_amount, pay_state, due_date, last_paid_date, flows_count, flows_paid,
                 contact_id, deal_id, lead_id, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                doc_type = VALUES(doc_type), number = VALUES(number),
                creation_date = VALUES(creation_date), counterpart_name = VALUES(counterpart_name),
                counterpart_vat = VALUES(counterpart_vat), currency = VALUES(currency),
                gross_amount = VALUES(gross_amount), paid_amount = VALUES(paid_amount),
                open_amount = VALUES(open_amount), pay_state = VALUES(pay_state),
                due_date = VALUES(due_date), last_paid_date = VALUES(last_paid_date),
                flows_count = VALUES(flows_count), flows_paid = VALUES(flows_paid),
                contact_id = VALUES(contact_id), deal_id = VALUES(deal_id),
                lead_id = VALUES(lead_id), synced_at = NOW()'
        )->execute([
            (string)$doc['id'], $company, 'ISSUED', (string)($doc['type'] ?? 'INVOICE'),
            self::str($doc['number'] ?? null, 64), self::date($doc['creation_date'] ?? null),
            self::str($cp['company_name'] ?? null, 190), $vat !== '' ? $vat : null,
            (string)(($doc['gross_amount']['currency'] ?? null) ?: 'EUR'),
            $roll['gross'], $roll['paid'], $roll['open'], $roll['state'],
            $roll['due_date'], $roll['last_paid_date'], $roll['count'], $roll['paid_count'],
            $link['contact_id'], $link['deal_id'], $link['lead_id'],
        ]);

        $stmt = $pdo->prepare('SELECT id FROM sibill_invoices WHERE sibill_id = ?');
        $stmt->execute([(string)$doc['id']]);
        $invoiceId = (int)$stmt->fetchColumn();

        self::writeFlows($invoiceId, $flows);
        return ['state' => $roll['state'], 'flows' => $roll['count']];
    }

    /** Replace an invoice's instalments wholesale — Sibill may re-plan them. */
    private static function writeFlows(int $invoiceId, array $flows): void
    {
        $pdo = Db::pdo();
        $pdo->prepare('DELETE FROM sibill_flows WHERE invoice_id = ?')->execute([$invoiceId]);
        if (!$flows) {
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO sibill_flows
                (invoice_id, sibill_id, amount, currency, payment_status, payment_method, due_date, settled_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                invoice_id = VALUES(invoice_id), amount = VALUES(amount), currency = VALUES(currency),
                payment_status = VALUES(payment_status), payment_method = VALUES(payment_method),
                due_date = VALUES(due_date), settled_date = VALUES(settled_date)'
        );
        foreach ($flows as $f) {
            $paid = (string)($f['payment_status'] ?? 'TO_PAY') === 'PAID';
            $stmt->execute([
                $invoiceId, (string)$f['id'], self::money($f['amount'] ?? null),
                (string)(($f['amount']['currency'] ?? null) ?: 'EUR'),
                $paid ? 'PAID' : 'TO_PAY', self::str($f['payment_method'] ?? null, 32),
                self::date($f['payment_date'] ?? null),
                $paid ? self::date($f['expected_payment_date'] ?? null) : null,
            ]);
        }
    }

    /** Derive the invoice-level payment picture from its instalments. */
    private static function rollUp(array $flows, float $gross): array
    {
        $paid = 0.0; $open = 0.0; $count = 0; $paidCount = 0;
        $due = null; $lastPaid = null;

        foreach ($flows as $f) {
            $count++;
            $amount = (float)self::money($f['amount'] ?? null);
            if ((string)($f['payment_status'] ?? 'TO_PAY') === 'PAID') {
                $paidCount++;
                $paid += $amount;
                $settled = self::date($f['expected_payment_date'] ?? null);
                if ($settled !== null && ($lastPaid === null || $settled > $lastPaid)) {
                    $lastPaid = $settled;
                }
            } else {
                $open += $amount;
                $d = self::date($f['payment_date'] ?? null);
                if ($d !== null && ($due === null || $d < $due)) {
                    $due = $d; // the next instalment to chase
                }
            }
        }

        if ($count === 0) {
            // No payment plan upstream. We cannot claim it is paid, and treating
            // it as zero-owed would hide real exposure, so the whole total is open.
            $state = 'unknown';
            $open  = $gross;
        } elseif ($paidCount === $count) {
            $state = 'paid';
        } elseif ($paidCount === 0) {
            $state = 'unpaid';
        } else {
            $state = 'partial';
        }

        return [
            'gross' => number_format($gross, 2, '.', ''),
            'paid'  => number_format($paid, 2, '.', ''),
            'open'  => number_format($open, 2, '.', ''),
            'state' => $state, 'due_date' => $due, 'last_paid_date' => $lastPaid,
            'count' => $count, 'paid_count' => $paidCount,
        ];
    }

    /** Drop rows the last complete walk did not touch — gone upstream. */
    private static function prune(string $company, string $startedAt): int
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id FROM sibill_invoices WHERE company_id = ? AND synced_at < ?');
        $stmt->execute([$company, $startedAt]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM sibill_flows WHERE invoice_id IN ($in)")->execute($ids);
        $pdo->prepare("DELETE FROM sibill_invoices WHERE id IN ($in)")->execute($ids);
        return count($ids);
    }

    // ---- CRM matching -------------------------------------------------------

    /**
     * Candidate forms of a VAT number, so "IT10125441211" on a lead still matches
     * "10125441211" on the invoice (Sibill stores Italian VATs without the prefix,
     * agents type them both ways).
     */
    public static function vatKeys(string $vat): array
    {
        $v = VatLock::normalize($vat);
        if ($v === '') {
            return [];
        }
        $keys = [$v];
        if (preg_match('/^([A-Z]{2})(\d{6,})$/', $v, $m)) {
            $keys[] = $m[2];             // strip the country prefix
        } elseif (preg_match('/^\d{6,}$/', $v)) {
            $keys[] = 'IT' . $v;         // and add the one an agent may have typed
        }
        return array_values(array_unique($keys));
    }

    /**
     * Best-effort link from an invoice's counterpart to CRM records: by VAT
     * first (exact, reliable), then by company name (loose, only when unique).
     */
    private static function resolveCrm(string $vat, string $companyName): array
    {
        $none = ['contact_id' => null, 'deal_id' => null, 'lead_id' => null];
        $pdo  = Db::pdo();

        $lead = null;
        $keys = self::vatKeys($vat);
        if ($keys) {
            $in = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, contact_id FROM leads WHERE vat_number IN ($in) ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute($keys);
            $lead = $stmt->fetch() ?: null;
        }

        $contactId = $lead && $lead['contact_id'] !== null ? (int)$lead['contact_id'] : null;

        // No VAT match: fall back to an unambiguous company-name hit on contacts.
        if ($contactId === null && trim($companyName) !== '') {
            $stmt = $pdo->prepare('SELECT id FROM contacts WHERE company = ? LIMIT 2');
            $stmt->execute([trim($companyName)]);
            $hits = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($hits) === 1) {
                $contactId = (int)$hits[0];
            }
        }

        if ($lead === null && $contactId === null) {
            return $none;
        }

        // The deal this invoice most plausibly belongs to: newest won deal for the
        // contact, else newest deal of any status.
        $dealId = null;
        if ($contactId !== null) {
            $stmt = $pdo->prepare(
                "SELECT id FROM deals WHERE contact_id = ?
                 ORDER BY (status = 'won') DESC, id DESC LIMIT 1"
            );
            $stmt->execute([$contactId]);
            $dealId = ($v = $stmt->fetchColumn()) !== false ? (int)$v : null;
        }

        return [
            'contact_id' => $contactId,
            'deal_id'    => $dealId,
            'lead_id'    => $lead ? (int)$lead['id'] : null,
        ];
    }

    /** Re-run CRM matching over the mirror (after leads/contacts change). */
    public static function relink(): int
    {
        $pdo  = Db::pdo();
        $rows = $pdo->query('SELECT id, counterpart_vat, counterpart_name FROM sibill_invoices')->fetchAll();
        $stmt = $pdo->prepare('UPDATE sibill_invoices SET contact_id = ?, deal_id = ?, lead_id = ? WHERE id = ?');
        $n = 0;
        foreach ($rows as $r) {
            $link = self::resolveCrm((string)($r['counterpart_vat'] ?? ''), (string)($r['counterpart_name'] ?? ''));
            $stmt->execute([$link['contact_id'], $link['deal_id'], $link['lead_id'], (int)$r['id']]);
            if ($link['contact_id'] !== null) {
                $n++;
            }
        }
        return $n;
    }

    // ---- reads --------------------------------------------------------------

    /** Headline numbers for the invoices page. Amounts are floats for display. */
    public static function summary(): array
    {
        $row = Db::pdo()->query(
            "SELECT
                COUNT(*)                                                   AS total,
                SUM(pay_state = 'paid')                                    AS paid,
                SUM(pay_state = 'partial')                                 AS partial,
                SUM(pay_state = 'unpaid')                                  AS unpaid,
                SUM(pay_state = 'unknown')                                 AS unknown,
                SUM(pay_state <> 'paid' AND due_date IS NOT NULL AND due_date < CURDATE()) AS overdue,
                COALESCE(SUM(open_amount), 0)                              AS open_total,
                COALESCE(SUM(CASE WHEN pay_state <> 'paid' AND due_date IS NOT NULL
                                  AND due_date < CURDATE() THEN open_amount ELSE 0 END), 0) AS overdue_total
             FROM sibill_invoices"
        )->fetch() ?: [];
        return array_map(static fn($v) => $v === null ? 0 : (0 + $v), $row);
    }

    /**
     * Invoice list for the page.
     * @param array $f state:paid|partial|unpaid|unknown|overdue|open, q:string, linked:bool
     */
    public static function search(array $f = [], int $limit = 300): array
    {
        $where = [];
        $args  = [];

        $state = (string)($f['state'] ?? '');
        if ($state === 'overdue') {
            $where[] = "i.pay_state <> 'paid' AND i.due_date IS NOT NULL AND i.due_date < CURDATE()";
        } elseif ($state === 'open') {
            $where[] = "i.pay_state <> 'paid'";
        } elseif (in_array($state, ['paid', 'partial', 'unpaid', 'unknown'], true)) {
            $where[] = 'i.pay_state = ?';
            $args[]  = $state;
        }
        if (trim((string)($f['q'] ?? '')) !== '') {
            $q = '%' . trim((string)$f['q']) . '%';
            $where[] = '(i.counterpart_name LIKE ? OR i.counterpart_vat LIKE ? OR i.number LIKE ?)';
            array_push($args, $q, $q, $q);
        }
        if (!empty($f['linked'])) {
            $where[] = 'i.contact_id IS NOT NULL';
        }
        $sql = 'SELECT i.*, c.name AS contact_name, d.title AS deal_title
                FROM sibill_invoices i
                LEFT JOIN contacts c ON c.id = i.contact_id
                LEFT JOIN deals d    ON d.id = i.deal_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY (i.pay_state <> 'paid' AND i.due_date IS NOT NULL AND i.due_date < CURDATE()) DESC,
                        i.due_date IS NULL, i.due_date ASC, i.creation_date DESC
               LIMIT " . max(1, min(2000, $limit));

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** Every invoice we hold for a CRM contact — used on the contact/deal panes. */
    public static function forContact(int $contactId, int $limit = 50): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM sibill_invoices WHERE contact_id = ?
             ORDER BY creation_date DESC LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([$contactId]);
        return $stmt->fetchAll();
    }

    /** Instalments of one invoice, soonest first. */
    public static function flows(int $invoiceId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM sibill_flows WHERE invoice_id = ? ORDER BY due_date IS NULL, due_date ASC'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    /** When the mirror was last refreshed, or null if it never has been. */
    public static function lastSyncAt(): ?string
    {
        try {
            $v = Db::pdo()->query('SELECT MAX(synced_at) FROM sibill_invoices')->fetchColumn();
            return $v ? (string)$v : null;
        } catch (Throwable) {
            return null; // table not migrated yet
        }
    }

    // ---- helpers ------------------------------------------------------------

    /** Sibill money objects are {"currency":"EUR","amount":"961.36"}. */
    private static function money(mixed $m): string
    {
        $v = is_array($m) ? ($m['amount'] ?? 0) : $m;
        return number_format((float)$v, 2, '.', '');
    }

    private static function date(mixed $v): ?string
    {
        $s = trim((string)($v ?? ''));
        if ($s === '') {
            return null;
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private static function str(mixed $v, int $max): ?string
    {
        $s = trim((string)($v ?? ''));
        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
