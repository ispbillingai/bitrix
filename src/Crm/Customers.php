<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Sibill\Invoices as SibillInvoices;
use PDO;

/**
 * The customer registry — everyone the business actually serves, as opposed to
 * the pipeline of people it is still trying to win.
 *
 * A customer is a contacts row with is_customer = 1. That single decision is
 * what makes the customer page cheap: tickets, sign documents, payment
 * contracts, portal logins, leads and deals already point at contact_id, and
 * Sibill invoices resolve to it by VAT. This class only aggregates; the one
 * thing it writes is the flag itself (a won deal becomes a customer) and the
 * router link (a network area assigned to a customer site).
 *
 * The rows themselves come from two places: the gestionale export via
 * CustomerImport (identity = customer_code), and won deals (markFromDeal).
 */
final class Customers
{
    // ---- the list -----------------------------------------------------------

    /**
     * Paged, filtered customer list.
     *
     * Aggregates (owed money, support contract, routers) are joined per page
     * from grouped subqueries — never stored, so they cannot go stale.
     *
     * @param array $f q: free text; state: all|owing|support|no_contact|expiring
     * @return array{rows: array<int,array>, total: int, page: int, pages: int}
     */
    public static function search(array $f = [], int $page = 1, int $per = 50): array
    {
        $pdo   = Db::pdo();
        $where = ['c.is_customer = 1'];
        $args  = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.name LIKE ? OR c.company LIKE ? OR c.vat_number LIKE ? OR c.customer_code LIKE ?
                         OR c.phone LIKE ? OR c.phone2 LIKE ? OR c.email LIKE ? OR c.city LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $state = (string)($f['state'] ?? 'all');
        // "Support" is either kind of contract: a live SmallPay subscription or a
        // gestionale contract whose expiry is still ahead. "Expired" is the
        // renewal list — they had one, it lapsed, and nothing replaced it.
        $where[] = match ($state) {
            'owing'      => 'COALESCE(inv.open_amount, 0) > 0',
            'support'    => '(COALESCE(pc.active_contracts, 0) > 0
                              OR (c.contract_expiry IS NOT NULL AND c.contract_expiry >= CURDATE()))',
            'expired'    => 'c.contract_expiry IS NOT NULL AND c.contract_expiry < CURDATE()
                             AND COALESCE(pc.active_contracts, 0) = 0',
            'no_contact' => "(c.phone IS NULL OR c.phone = '') AND (c.phone2 IS NULL OR c.phone2 = '')
                             AND (c.email IS NULL OR c.email = '')",
            'expiring'   => 'c.contract_expiry IS NOT NULL AND c.contract_expiry <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)',
            default      => '1=1',
        };

        $w = implode(' AND ', $where);
        $joins = self::aggregateJoins();

