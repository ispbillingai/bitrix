<?php
declare(strict_types=1);

namespace Glue\Commission;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Throwable;

/**
 * Commission statements (conteggi provvigioni) for partners and agents.
 *
 * "The secretary selects the partner's area and uploads a calculation of €900;
 *  the partner receives it, submits the invoice, and the secretary processes
 *  the payment. The partner area shows the history of commissions and
 *  invoices, paid and unpaid."
 *
 *   sent      the office filed it; the payee is asked for an invoice
 *   invoiced  the payee (or the office for them) attached the invoice
 *   paid      the office paid it; the payee is told
 *   cancelled withdrawn before payment
 * An invoice the office sends back returns the statement to 'sent' with the
 * reason on it, for the payee to fix and send again.
 *
 * A partner statement can cover the automatic accruals the CRM makes for
 * their won deals (partner_accruals). They are tied to it and follow it —
 * paid when it is paid, released when it is cancelled — so they stop reading
 * "pending" beside a commission that has been settled.
 *
 * Ownership is checked in here, never trusted from the caller: an invoice from
 * a payee names the payee, and a statement belonging to someone else is
 * refused as not found.
 */
final class Statements
{
    public const PAYEE_TYPES = ['partner', 'agent'];
    public const STATUSES    = ['sent', 'invoiced', 'paid', 'cancelled'];

