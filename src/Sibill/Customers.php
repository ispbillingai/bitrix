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

    /** One customer with the same aggregates the list shows. */
    public static function get(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT c.*, ct.name AS contact_name,
                    COALESCE(SUM(i.pay_state <> 'paid'), 0)                            AS open_count,
                    COALESCE(SUM(i.pay_state <> 'paid' AND i.due_date IS NOT NULL
                                 AND i.due_date < CURDATE()), 0)                       AS overdue_count,
                    COALESCE(SUM(CASE WHEN i.pay_state <> 'paid' THEN i.open_amount ELSE 0 END), 0) AS owed,
                    MIN(CASE WHEN i.pay_state <> 'paid' THEN i.due_date END)            AS oldest_due
             FROM sibill_customers c
             LEFT JOIN contacts ct        ON ct.id = c.contact_id
             LEFT JOIN sibill_invoices i  ON i.counterpart_vat = c.vat_number
             WHERE c.id = ?
             GROUP BY c.id"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** A customer's invoices, unpaid first, each with its instalment counts. */
    public static function invoices(int $id, bool $openOnly = false): array
    {
        $c = self::get($id);
        if ($c === null) {
            return [];
        }
        $sql = 'SELECT * FROM sibill_invoices WHERE counterpart_vat = ?'
            . ($openOnly ? " AND pay_state <> 'paid'" : '')
            . " ORDER BY (pay_state <> 'paid') DESC, due_date IS NULL, due_date ASC, creation_date DESC";
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
     * invoice due on or after it.
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
        $innerWhere = "pay_state <> 'paid' AND due_date IS NOT NULL
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
     */
    public static function remind(int $id, bool $sendNow = false): int
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
        // An automatic chase only ever talks about invoices inside the start-date
        // window; a human pressing "remind now" gets the customer's full open
        // balance, because they can see the invoice list and chose to send it.
        if (!$sendNow && ($from = self::chaseFromDate()) !== '') {
            $open = array_values(array_filter($open, static fn($i) =>
                $i['due_date'] !== null && $i['due_date'] >= $from));
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
        $dedupe = $sendNow
            ? 'chase:' . $id . ':' . date('Y-m-d H:i:s')
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