        $total = (int)(function () use ($pdo, $w, $joins, $args) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM contacts c $joins WHERE $w");
            $stmt->execute($args);
            return $stmt->fetchColumn();
        })();

        $pages = max(1, (int)ceil($total / $per));
        $page  = max(1, min($page, $pages));
        $off   = ($page - 1) * $per;

        $stmt = $pdo->prepare(
            "SELECT c.*,
                    COALESCE(inv.open_amount, 0)     AS inv_open,
                    COALESCE(inv.overdue_count, 0)   AS inv_overdue,
                    COALESCE(inv.invoice_count, 0)   AS inv_count,
                    COALESCE(pc.active_contracts, 0) AS active_contracts,
                    COALESCE(na.router_count, 0)     AS router_count,
                    COALESCE(tk.open_tickets, 0)     AS open_tickets
             FROM contacts c
             $joins
             WHERE $w
             ORDER BY c.name ASC
             LIMIT $per OFFSET $off"
        );
        $stmt->execute($args);

        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * The LEFT JOINs the list and the counters share. Invoices attach by
     * contact_id — resolveCrm() writes that link from the VAT match on every
     * Sibill sync — so one grouped pass over each table serves the whole page.
     */
    private static function aggregateJoins(): string
    {
        return "
            LEFT JOIN (
                SELECT contact_id,
                       COUNT(*) AS invoice_count,
                       SUM(CASE WHEN direction = 'ISSUED' THEN open_amount ELSE 0 END) AS open_amount,
                       SUM(CASE WHEN direction = 'ISSUED' AND pay_state IN ('unpaid','partial')
                                     AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count
                FROM sibill_invoices
                WHERE contact_id IS NOT NULL
                GROUP BY contact_id
            ) inv ON inv.contact_id = c.id
            LEFT JOIN (
                SELECT contact_id, SUM(CASE WHEN status IN ('active','past_due') THEN 1 ELSE 0 END) AS active_contracts
                FROM payment_contracts
                WHERE contact_id IS NOT NULL
                GROUP BY contact_id
            ) pc ON pc.contact_id = c.id
            LEFT JOIN (
                SELECT contact_id, COUNT(*) AS router_count
                FROM network_areas
                WHERE contact_id IS NOT NULL AND active = 1
                GROUP BY contact_id
            ) na ON na.contact_id = c.id
            LEFT JOIN (
                SELECT contact_id, SUM(CASE WHEN status <> 'closed' THEN 1 ELSE 0 END) AS open_tickets
                FROM tickets
                GROUP BY contact_id
            ) tk ON tk.contact_id = c.id";
    }

    /** Counts for the filter chips, so the tabs say how much work sits behind them. */
    public static function counters(): array
    {
        $pdo = Db::pdo();
        $joins = self::aggregateJoins();
        $row = $pdo->query(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(inv.open_amount, 0) > 0 THEN 1 ELSE 0 END) AS owing,
                    SUM(CASE WHEN COALESCE(pc.active_contracts, 0) > 0
                                  OR (c.contract_expiry IS NOT NULL AND c.contract_expiry >= CURDATE())
                             THEN 1 ELSE 0 END) AS support,
                    SUM(CASE WHEN c.contract_expiry IS NOT NULL AND c.contract_expiry < CURDATE()
                                  AND COALESCE(pc.active_contracts, 0) = 0
                             THEN 1 ELSE 0 END) AS expired,
                    SUM(CASE WHEN (c.phone IS NULL OR c.phone = '') AND (c.phone2 IS NULL OR c.phone2 = '')
                                  AND (c.email IS NULL OR c.email = '') THEN 1 ELSE 0 END) AS no_contact
             FROM contacts c $joins WHERE c.is_customer = 1"
        )->fetch();
        return array_map('intval', $row ?: ['total' => 0, 'owing' => 0, 'support' => 0, 'expired' => 0, 'no_contact' => 0]);
    }

    // ---- one customer, whole ------------------------------------------------

    /**
     * Everything the customer page shows, in one call: profile plus the linked
     * invoices, contracts, chat threads, documents, pipeline history, routers.
     *
     * Invoices are fetched by contact_id OR by the customer's VAT keys — the
     * second leg catches invoices synced before this customer existed or not
     * yet relinked.
     */
    public static function overview(int $contactId): ?array
    {
        $pdo = Db::pdo();
        $c = Contacts::find($contactId);
        if (!$c) {
            return null;
        }

        // Invoices: by resolved link or by VAT, without double-counting.
        $vatKeys = SibillInvoices::vatKeys((string)($c['vat_number'] ?? ''));
        $cond = 'contact_id = ?';
        $args = [$contactId];
        if ($vatKeys) {
            $cond .= ' OR counterpart_vat IN (' . implode(',', array_fill(0, count($vatKeys), '?')) . ')';
            array_push($args, ...$vatKeys);
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM sibill_invoices WHERE ($cond)
             ORDER BY (pay_state IN ('unpaid','partial')) DESC, creation_date DESC, id DESC LIMIT 200"
        );
        $stmt->execute($args);
        $invoices = $stmt->fetchAll();

        $owed = 0.0;
        $overdue = 0;
        foreach ($invoices as $i) {
            if ($i['direction'] === 'ISSUED') {
                $owed += (float)$i['open_amount'];
                if (in_array($i['pay_state'], ['unpaid', 'partial'], true)
                    && $i['due_date'] !== null && $i['due_date'] < date('Y-m-d')) {
                    $overdue++;
                }
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM payment_contracts WHERE contact_id = ? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$contactId]);
        $contracts = $stmt->fetchAll();

        // The chat: every thread, with its full message history. A customer with
        // years of tickets still renders — messages are capped per thread by the
        // view, not truncated here where the cut would be invisible.
        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE contact_id = ? ORDER BY updated_at DESC LIMIT 50');
        $stmt->execute([$contactId]);
        $tickets = $stmt->fetchAll();
        if ($tickets) {
            $ids = array_column($tickets, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT m.*, d.status AS sign_status, d.title AS sign_title,
                        d.signed_at AS sign_signed_at, d.signed_path AS sign_signed_path
                 FROM ticket_messages m
                 LEFT JOIN sign_documents d ON d.id = m.sign_document_id
                 WHERE m.ticket_id IN ($in) ORDER BY m.id ASC"
            );
            $stmt->execute($ids);
            $byTicket = [];
            foreach ($stmt->fetchAll() as $m) {
                $byTicket[(int)$m['ticket_id']][] = $m;
            }
            foreach ($tickets as &$tk) {
                $tk['messages'] = $byTicket[(int)$tk['id']] ?? [];
            }
            unset($tk);
        }

        $stmt = $pdo->prepare('SELECT * FROM sign_documents WHERE contact_id = ? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$contactId]);
        $documents = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM deals WHERE contact_id = ? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$contactId]);
        $deals = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM leads WHERE contact_id = ? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$contactId]);
        $leads = $stmt->fetchAll();

        // This customer's routers, each with its device up/down tally.
        $stmt = $pdo->prepare(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM devices d WHERE d.area_id = a.id AND d.active = 1) AS device_count,
                    (SELECT COUNT(*) FROM devices d WHERE d.area_id = a.id AND d.active = 1 AND d.status = 'down') AS devices_down
             FROM network_areas a WHERE a.contact_id = ? ORDER BY a.sort_order, a.id"
        );
        $stmt->execute([$contactId]);
        $areas = $stmt->fetchAll();

        return [
            'contact'   => $c,
            'invoices'  => $invoices,
            'owed'      => $owed,
            'overdue'   => $overdue,
            'contracts' => $contracts,
            'tickets'   => $tickets,
            'documents' => $documents,
            'deals'     => $deals,
            'leads'     => $leads,
            'areas'     => $areas,
        ];
    }

    /** Areas not yet assigned to any customer — the dropdown for linking a router. */
    public static function unassignedAreas(): array
    {
        return Db::pdo()->query(
            'SELECT id, name, host FROM network_areas WHERE contact_id IS NULL AND active = 1 ORDER BY name'
        )->fetchAll();
    }

    /** Attach (or with NULL detach) a router area to a customer site. */
    public static function linkArea(int $areaId, ?int $contactId): void
    {
        Db::pdo()->prepare('UPDATE network_areas SET contact_id = ? WHERE id = ?')
            ->execute([$contactId, $areaId]);
    }

    /**
     * A customer typed in by hand — no gestionale row, no won deal, just staff
     * who knows this customer exists. Same identity rules as the import: the
     * code (when given) must be free, the VAT is normalised, landlines keep
     * their trunk zero.
     *
     * @return array{ok: bool, id: int, error: ?string}
     */
    public static function createManual(array $d, ?int $userId = null): array
    {
        $pdo  = Db::pdo();
        $code = trim((string)($d['customer_code'] ?? '')) ?: null;
        if ($code !== null) {
            $q = $pdo->prepare('SELECT id FROM contacts WHERE customer_code = ?');
            $q->execute([$code]);
            if (($dupId = (int)($q->fetchColumn() ?: 0)) > 0) {
                return ['ok' => false, 'id' => $dupId, 'error' => 'code_taken'];
            }
        }

        $first = trim((string)($d['first_name'] ?? ''));
        $last  = trim((string)($d['last_name'] ?? ''));
        $co    = trim((string)($d['company'] ?? ''));
        if ($first === '' && $last === '' && $co === '') {
            return ['ok' => false, 'id' => 0, 'error' => 'no_name'];
        }

        $id = Contacts::create([
            'first_name' => $first, 'last_name' => $last,
            'name'    => ($first === '' && $last === '') ? $co : null,
            'company' => $co ?: null,
            'phone'   => CustomerImport::phone((string)($d['phone'] ?? '')) ?: null,
            'email'   => trim((string)($d['email'] ?? '')) ?: null,
            'source'  => 'manual',
            'notes'   => trim((string)($d['notes'] ?? '')) ?: null,
        ]);

        $pdo->prepare(
            'UPDATE contacts SET customer_code = ?, vat_number = ?, is_customer = 1,
                    customer_since = NOW(), phone2 = ?, address = ?, city = ?, province = ?, zip = ?,
                    contract_expiry = ?, gestionale_agent = ?
             WHERE id = ?'
        )->execute([
            $code,
            VatLock::normalize((string)($d['vat_number'] ?? '')) ?: null,
            CustomerImport::phone((string)($d['phone2'] ?? '')) ?: null,
            trim((string)($d['address'] ?? '')) ?: null,
            trim((string)($d['city'] ?? '')) ?: null,
            mb_substr(trim((string)($d['province'] ?? '')), 0, 8) ?: null,
            trim((string)($d['zip'] ?? '')) ?: null,
            trim((string)($d['contract_expiry'] ?? '')) ?: null,
            trim((string)($d['gestionale_agent'] ?? '')) ?: null,
            $id,
        ]);

        \Glue\Event\Log::write('crm', 'customer_created_manual', 'contact', $id, ['by' => $userId]);
        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    // ---- becoming a customer ------------------------------------------------

    /**
     * A won deal makes its contact a customer. Called from Deals::moveStage on
     * the won transition; idempotent, and customer_since keeps the FIRST win.
     * The lead's VAT number travels onto the contact when the contact has none —
     * that is what later ties this customer to their Sibill invoices.
     */
    public static function markFromDeal(int $dealId): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT contact_id, lead_id FROM deals WHERE id = ?');
        $stmt->execute([$dealId]);
        $deal = $stmt->fetch();
        $contactId = $deal ? (int)($deal['contact_id'] ?? 0) : 0;
        if ($contactId <= 0) {
            return;
        }

        $vat = null;
        if (!empty($deal['lead_id'])) {
            $q = $pdo->prepare('SELECT vat_number FROM leads WHERE id = ?');
            $q->execute([(int)$deal['lead_id']]);
            $vat = VatLock::normalize((string)($q->fetchColumn() ?: '')) ?: null;
        }

        $pdo->prepare(
            "UPDATE contacts
                SET is_customer = 1,
                    customer_since = COALESCE(customer_since, NOW()),
                    vat_number = COALESCE(NULLIF(vat_number, ''), ?)
              WHERE id = ?"
        )->execute([$vat, $contactId]);
    }
}