    private const MAX_BYTES = 15728640; // 15 MB
    /** A calculation is a PDF or a spreadsheet; an invoice a PDF, the e-invoice XML (signed .p7m too) or a photo. */
    private const EXT = ['pdf', 'xml', 'p7m', 'jpg', 'jpeg', 'png', 'webp', 'heic',
                         'xls', 'xlsx', 'csv', 'ods', 'doc', 'docx', 'odt', 'txt', 'zip'];
    /** Served inline (a browser shows them safely); everything else downloads. */
    private const INLINE = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                            'png' => 'image/png', 'webp' => 'image/webp'];

    // ---- the office files a statement ------------------------------------------------

    /**
     * @param array $d payee_type, payee_id, title, period, notes, amount ("900", "900,00",
     *                 "1.234,56"), accrual_ids (partner only)
     * @param array|null $file the calculation (optional)
     * @return array{ok:bool, id:int, error:?string}
     */
    public static function create(array $d, ?array $file, ?int $userId): array
    {
        $type  = (string)($d['payee_type'] ?? '');
        $pid   = (int)($d['payee_id'] ?? 0);
        $payee = in_array($type, self::PAYEE_TYPES, true) ? self::payee($type, $pid) : null;
        if (!$payee || (int)$payee['active'] !== 1) {
            return ['ok' => false, 'id' => 0, 'error' => 'no_payee'];
        }
        $amount = self::parseAmount((string)($d['amount'] ?? ''));
        if ($amount <= 0) {
            return ['ok' => false, 'id' => 0, 'error' => 'amount'];
        }
        $title = mb_substr(trim((string)($d['title'] ?? '')), 0, 190);
        if ($title === '') {
            return ['ok' => false, 'id' => 0, 'error' => 'title'];
        }
        $calc = self::storeFile($file, $err);
        if ($err !== null) {
            return ['ok' => false, 'id' => 0, 'error' => 'file_' . $err];
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO commission_statements
                    (payee_type, payee_id, title, period, notes, amount, currency, calc_path, calc_name, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'sent\', ?)'
            )->execute([
                $type, $pid, $title,
                mb_substr(trim((string)($d['period'] ?? '')), 0, 60) ?: null,
                trim((string)($d['notes'] ?? '')) ?: null,
                $amount, mb_substr((string)Config::get('crm.currency', 'EUR'), 0, 3),
                $calc['path'] ?? null, $calc['name'] ?? null, $userId ?: null,
            ]);
            $id = (int)$pdo->lastInsertId();

            // Only this partner's own, still-open, not-yet-covered accruals.
            $linked = 0;
            $ids = $type === 'partner'
                ? array_values(array_filter(array_map('intval', (array)($d['accrual_ids'] ?? [])), fn($i) => $i > 0))
                : [];
            if ($ids) {
                $in = implode(',', $ids);
                $linked = (int)$pdo->exec(
                    "UPDATE partner_accruals
                        SET statement_id = $id, status = 'approved', approved_at = COALESCE(approved_at, NOW())
                      WHERE id IN ($in) AND partner_id = $pid AND statement_id IS NULL AND status IN ('pending','approved')"
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($calc) {
                @unlink(self::uploadDir() . '/' . $calc['path']);
            }
            throw $e;
        }

        Log::write('commission', 'statement_created', 'commission', $id,
            ['payee' => "$type:$pid", 'amount' => $amount, 'accruals' => $linked, 'by' => $userId]);
        self::notifyPayee($id, 'commission_statement');
        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    // ---- the invoice ----------------------------------------------------------------

    /**
     * Attach the invoice. $by 'payee' — the partner or agent, who must be the one
     * named ($payeeType/$payeeId) — or 'office', for an invoice that arrived by
     * email. Accepted while the statement is unpaid; a second one replaces the
     * first (and after a rejection the same file may be kept with corrected data).
     * The office is told when the payee sends one.
     *
     * @param array $d invoice_number, invoice_date, invoice_amount, invoice_note
     * @return array{ok:bool, error:?string}
     */
    public static function submitInvoice(int $id, array $d, ?array $file, string $by,
                                         ?string $payeeType = null, ?int $payeeId = null, ?int $userId = null): array
    {
        $st = self::find($id);
        if (!$st) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($by !== 'office' && ($st['payee_type'] !== $payeeType || (int)$st['payee_id'] !== (int)$payeeId)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!in_array($st['status'], ['sent', 'invoiced'], true)) {
            return ['ok' => false, 'error' => 'closed'];
        }
        $number = mb_substr(trim((string)($d['invoice_number'] ?? '')), 0, 60);
        if ($number === '') {
            return ['ok' => false, 'error' => 'number'];
        }
        $f = self::storeFile($file, $err);
        if ($err !== null) {
            return ['ok' => false, 'error' => 'file_' . $err];
        }
        if (!$f && empty($st['invoice_path'])) {
            return ['ok' => false, 'error' => 'no_file'];
        }
        if ($f && !empty($st['invoice_path'])) {
            @unlink(self::uploadDir() . '/' . basename((string)$st['invoice_path']));
        }
        $date   = self::date((string)($d['invoice_date'] ?? '')) ?? date('Y-m-d');
        $amount = self::parseAmount((string)($d['invoice_amount'] ?? '')) ?: (float)$st['amount'];

        Db::pdo()->prepare(
            'UPDATE commission_statements
                SET status = \'invoiced\', invoice_path = COALESCE(?, invoice_path), invoice_name = COALESCE(?, invoice_name),
                    invoice_number = ?, invoice_date = ?, invoice_amount = ?, invoice_note = ?, invoice_by = ?,
                    invoiced_at = NOW(), rejected_note = NULL, rejected_at = NULL
              WHERE id = ?'
        )->execute([
            $f['path'] ?? null, $f['name'] ?? null, $number, $date, $amount,
            mb_substr(trim((string)($d['invoice_note'] ?? '')), 0, 500) ?: null,
            $by === 'office' ? 'office' : 'payee', $id,
        ]);
        Log::write('commission', 'statement_invoiced', 'commission', $id,
            ['by' => $by === 'office' ? 'office:' . $userId : $st['payee_type'] . ':' . $st['payee_id'], 'number' => $number, 'amount' => $amount]);
        if ($by !== 'office') {
            self::notifyOffice($id);
        }
        return ['ok' => true, 'error' => null];
    }

    /** The office sends the invoice back: it goes to 'sent' again with the reason, and the payee is told. */
    public static function reject(int $id, string $reason, ?int $userId): array
    {
        $st = self::find($id);
        if (!$st || $st['status'] !== 'invoiced') {
            return ['ok' => false, 'error' => 'state'];
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'reason'];
        }
        Db::pdo()->prepare('UPDATE commission_statements SET status = \'sent\', rejected_note = ?, rejected_at = NOW() WHERE id = ?')
            ->execute([$reason, $id]);
        Log::write('commission', 'statement_rejected', 'commission', $id, ['reason' => $reason, 'by' => $userId]);
        self::notifyPayee($id, 'commission_rejected');
        return ['ok' => true, 'error' => null];
    }

    // ---- payment --------------------------------------------------------------------

    /**
     * The office paid it. Only an invoiced statement: the invoice is the
     * accounting document the payment rests on (the office can upload it on
     * the payee's behalf first). Covered accruals are paid with it.
     *
     * @param array $d paid_on, paid_amount, payment_ref
     */
    public static function markPaid(int $id, array $d, ?int $userId): array
    {
        $st = self::find($id);
        if (!$st || $st['status'] !== 'invoiced') {
            return ['ok' => false, 'error' => 'state'];
        }
        $on     = self::date((string)($d['paid_on'] ?? '')) ?? date('Y-m-d');
        $amount = self::parseAmount((string)($d['paid_amount'] ?? '')) ?: (float)($st['invoice_amount'] ?? $st['amount']);
        $ref    = mb_substr(trim((string)($d['payment_ref'] ?? '')), 0, 190);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE commission_statements
                    SET status = \'paid\', paid_on = ?, paid_amount = ?, payment_ref = ?, paid_by = ?, paid_at = NOW()
                  WHERE id = ? AND status = \'invoiced\''
            )->execute([$on, $amount, $ref ?: null, $userId ?: null, $id]);
            $pdo->prepare("UPDATE partner_accruals SET status = 'paid', paid_at = NOW() WHERE statement_id = ?")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Log::write('commission', 'statement_paid', 'commission', $id, ['amount' => $amount, 'on' => $on, 'ref' => $ref, 'by' => $userId]);
        self::notifyPayee($id, 'commission_paid');
        return ['ok' => true, 'error' => null];
    }

    /** Withdraw an unpaid statement. Covered accruals are released (they stay approved, free for the next one). */
    public static function cancel(int $id, string $note, ?int $userId): array
    {
        $st = self::find($id);
        if (!$st || !in_array($st['status'], ['sent', 'invoiced'], true)) {
            return ['ok' => false, 'error' => 'state'];
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE commission_statements SET status = \'cancelled\', cancel_note = ?, cancelled_at = NOW() WHERE id = ?')
                ->execute([mb_substr(trim($note), 0, 255) ?: null, $id]);
            $pdo->prepare('UPDATE partner_accruals SET statement_id = NULL WHERE statement_id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Log::write('commission', 'statement_cancelled', 'commission', $id, ['note' => $note, 'by' => $userId]);
        return ['ok' => true, 'error' => null];
    }

    // ---- reading --------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $s = Db::pdo()->prepare('SELECT * FROM commission_statements WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** One payee's statements, newest first, each with the accruals it covers. */
    public static function forPayee(string $type, int $id): array
    {
        $s = Db::pdo()->prepare('SELECT * FROM commission_statements WHERE payee_type = ? AND payee_id = ? ORDER BY id DESC');
        $s->execute([$type, $id]);
        return self::withAccruals($s->fetchAll() ?: []);
    }

    /**
     * The office list: what is waiting to be paid first, then what is waiting
     * for an invoice, then the rest by date.
     * @param array $f status ?string, payee ?[type, id]
     */
    public static function all(array $f = []): array
    {
        $where = [];
        $args  = [];
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 's.status = ?';
            $args[]  = $f['status'];
        }
        if (!empty($f['payee']) && is_array($f['payee'])) {
            $where[] = 's.payee_type = ? AND s.payee_id = ?';
            $args[]  = (string)$f['payee'][0];
            $args[]  = (int)$f['payee'][1];
        }
        $s = Db::pdo()->prepare(
            "SELECT s.*, CASE WHEN s.payee_type = 'partner' THEN p.name
                              ELSE COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) END AS payee_name
               FROM commission_statements s
               LEFT JOIN partners p ON s.payee_type = 'partner' AND p.id = s.payee_id
               LEFT JOIN users u    ON s.payee_type = 'agent'   AND u.id = s.payee_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY (s.status = 'invoiced') DESC, (s.status = 'sent') DESC, s.id DESC LIMIT 500"
        );
        $s->execute($args);
        return self::withAccruals($s->fetchAll() ?: []);
    }

    private static function withAccruals(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $in = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $by = [];
        foreach (Db::pdo()->query(
            "SELECT a.statement_id, a.id, a.amount, a.status, l.customer_name, d.title AS deal_title
               FROM partner_accruals a LEFT JOIN leads l ON l.id = a.lead_id LEFT JOIN deals d ON d.id = a.deal_id
              WHERE a.statement_id IN ($in) ORDER BY a.id"
        ) as $a) {
            $by[(int)$a['statement_id']][] = $a;
        }
        foreach ($rows as &$r) {
            $r['accruals'] = $by[(int)$r['id']] ?? [];
        }
        unset($r);
        return $rows;
    }

    /**
     * Sums and counts per status. The paid figure is what was paid; the
     * awaiting figure is what the invoice says; the to-invoice figure is the
     * statement's own amount.
     * @return array{sent:float,invoiced:float,paid:float,cancelled:float,n_sent:int,n_invoiced:int,n_paid:int,n_cancelled:int}
     */
    public static function totals(?string $type = null, ?int $id = null): array
    {
        $w = $type !== null ? ' WHERE payee_type = ' . Db::pdo()->quote($type) . ' AND payee_id = ' . (int)$id : '';
        $out = ['sent' => 0.0, 'invoiced' => 0.0, 'paid' => 0.0, 'cancelled' => 0.0,
                'n_sent' => 0, 'n_invoiced' => 0, 'n_paid' => 0, 'n_cancelled' => 0];
        foreach (Db::pdo()->query(
            "SELECT status, COUNT(*) n,
                    COALESCE(SUM(CASE status WHEN 'paid' THEN COALESCE(paid_amount, invoice_amount, amount)
                                             WHEN 'invoiced' THEN COALESCE(invoice_amount, amount)
                                             ELSE amount END), 0) v
               FROM commission_statements$w GROUP BY status"
        ) as $r) {
            $out[(string)$r['status']] = round((float)$r['v'], 2);
            $out['n_' . $r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /** Invoices waiting for the office to pay — the number on the office's sidebar entry. */
    public static function countInvoiced(): int
    {
        return (int)Db::pdo()->query("SELECT COUNT(*) FROM commission_statements WHERE status = 'invoiced'")->fetchColumn();
    }

    /** Statements waiting for this payee's invoice — the number on their sidebar entry. */
    public static function countToInvoice(string $type, int $id): int
    {
        $s = Db::pdo()->prepare("SELECT COUNT(*) FROM commission_statements WHERE status = 'sent' AND payee_type = ? AND payee_id = ?");
        $s->execute([$type, $id]);
        return (int)$s->fetchColumn();
    }

    /** A partner's accruals no statement covers yet: what the office can put in the next one. */
    public static function openAccruals(int $partnerId): array
    {
        $s = Db::pdo()->prepare(
            "SELECT a.*, l.customer_name, d.title AS deal_title
               FROM partner_accruals a LEFT JOIN leads l ON l.id = a.lead_id LEFT JOIN deals d ON d.id = a.deal_id
              WHERE a.partner_id = ? AND a.statement_id IS NULL AND a.status IN ('pending','approved')
              ORDER BY a.id"
        );
        $s->execute([$partnerId]);
        return $s->fetchAll() ?: [];
    }

    // ---- payees ---------------------------------------------------------------------

    /** A partner or an agent: id, name, phone, email, active — or null. */
    public static function payee(string $type, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $sql = $type === 'partner'
            ? 'SELECT id, name, phone, email, active FROM partners WHERE id = ?'
            : "SELECT id, COALESCE(NULLIF(TRIM(full_name), ''), username) AS name, phone, email, active
                 FROM users WHERE id = ? AND role = 'agent'";
        $s = Db::pdo()->prepare($sql);
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** Everyone a statement can be for: active partners and active agents. */
    public static function payees(): array
    {
        $pdo = Db::pdo();
        return [
            'partner' => $pdo->query('SELECT id, name FROM partners WHERE active = 1 ORDER BY name')->fetchAll() ?: [],
            'agent'   => $pdo->query("SELECT id, COALESCE(NULLIF(TRIM(full_name), ''), username) AS name
                                        FROM users WHERE active = 1 AND role = 'agent' ORDER BY name")->fetchAll() ?: [],
        ];
    }

    // ---- files ----------------------------------------------------------------------

    public static function uploadDir(): string
    {
        $root = dirname(__DIR__, 2);
        $preferred = $root . '/storage/uploads/commissions';
        if (is_dir($preferred) || @mkdir($preferred, 0775, true)) {
            return $preferred;
        }
        return $root . '/public/uploads/commissions'; // behind public/uploads/.htaccess (deny all)
    }

    /** @return array{path:string,name:string}|null; $err = too_big | bad_type | save_failed when a file was refused */
    private static function storeFile(?array $file, ?string &$err): ?array
    {
        $err = null;
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (!$file || $code === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            $err = 'too_big';
            return null;
        }
        if ($code !== UPLOAD_ERR_OK) {
            $err = 'save_failed';
            return null;
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > self::MAX_BYTES) {
            $err = 'too_big';
            return null;
        }
        $orig = (string)($file['name'] ?? 'file');
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT, true)) {
            $err = 'bad_type';
            return null;
        }
        $dir = self::uploadDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $err = 'save_failed';
            return null;
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
            $err = 'save_failed';
            return null;
        }
        return ['path' => $stored, 'name' => mb_substr($orig, 0, 190)];
    }

    /** Send a statement's calculation or invoice file and exit. Only after the caller's permission check. */
    public static function stream(array $st, string $which): void
    {
        $inv  = $which === 'invoice';
        $path = (string)($inv ? ($st['invoice_path'] ?? '') : ($st['calc_path'] ?? ''));
        $name = (string)($inv ? ($st['invoice_name'] ?? '') : ($st['calc_name'] ?? '')) ?: ($inv ? 'fattura' : 'conteggio');
        $full = self::uploadDir() . '/' . basename($path);
        if ($path === '' || !is_file($full)) {
            http_response_code(404);
            exit('Not found');
        }
        $ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mime = self::INLINE[$ext] ?? ([
            'xml' => 'application/xml', 'p7m' => 'application/pkcs7-mime', 'csv' => 'text/csv',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odt' => 'application/vnd.oasis.opendocument.text',
            'zip' => 'application/zip', 'txt' => 'text/plain', 'heic' => 'image/heic',
        ][$ext] ?? 'application/octet-stream');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . (isset(self::INLINE[$ext]) ? 'inline' : 'attachment')
            . '; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
        header('Content-Length: ' . (string)filesize($full));
        header('X-Content-Type-Options: nosniff');
        readfile($full);
        exit;
    }

    // ---- formats --------------------------------------------------------------------

    /** "€ 1.234,56" — Italian style, whatever the page's language, as on an invoice. */
    public static function money(float $n): string
    {
        $cur = (string)Config::get('crm.currency', 'EUR');
        return ($cur === 'EUR' ? '€' : $cur) . ' ' . number_format($n, 2, ',', '.');
    }

    /**
     * An amount as typed: "900", "900,00", "1.234,56", "1,234.56", "€ 900".
     * The last separator is the decimal point when 1-2 digits follow it;
     * otherwise it is a thousands mark. Negative or unreadable = 0.
     */
    public static function parseAmount(string $raw): float
    {
        $s = preg_replace('/[^\d.,\-]/', '', trim($raw)) ?? '';
        if ($s === '' || str_contains($s, '-')) {
            return 0.0;
        }
        $pc = strrpos($s, ',');
        $pd = strrpos($s, '.');
        $last = max($pc === false ? -1 : $pc, $pd === false ? -1 : $pd);
        $after = $last >= 0 ? strlen($s) - $last - 1 : 0;
        if ($last >= 0 && $after >= 1 && $after <= 2) {
            $int = preg_replace('/\D/', '', substr($s, 0, $last)) ?? '';
            $dec = str_pad(preg_replace('/\D/', '', substr($s, $last + 1)) ?? '', 2, '0');
        } else {
            $int = preg_replace('/\D/', '', $s) ?? '';
            $dec = '00';
        }
        return round((int)($int === '' ? '0' : $int) + ((int)substr($dec, 0, 2)) / 100, 2);
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
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    // ---- notifications (queued: never make the page wait on WhatsApp/SMTP) -------------

    /** commission_statement | commission_rejected | commission_paid — to the partner or agent. */
    private static function notifyPayee(int $id, string $rule): void
    {
        try {
            $st = self::find($id);
            $p  = $st ? self::payee((string)$st['payee_type'], (int)$st['payee_id']) : null;
            if (!$st || !$p) {
                return;
            }
            $isPartner = $st['payee_type'] === 'partner';
            $payload = [
                'name'           => (string)$p['name'],
                'payee_name'     => (string)$p['name'],
                'id'             => (string)$id,
                'title'          => (string)$st['title'],
                'amount'         => self::money((float)$st['amount']),
                'reason'         => (string)($st['rejected_note'] ?? ''),
                'invoice_number' => (string)($st['invoice_number'] ?? ''),
                'paid_amount'    => self::money((float)($st['paid_amount'] ?? 0)),
                'paid_on'        => !empty($st['paid_on']) ? date('d/m/Y', strtotime((string)$st['paid_on'])) : '',
                'payment_ref'    => (string)($st['payment_ref'] ?? ''),
                'link'           => Config::appBaseUrl() . ($isPartner ? '/partner.php?tab=commissions' : '/dashboard.php?tab=my_commissions'),
            ];
            $payload += $isPartner
                ? ['partner_name' => (string)$p['name'], 'partner_phone' => (string)($p['phone'] ?? ''), 'partner_email' => (string)($p['email'] ?? '')]
                : ['agent_name' => (string)$p['name'], 'agent_phone' => (string)($p['phone'] ?? ''), 'agent_email' => (string)($p['email'] ?? '')];
            (new Scheduler())->enqueue([
                'entity_type'    => 'commission',
                'entity_id'      => $id,
                'rule_key'       => $rule,
                'recipient_type' => $isPartner ? 'partner' : 'agent',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s'),
                'payload'        => $payload,
                // A rejection can happen more than once on the same statement.
                'dedupe_key'     => $rule . ':' . $id . ($rule === 'commission_rejected' ? ':' . time() : ''),
            ]);
        } catch (Throwable $e) {
            Log::write('commission', 'statement_notify_failed', 'commission', $id, ['rule' => $rule, 'error' => $e->getMessage()]);
        }
    }

    /** "The invoice is in" — to every active administrator (the office), by WhatsApp and email. */
    private static function notifyOffice(int $id): void
    {
        try {
            $st = self::find($id);
            $p  = $st ? self::payee((string)$st['payee_type'], (int)$st['payee_id']) : null;
            if (!$st || !$p) {
                return;
            }
            $link = Config::appBaseUrl() . '/dashboard.php?tab=commissions&st=' . $id . '#cm-' . $id;
            $admins = Db::pdo()->query(
                "SELECT id, COALESCE(NULLIF(TRIM(full_name), ''), username) AS name, phone, email
                   FROM users WHERE role = 'admin' AND active = 1"
            )->fetchAll();
            foreach ($admins as $a) {
                if (trim((string)$a['phone']) === '' && trim((string)$a['email']) === '') {
                    continue;
                }
                (new Scheduler())->enqueue([
                    'entity_type'    => 'commission',
                    'entity_id'      => $id,
                    'rule_key'       => 'commission_invoice_admin',
                    'recipient_type' => 'agent',
                    'channel'        => 'both',
                    'due_at'         => date('Y-m-d H:i:s'),
                    'payload'        => [
                        'name' => (string)$a['name'], 'agent_name' => (string)$a['name'],
                        'agent_phone' => (string)($a['phone'] ?? ''), 'agent_email' => (string)($a['email'] ?? ''),
                        'payee_name' => (string)$p['name'], 'id' => (string)$id, 'title' => (string)$st['title'],
                        'amount' => self::money((float)$st['amount']),
                        'invoice_number' => (string)$st['invoice_number'],
                        'invoice_amount' => self::money((float)($st['invoice_amount'] ?? $st['amount'])),
                        'link' => $link,
                    ],
                    'dedupe_key'     => 'commission_invoice_admin:' . $id . ':' . (int)$a['id'] . ':' . time(),
                ]);
            }
        } catch (Throwable $e) {
            Log::write('commission', 'statement_notify_failed', 'commission', $id, ['rule' => 'commission_invoice_admin', 'error' => $e->getMessage()]);
        }
    }
}
