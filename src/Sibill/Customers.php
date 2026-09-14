<?php
declare(strict_types=1);

namespace Glue\Sibill;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Glue\Settings;
use PDO;
use Throwable;

/**
 * The customer-level answer: who owes us money, and chasing them for it.
 *
 * One row per counterpart (keyed by VAT), rolled up from the invoice mirror.
 * The roll-up itself is never stored — it is a GROUP BY over sibill_invoices, so
 * it cannot drift out of step with the invoices it describes. What IS stored is
 * the part Sibill does not have: a phone number, an email address, and whether
 * this customer should be chased at all.
 *
 * That gap is the whole reason this class exists. Sibill holds no contact
 * details for a counterpart — not even its own courtesy-copy endpoint does, it
 * asks the caller for an address. So a customer can be listed as owing €14,000
 * and still be unreachable until someone types in a number. The list shows that
 * plainly rather than quietly skipping them.
 */
final class Customers
{
    /** Chasing is opt-in and never retroactive on first switch-on. See runChaseIfDue(). */
    private const RULE_KEY = 'invoice_overdue';

    // ---- building the list --------------------------------------------------

    /**
     * Refresh the customer rows from the invoice mirror. Called at the end of
     * every invoice sync.
     *
     * Staff-owned fields (phone, email, lang, chase_enabled, snooze, notes) are
     * never overwritten — a blank phone/email is filled from a matched CRM
     * contact, and that is the extent of it.
     */
    public static function rebuild(): int
    {
        $pdo = Db::pdo();
        $rows = $pdo->query(
            "SELECT counterpart_vat AS vat,
                    SUBSTRING_INDEX(GROUP_CONCAT(counterpart_name ORDER BY creation_date DESC SEPARATOR '\\n'), '\\n', 1) AS name,
                    MAX(contact_id) AS contact_id
             FROM sibill_invoices
             WHERE counterpart_vat IS NOT NULL AND counterpart_vat <> ''
             GROUP BY counterpart_vat"
        )->fetchAll();

        $ins = $pdo->prepare(
            'INSERT INTO sibill_customers (vat_number, name, contact_id, lang)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), contact_id = VALUES(contact_id)'
        );
        $fill = $pdo->prepare(
            "UPDATE sibill_customers c
                JOIN contacts ct ON ct.id = c.contact_id
                SET c.phone = COALESCE(NULLIF(c.phone, ''), NULLIF(ct.phone, '')),
                    c.email = COALESCE(NULLIF(c.email, ''), NULLIF(ct.email, ''))
              WHERE c.id = ? AND c.contact_id IS NOT NULL"
        );

        $lang = (string)Config::get('app.default_lang', 'it');
        $n = 0;
        foreach ($rows as $r) {
            $ins->execute([$r['vat'], (string)($r['name'] ?? $r['vat']), $r['contact_id'], $lang]);
            $id = (int)$pdo->lastInsertId();
            if ($id === 0) {
                $q = $pdo->prepare('SELECT id FROM sibill_customers WHERE vat_number = ?');
                $q->execute([$r['vat']]);
                $id = (int)$q->fetchColumn();
            }
            if ($id > 0) {
                $fill->execute([$id]);
            }
            $n++;
        }
        return $n;
    }

    /**
     * The debtor list. Aggregates are computed here, not stored.
     *
     * @param array $f state: owing|overdue|reachable|unreachable|all, q: string
     */
    public static function search(array $f = [], int $limit = 500): array
    {
        $state = (string)($f['state'] ?? 'overdue');
        $where = [];
        $args  = [];

        // 'all' still means "has invoices"; the HAVING below does the filtering
        // on the aggregate, which a WHERE cannot see.
        $having = match ($state) {
            'overdue'     => 'agg.overdue_count > 0',
            'owing'       => 'agg.open_count > 0',
            'unreachable' => "agg.overdue_count > 0 AND (c.phone IS NULL OR c.phone = '') AND (c.email IS NULL OR c.email = '')",
            'reachable'   => "agg.overdue_count > 0 AND ((c.phone IS NOT NULL AND c.phone <> '') OR (c.email IS NOT NULL AND c.email <> ''))",
            'settled'     => 'agg.open_count = 0',
            default       => '1=1',
        };
        if (trim((string)($f['q'] ?? '')) !== '') {
            $q = '%' . trim((string)$f['q']) . '%';
            $where[] = '(c.name LIKE ? OR c.vat_number LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
            array_push($args, $q, $q, $q, $q);
        }

        $sql = "SELECT c.*, ct.name AS contact_name,
                       agg.open_count, agg.overdue_count, agg.owed, agg.oldest_due, agg.invoice_count
                FROM sibill_customers c
                LEFT JOIN contacts ct ON ct.id = c.contact_id
                JOIN (
                    SELECT counterpart_vat,
                           COUNT(*)                                                    AS invoice_count,
                           SUM(pay_state <> 'paid')                                    AS open_count,
                           SUM(pay_state <> 'paid' AND due_date IS NOT NULL
                               AND due_date < CURDATE())                               AS overdue_count,
                           COALESCE(SUM(CASE WHEN pay_state <> 'paid' THEN open_amount ELSE 0 END), 0) AS owed,
                           MIN(CASE WHEN pay_state <> 'paid' THEN due_date END)        AS oldest_due
                    FROM sibill_invoices
                    WHERE counterpart_vat IS NOT NULL AND counterpart_vat <> ''
                      AND " . Invoices::debtOnly() . "
                    GROUP BY counterpart_vat
                ) agg ON agg.counterpart_vat = c.vat_number"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' HAVING ' . $having
            . ' ORDER BY agg.owed DESC, agg.oldest_due ASC
                LIMIT ' . max(1, min(2000, $limit));

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /**
     * One customer with the same aggregates the list shows, plus what we hold in
     * credit notes for them — not netted off the debt (nothing in the mirror
     * says which invoice a credit note cancels), but shown on the card so
     * whoever is about to chase can see it.
     */
    public static function get(int $id): ?array
    {
        $debt = Invoices::debtOnly('i.');
        $stmt = Db::pdo()->prepare(
            "SELECT c.*, ct.name AS contact_name,
                    COALESCE(SUM($debt AND i.pay_state <> 'paid'), 0)                  AS open_count,
                    COALESCE(SUM($debt AND i.pay_state <> 'paid' AND i.due_date IS NOT NULL
                                 AND i.due_date < CURDATE()), 0)                       AS overdue_count,
                    COALESCE(SUM(CASE WHEN $debt AND i.pay_state <> 'paid'
                                      THEN i.open_amount ELSE 0 END), 0)               AS owed,
                    MIN(CASE WHEN $debt AND i.pay_state <> 'paid' THEN i.due_date END)  AS oldest_due,
                    COALESCE(SUM(i.doc_type = 'CREDIT_NOTE'), 0)                       AS credit_count,
                    COALESCE(SUM(CASE WHEN i.doc_type = 'CREDIT_NOTE'
                                      THEN i.gross_amount ELSE 0 END), 0)              AS credit_total
             FROM sibill_customers c
             LEFT JOIN contacts ct        ON ct.id = c.contact_id
             LEFT JOIN sibill_invoices i  ON i.counterpart_vat = c.vat_number
             WHERE c.id = ?
             GROUP BY c.id"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * A customer's documents, unpaid first, each with its instalment counts.
     *
     * $openOnly means "still owed to us", which is what a chase is built from —
     * so it drops credit notes as well as settled invoices. The full list keeps
     * them (sorted to the bottom) because they are part of the picture when
     * someone is deciding what to chase for.
     */
    public static function invoices(int $id, bool $openOnly = false): array
    {
        $c = self::get($id);
        if ($c === null) {
            return [];
        }
        $sql = 'SELECT * FROM sibill_invoices WHERE counterpart_vat = ?'
            . ($openOnly ? " AND pay_state <> 'paid' AND " . Invoices::debtOnly() : '')
            . " ORDER BY (doc_type = 'CREDIT_NOTE') ASC, (pay_state <> 'paid') DESC,
                        due_date IS NULL, due_date ASC, creation_date DESC";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([$c['vat_number']]);
        return $stmt->fetchAll();
    }

    /** Headline counts for the page. */
    public static function summary(): array
    {
        $row = Db::pdo()->query(
            "SELECT
                COUNT(*) AS debtors,
                SUM(reachable) AS reachable,
                COALESCE(SUM(owed), 0) AS owed
             FROM (
                SELECT c.id,
                       ((c.phone IS NOT NULL AND c.phone <> '') OR (c.email IS NOT NULL AND c.email <> '')) AS reachable,
                       SUM(CASE WHEN i.pay_state <> 'paid' THEN i.open_amount ELSE 0 END) AS owed,
                       SUM(i.pay_state <> 'paid' AND i.due_date < CURDATE()) AS overdue_count
                FROM sibill_customers c
                JOIN sibill_invoices i ON i.counterpart_vat = c.vat_number
                                      AND " . Invoices::debtOnly('i.') . "
                GROUP BY c.id
                HAVING overdue_count > 0
             ) d"
        )->fetch() ?: [];
        return [
            'debtors'   => (int)($row['debtors'] ?? 0),
            'reachable' => (int)($row['reachable'] ?? 0),
            'owed'      => (float)($row['owed'] ?? 0),
        ];
    }

    // ---- staff edits --------------------------------------------------------

    /**
     * Bulk-attach contact details to customers by VAT number.
     *
     * This is the answer to "Sibill has no phone/email": the invoice already
     * tells us the VAT, so the customer's identity is never in doubt — only the
     * way to reach them is missing. Paste a list the client already has (from
     * the old system, the accountant, a spreadsheet) as one row per line:
     *
     *     VAT<TAB>phone<TAB>email        (also accepts ; or , separators)
     *     IT10125441211  3391234567  ufficio@cliente.it
     *
     * Column order is a hint, not a rule: the email is found by its "@", and the
     * VAT by matching it against the customers we actually hold (which also tells
     * phone and VAT apart when both are just digits). Phones are normalised to
     * +39… so they can be messaged as-is.
     *
     * Unlike the sync — which only fills blanks — an import is a deliberate human
     * act, so a provided value overwrites. A blank column is left untouched.
     *
     * @return array{matched:int,phone_set:int,email_set:int,lines:int,skipped:int,unmatched:string[]}
     */
    public static function importContacts(string $raw): array
    {
        $pdo = Db::pdo();

        // Every VAT we hold, and its variants (with/without the IT prefix), mapped
        // to the customer row — so an input token can be recognised as "a VAT we
        // know" rather than mistaken for a phone number.
        $map = [];
        foreach ($pdo->query('SELECT id, vat_number FROM sibill_customers')->fetchAll() as $r) {
            foreach (Invoices::vatKeys((string)$r['vat_number']) as $k) {
                $map[$k] = (int)$r['id'];
            }
        }

        $out = ['matched' => 0, 'phone_set' => 0, 'email_set' => 0,
                'lines' => 0, 'skipped' => 0, 'unmatched' => []];

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach (array_slice($lines, 0, 5000) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $out['lines']++;

            // Spreadsheet paste is tab-separated; fall back to ; then , so a
            // hand-typed list works too.
            $sep = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : ',');
            $fields = array_map('trim', explode($sep, $line));

            $email = ''; $custId = 0; $vatIdx = -1;
            foreach ($fields as $i => $f) {
                if ($email === '' && str_contains($f, '@')) {
                    $email = $f;
                    continue;
                }
                if ($custId === 0) {
                    foreach (Invoices::vatKeys($f) as $k) {
                        if (isset($map[$k])) {
                            $custId = $map[$k];
                            $vatIdx = $i;
                            break;
                        }
                    }
                }
            }
            // Phone: a leftover field with enough digits (not the VAT, not the email).
            $phone = '';
            foreach ($fields as $i => $f) {
                if ($i === $vatIdx || str_contains($f, '@')) {
                    continue;
                }
                if (strlen(preg_replace('/\D+/', '', $f) ?? '') >= 6) {
                    $phone = $f;
                    break;
                }
            }

            if ($custId === 0) {
                // No VAT we recognise. Might be a header row, or a customer with no
                // outstanding invoices; report it so nothing is silently dropped.
                if ($email !== '' || $phone !== '') {
                    $vatShown = $vatIdx >= 0 ? $fields[$vatIdx] : ($fields[0] ?? '');
                    if (count($out['unmatched']) < 100) {
                        $out['unmatched'][] = $vatShown !== '' ? $vatShown : $line;
                    }
                } else {
                    $out['skipped']++;
                }
                continue;
            }

            $d = [];
            if ($phone !== '') {
                $d['phone'] = \Glue\Notify\Notifier::normalizePhone($phone);
            }
            if ($email !== '') {
                $d['email'] = $email;
            }
            if (!$d) {
                $out['skipped']++;
                continue;
            }
            self::saveDetails($custId, $d);
            $out['matched']++;
            if (isset($d['phone']) && $d['phone'] !== '') {
                $out['phone_set']++;
            }
            if (isset($d['email'])) {
                $out['email_set']++;
            }
        }

        Log::write('sibill', 'import_contacts', null, null, [
            'matched' => $out['matched'], 'phone_set' => $out['phone_set'],
            'email_set' => $out['email_set'], 'unmatched' => count($out['unmatched']),
        ]);
        return $out;
    }

    /** Save the bits Sibill cannot give us. Only the keys present are touched. */
    public static function saveDetails(int $id, array $d): void
    {
        $cols = [];
        $args = [];
        foreach (['phone', 'email', 'lang', 'notes'] as $k) {
            if (array_key_exists($k, $d)) {
                $cols[] = "$k = ?";
                $args[] = trim((string)$d[$k]) !== '' ? trim((string)$d[$k]) : null;
            }
        }
        if (array_key_exists('chase_enabled', $d)) {
            $cols[] = 'chase_enabled = ?';
            $args[] = !empty($d['chase_enabled']) ? 1 : 0;
        }
        if (array_key_exists('snooze_until', $d)) {
            $cols[] = 'snooze_until = ?';
            $s = trim((string)$d['snooze_until']);
            $args[] = $s !== '' ? date('Y-m-d', strtotime($s) ?: time()) : null;
        }
        if (!$cols) {
            return;
        }
        $args[] = $id;
        Db::pdo()->prepare('UPDATE sibill_customers SET ' . implode(', ', $cols) . ' WHERE id = ?')->execute($args);
    }

    /** Keep one invoice out of automatic + whole-customer reminders (or put it back). */
    public static function setInvoiceChase(int $invoiceId, bool $excluded): void
    {
        Db::pdo()->prepare('UPDATE sibill_invoices SET chase_excluded = ? WHERE id = ?')
            ->execute([$excluded ? 1 : 0, $invoiceId]);
    }

    // ---- chasing ------------------------------------------------------------

    /**
     * The "start fresh" line. When set, automatic chasing ignores anything due
     * before this date — so switching chasing on today does not fire off a
     * reminder about an invoice that fell due in 2023 and may well have been
     * paid without ever being reconciled in Sibill. Blank = chase everything.
     */
    public static function chaseFromDate(): string
    {
        $v = trim((string)Config::get('sibill.chase_from_date', ''));
        if ($v === '') {
            return '';
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    /**
     * Customers due a payment reminder right now.
     *
     * Deliberately narrow: a customer must be overdue by more than a grace
     * period, owe more than a floor amount (nobody should get a WhatsApp about
     * €3.20), be reachable, not be snoozed or excluded, not have been chased
     * within the cadence, and — if a start date is set — be overdue on an
     * invoice due on or after it. Credit notes are not a debt and never enter
     * the aggregate; see Invoices::debtOnly().
     */
    public static function due(int $limit): array
    {
        $everyDays = max(1, (int)Config::get('sibill.chase_every_days', 7));
        $minLate   = max(0, (int)Config::get('sibill.chase_min_days_late', 7));
        $minAmount = (float)Config::get('sibill.chase_min_amount', 20);
        $fromDate  = self::chaseFromDate();

        // The cutoff lands inside the aggregate: a customer's owed/count/oldest
        // must be computed from in-window invoices only, or the message would
        // still quote the old backlog even when it did not trigger the chase.
        $innerWhere = "pay_state <> 'paid' AND chase_excluded = 0 AND due_date IS NOT NULL
                       AND " . Invoices::debtOnly() . "
                       AND due_date < (CURDATE() - INTERVAL ? DAY)
                       AND counterpart_vat IS NOT NULL AND counterpart_vat <> ''";
        $params = [$minLate];
        if ($fromDate !== '') {
            $innerWhere .= ' AND due_date >= ?';
            $params[]    = $fromDate;
        }
        $params[] = $everyDays;
        $params[] = $minAmount;

        $stmt = Db::pdo()->prepare(
            "SELECT c.*, agg.overdue_count, agg.owed, agg.oldest_due, agg.numbers
             FROM sibill_customers c
             JOIN (
                SELECT counterpart_vat,
                       COUNT(*) AS overdue_count,
                       SUM(open_amount) AS owed,
                       MIN(due_date) AS oldest_due,
                       SUBSTRING_INDEX(GROUP_CONCAT(number ORDER BY due_date ASC SEPARATOR ', '), ', ', 5) AS numbers
                FROM sibill_invoices
                WHERE $innerWhere
                GROUP BY counterpart_vat
             ) agg ON agg.counterpart_vat = c.vat_number
             WHERE c.chase_enabled = 1
               AND (c.snooze_until IS NULL OR c.snooze_until < CURDATE())
               AND ((c.phone IS NOT NULL AND c.phone <> '') OR (c.email IS NOT NULL AND c.email <> ''))
               AND (c.last_reminded_at IS NULL OR c.last_reminded_at < (NOW() - INTERVAL ? DAY))
               AND agg.owed >= ?
             ORDER BY agg.owed DESC
             LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Queue one payment reminder. Returns the reminder id, or 0 if there was
     * nothing to send to.
     *
     * The message is queued rather than sent here: the existing dispatcher owns
     * the WhatsApp spacing, the outbox record and the retry, and a chase run of
     * twenty would otherwise sit in a loop sleeping through the rate limit.
     * $sendNow forces immediate delivery for the "remind now" button.
     *
     * $invoiceIds targets specific invoices — the per-invoice "remind this one"
     * button. A human picked them, so exclusions and the start-date line don't
     * apply. With null (the default) the message covers the customer's whole open
     * balance minus any invoices marked excluded from chasing.
     *
     * Either way the set comes from invoices($id, true), which holds unpaid
     * invoices only — a credit note is a refund and can never be chased, not
     * even by pressing the button.
     */
    public static function remind(int $id, bool $sendNow = false, ?array $invoiceIds = null): int
    {
        $c = self::get($id);
        if ($c === null) {
            return 0;
        }
        $phone = trim((string)($c['phone'] ?? ''));
        $email = trim((string)($c['email'] ?? ''));
        if ($phone === '' && $email === '') {
            return 0;
        }

        $open = self::invoices($id, true);
        if ($invoiceIds !== null) {
            // Targeted send: exactly the chosen invoice(s), whether or not they
            // are overdue yet — but still only ones that are actually owed.
            $want = array_map('intval', $invoiceIds);
            $open = array_values(array_filter($open, static fn($i) => in_array((int)$i['id'], $want, true)));
        } else {
            // Whole customer: never chase an invoice the operator has excluded.
            $open = array_values(array_filter($open, static fn($i) => (int)($i['chase_excluded'] ?? 0) === 0));
            // Automatic passes also stay within the start-date window; a manual
            // "remind now" covers the full (non-excluded) open balance.
            if (!$sendNow && ($from = self::chaseFromDate()) !== '') {
                $open = array_values(array_filter($open, static fn($i) =>
                    $i['due_date'] !== null && $i['due_date'] >= $from));
            }
        }
        if (!$open) {
            return 0;
        }
        $numbers = implode(', ', array_slice(array_map(
            static fn($i) => (string)($i['number'] ?? ''), $open
        ), 0, 5));
        $oldest = null;
        $owed = 0.0;
        foreach ($open as $i) {
            $owed += (float)$i['open_amount'];
            if ($i['due_date'] !== null && ($oldest === null || $i['due_date'] < $oldest)) {
                $oldest = $i['due_date'];
            }
        }

        // A SmallPay page for exactly this amount, when the setting asks for
        // one — '' when off or when SmallPay could not be reached, and the
        // reminder still goes, without that line ({?pay_link}…{/pay_link}).
        $payLink = self::payLink($c, $open, $owed);

        // Channel: whatever is configured, narrowed to what we can actually reach.
        $channel = (string)Config::get('sibill.chase_channel', 'both');
        if ($phone === '') {
            $channel = 'email';
        } elseif ($email === '') {
            $channel = 'whatsapp';
        }

        // The automatic pass dedupes to one chase per customer per day. A human
        // pressing "remind now" has decided otherwise, so their send gets a key
        // of its own rather than being swallowed by that day's entry.
        // Targeted sends key on the invoice(s) too, so chasing two invoices of the
        // same customer within the same second doesn't collide into one.
        $suffix = $invoiceIds !== null ? ':inv' . implode('-', array_map('intval', $invoiceIds)) : '';
        $dedupe = $sendNow
            ? 'chase:' . $id . $suffix . ':' . date('Y-m-d H:i:s')
            : 'chase:' . $id . ':' . date('Y-m-d');
        if (!$sendNow) {
            $seen = Db::pdo()->prepare('SELECT 1 FROM reminders WHERE dedupe_key = ? LIMIT 1');
            $seen->execute([$dedupe]);
            if ($seen->fetchColumn()) {
                return 0; // already chased today
            }
        }

        $sched = new Scheduler();
        $rid = $sched->enqueue([
            'entity_type'    => 'sibill_customer',
            'entity_id'      => $id,
            'rule_key'       => self::RULE_KEY,
            'recipient_type' => 'customer',
            'channel'        => $channel,
            'due_at'         => date('Y-m-d H:i:s'),
            'lang'           => $c['lang'] ?? null,
            // One chase per customer per day, whatever asks for it.
            'dedupe_key'     => $dedupe,
            'payload'        => [
                'customer_name' => $c['name'],
                'name'          => $c['name'],
                'count'         => (string)count($open),
                'total'         => number_format($owed, 2, ',', '.'),
                'invoices'      => $numbers,
                'oldest_due'    => $oldest !== null ? date('d/m/Y', strtotime($oldest)) : '',
                'days_late'     => $oldest !== null
                    ? (string)(int)((time() - strtotime($oldest)) / 86400) : '0',
                'pay_link'      => $payLink,
            ],
        ], false);

        if ($rid > 0) {
            Db::pdo()->prepare(
                'UPDATE sibill_customers SET last_reminded_at = NOW(), reminders_sent = reminders_sent + 1 WHERE id = ?'
            )->execute([$id]);
            if ($sendNow) {
                $sched->sendNow($rid);
            }
        }
        return $rid;
    }

    // ---- paying online from the reminder --------------------------------------

    /** How long chasing pauses after an online payment, for the books to catch up. */
    public const PAID_PAUSE_DAYS = 14;

    /** Reminders carry a SmallPay page when the setting asks for one and SmallPay is live. */
    public static function payLinkEnabled(): bool
    {
        return (bool)Config::get('sibill.chase_pay_link', false) && \Glue\Pay\SmallPay::enabled();
    }

    /**
     * The cashier URL to put in a reminder for these invoices: a one-off
     * SmallPay position for exactly the chased amount, by card. The customer
     * row keeps the position it last issued — while that one is unpaid and for
     * the same figure the link is reused (the same page for the same debt);
     * otherwise it is cancelled and a new one opened, so the link in the
     * newest message always matches the amount written next to it. Returns ''
     * when the feature is off or SmallPay could not be reached: the reminder
     * still goes, without that line.
     */
    private static function payLink(array $c, array $open, float $owed): string
    {
        if (!self::payLinkEnabled()) {
            return '';
        }
        $cents = (int)round($owed * 100);
        if ($cents <= 0) {
            return '';
        }
        $prev = !empty($c['pay_contract_id']) ? \Glue\Pay\Contracts::find((int)$c['pay_contract_id']) : null;
        if ($prev !== null) {
            if ((string)$prev['status'] === 'awaiting_customer'
                && (int)$prev['first_amount_cents'] === $cents
                && trim((string)($prev['checkout_url'] ?? '')) !== '') {
                return trim((string)$prev['checkout_url']);
            }
            if (in_array((string)$prev['status'], ['draft', 'awaiting_customer'], true)) {
                try {
                    \Glue\Pay\Contracts::cancel((int)$prev['id']);
                } catch (Throwable $e) {
                    Log::write('sibill', 'chase_link_cancel_failed', 'payment_contract', (int)$prev['id'],
                        ['error' => $e->getMessage()]);
                }
            }
        }

        $numbers = array_values(array_filter(array_map(
            static fn($i) => trim((string)($i['number'] ?? '')), $open
        ), 'strlen'));
        $it   = substr((string)($c['lang'] ?? 'it'), 0, 2) !== 'en';
        $desc = (count($numbers) === 1 ? ($it ? 'Fattura ' : 'Invoice ') : ($it ? 'Fatture ' : 'Invoices '))
            . implode(', ', array_slice($numbers, 0, 5));
        try {
            $contract = \Glue\Pay\Contracts::open([
                'kind'           => 'one_off',
                'gateway'        => \Glue\Pay\SmallPay::GW_CARD, // a debt is paid, not a mandate signed
                'contact_id'     => $c['contact_id'] ?? null,
                'description'    => mb_substr(trim($desc), 0, 120),
                'amount_cents'   => $cents,
                'customer_name'  => (string)$c['name'],
                'customer_phone' => (string)($c['phone'] ?? ''),
                'customer_email' => (string)($c['email'] ?? ''),
                'lang'           => $c['lang'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::write('sibill', 'chase_link_failed', 'sibill_customer', (int)$c['id'], ['error' => $e->getMessage()]);
            return '';
        }
        Db::pdo()->prepare('UPDATE sibill_customers SET pay_contract_id = ? WHERE id = ?')
            ->execute([(int)$contract['id'], (int)$c['id']]);
        Log::write('sibill', 'chase_link_opened', 'sibill_customer', (int)$c['id'],
            ['contract' => (int)$contract['id'], 'cents' => $cents]);
        return trim((string)($contract['checkout_url'] ?? ''));
    }

    /**
     * A reminder's SmallPay position was paid (Pay\Contracts hands over the
     * status change). The customer is thanked, every administrator is told to
     * record the payment in the gestionale and in Sibill — Sibill cannot learn
     * of it any other way — and reminders to this customer pause meanwhile, so
     * the next pass does not chase money already in the bank. Returns false
     * when the contract is not a reminder's, so the caller treats it as an
     * ordinary sale.
     */
    public static function onChasePaid(array $contract): bool
    {
        $q = Db::pdo()->prepare('SELECT * FROM sibill_customers WHERE pay_contract_id = ? LIMIT 1');
        $q->execute([(int)$contract['id']]);
        $c = $q->fetch();
        if (!$c) {
            return false;
        }
        $cid  = (int)$c['id'];
        $days = self::PAID_PAUSE_DAYS;
        Db::pdo()->prepare(
            'UPDATE sibill_customers
                SET snooze_until = GREATEST(COALESCE(snooze_until, CURDATE()), DATE_ADD(CURDATE(), INTERVAL ? DAY))
              WHERE id = ?'
        )->execute([$days, $cid]);

        $amount = \Glue\Pay\Contracts::money($contract);
        $desc   = (string)$contract['description'];
        $sched  = new Scheduler();
        $phone  = trim((string)($contract['customer_phone'] ?? '')) ?: trim((string)($c['phone'] ?? ''));
        $email  = trim((string)($contract['customer_email'] ?? '')) ?: trim((string)($c['email'] ?? ''));
        if ($phone !== '' || $email !== '') {
            $sched->enqueue([
                'entity_type'    => 'payment_contract',
                'entity_id'      => (int)$contract['id'],
                'rule_key'       => 'invoice_paid',
                'recipient_type' => 'customer',
                'channel'        => $phone !== '' && $email !== '' ? 'both' : ($phone !== '' ? 'whatsapp' : 'email'),
                'due_at'         => date('Y-m-d H:i:s'),
                'lang'           => $c['lang'] ?? null,
                'dedupe_key'     => 'pay:invoice_paid:' . (int)$contract['id'],
                'payload'        => [
                    'name'           => (string)$c['name'],
                    'customer_phone' => $phone,
                    'customer_email' => $email,
                    'amount'         => $amount,
                    'description'    => $desc,
                ],
            ]);
        }
        // Same queue, same addressing as the other administrator alerts: the
        // payload carries each admin's own phone and email.
        $link = Config::appBaseUrl() . '/dashboard.php?tab=invoices&c=' . $cid;
        foreach (\Glue\Crm\LeadCustomers::admins() as $u) {
            $sched->enqueue([
                'entity_type'    => 'payment_contract',
                'entity_id'      => (int)$contract['id'],
                'rule_key'       => 'invoice_paid_admin',
                'recipient_type' => 'agent',
                'channel'        => \Glue\Crm\LeadCustomers::channelFor($u),
                'due_at'         => date('Y-m-d H:i:s'),
                'dedupe_key'     => 'pay:invoice_paid_admin:' . (int)$contract['id'] . ':' . (int)$u['id'],
                'payload'        => [
                    'name'             => trim((string)($u['full_name'] ?? '')) ?: (string)$u['username'],
                    'agent_phone'      => (string)($u['phone'] ?? ''),
                    'agent_email'      => (string)($u['email'] ?? ''),
                    'customer_name'    => (string)$c['name'],
                    'customer_html'    => htmlspecialchars((string)$c['name'], ENT_QUOTES),
                    'amount'           => $amount,
                    'description'      => $desc,
                    'description_html' => htmlspecialchars($desc, ENT_QUOTES),
                    'days'             => (string)$days,
                    'link'             => $link,
                ],
            ]);
        }
        Log::write('sibill', 'chase_paid_online', 'sibill_customer', $cid, [
            'contract' => (int)$contract['id'], 'amount' => $amount, 'paused_days' => $days,
        ]);
        return true;
    }

    /**
     * The scheduler's chase pass. Off unless switched on, rate-limited by its own
     * cadence, and confined to working hours — a debt-collection WhatsApp at 3am
     * is worse than no WhatsApp.
     */
    public static function runChaseIfDue(): ?array
    {
        if (!(bool)Config::get('sibill.chase_enabled', false) || !Client::configured()) {
            return null;
        }
        $from = (int)Config::get('sibill.chase_hour_from', 9);
        $to   = (int)Config::get('sibill.chase_hour_to', 18);
        $hour = (int)date('G');
        if ($hour < $from || $hour >= $to) {
            return null;
        }
        // One pass an hour is plenty; the per-customer cadence does the real work.
        $last = (string)Settings::get('sibill.last_chase_at', '');
        if ($last !== '' && (time() - (strtotime($last) ?: 0)) < 3600) {
            return null;
        }
        Settings::set('sibill.last_chase_at', date('Y-m-d H:i:s'));

        try {
            $max = max(1, (int)Config::get('sibill.chase_max_per_run', 15));
            $queued = 0;
            foreach (self::due($max) as $c) {
                if (self::remind((int)$c['id']) > 0) {
                    $queued++;
                }
            }
            $out = ['queued' => $queued];
            if ($queued > 0) {
                Log::write('sibill', 'chase', null, null, $out);
            }
            return $out;
        } catch (Throwable $e) {
            Log::write('sibill', 'chase_error', null, null, ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }
}
