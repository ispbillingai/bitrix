<?php
declare(strict_types=1);

namespace Glue\Finance;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\StaffAlert;
use Throwable;
use ZipArchive;

/**
 * The document area of a lead, and the financing applications built on it.
 *
 * Asked 2026-09-16: inside a lead the seller uploads the customer's general
 * documents, or opens a financing application whose required documents each
 * have their own row and upload button. The list can be filled a bit at a time
 * through the same link. The office is told, checks the folder, and then sends
 * the application on to one or more lenders, each through its own link. Every
 * lender gets the same documents except the privacy form, which is its own.
 *
 * Files never sit in the web root: storage/uploads/lead-docs, served only by
 * the dashboard (staff), the application's own link (the seller or customer)
 * and a lender's link (that lender's dossier).
 */
final class Docs
{
    public const MAX_BYTES = 20971520; // 20 MB a file
    /** What a customer's paperwork actually arrives as. */
    private const EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'tif', 'tiff',
                         'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'csv', 'txt', 'p7m', 'xml', 'zip'];
    private const INLINE = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                            'png' => 'image/png', 'webp' => 'image/webp'];
    private const MIME = ['heic' => 'image/heic', 'heif' => 'image/heif', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
                          'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                          'odt' => 'application/vnd.oasis.opendocument.text', 'xls' => 'application/vnd.ms-excel',
                          'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                          'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'csv' => 'text/csv',
                          'txt' => 'text/plain', 'p7m' => 'application/pkcs7-mime', 'xml' => 'application/xml', 'zip' => 'application/zip'];

    /** The checklist as shipped; Settings → Finanziamenti can replace it (finance.doc_types). */
    private const DEFAULT_TYPES = "identita|Documento d'identità (fronte/retro)|1\n"
        . "codice_fiscale|Codice fiscale o tessera sanitaria|1\n"
        . "reddito|Ultima busta paga, CU o modello unico|1\n"
        . "estratto_conto|Estratto conto ultimi 3 mesi|0\n"
        . "visura|Visura camerale (se azienda)|0\n"
        . "iban|IBAN / coordinate bancarie|1\n"
        . "preventivo|Preventivo o fattura proforma|1\n"
        . "privacy|Modulo privacy firmato|1|per_lender";

    // ---- the checklist ---------------------------------------------------------------

    /**
     * The rows a financing application asks for: "code|Label|required|per_lender"
     * per line, in Settings. per_lender marks the privacy form, the one document
     * that differs from lender to lender: it gets a row for each lender chosen.
     *
     * @return array<int,array{code:string,label:string,required:bool,per_lender:bool}>
     */
    public static function docTypes(): array
    {
        $raw = trim((string)Config::get('finance.doc_types', '')) ?: self::DEFAULT_TYPES;
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $p = array_map('trim', explode('|', $line));
            $code = preg_replace('/[^a-z0-9_]/', '', strtolower($p[0] ?? '')) ?: '';
            if ($code === '' || isset($out[$code])) {
                continue;
            }
            $out[$code] = [
                'code'       => $code,
                'label'      => ($p[1] ?? '') !== '' ? $p[1] : $code,
                'required'   => ($p[2] ?? '1') === '1',
                'per_lender' => in_array('per_lender', array_slice($p, 3), true),
            ];
        }
        return array_values($out);
    }

    public static function typeLabel(string $code): string
    {
        foreach (self::docTypes() as $t) {
            if ($t['code'] === $code) {
                return $t['label'];
            }
        }
        return $code === 'altro' ? 'Altri documenti' : $code;
    }

    // ---- applications ----------------------------------------------------------------

    public static function app(int $id): ?array
    {
        $s = Db::pdo()->prepare(
            'SELECT a.*, l.customer_name, l.customer_phone, l.customer_email, l.vat_number, l.assigned_to, ct.company
               FROM finance_apps a JOIN leads l ON l.id = a.lead_id
               LEFT JOIN contacts ct ON ct.id = l.contact_id WHERE a.id = ?'
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** The application behind an upload link. Closed ones still resolve: the page says so. */
    public static function appByToken(string $token): ?array
    {
        if (strlen($token) < 20) {
            return null;
        }
        $s = Db::pdo()->prepare(
            'SELECT a.*, l.customer_name, l.customer_phone, l.customer_email, l.vat_number, l.assigned_to, ct.company
               FROM finance_apps a JOIN leads l ON l.id = a.lead_id
               LEFT JOIN contacts ct ON ct.id = l.contact_id WHERE a.token = ?'
        );
        $s->execute([$token]);
        return $s->fetch() ?: null;
    }

    /** The application on a lead (there is one), or null. */
    public static function appForLead(int $leadId): ?array
    {
        $s = Db::pdo()->prepare('SELECT id FROM finance_apps WHERE lead_id = ? ORDER BY id DESC LIMIT 1');
        $s->execute([$leadId]);
        $id = (int)($s->fetchColumn() ?: 0);
        return $id > 0 ? self::app($id) : null;
    }

    /** Open the application for a lead, or return the one already there. */
    public static function openApp(int $leadId, ?int $userId, array $d = []): array
    {
        $lead = Db::pdo()->prepare('SELECT id, contact_id FROM leads WHERE id = ?');
        $lead->execute([$leadId]);
        $l = $lead->fetch();
        if (!$l) {
            return ['ok' => false, 'error' => 'no_lead'];
        }
        $have = self::appForLead($leadId);
        if ($have) {
            return ['ok' => true, 'id' => (int)$have['id'], 'existing' => true];
        }
        Db::pdo()->prepare(
            'INSERT INTO finance_apps (lead_id, contact_id, amount, purpose, notes, lender_ids, token, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $leadId, $l['contact_id'] ?: null,
            self::amount((string)($d['amount'] ?? '')) ?: null,
            mb_substr(trim((string)($d['purpose'] ?? '')), 0, 190) ?: null,
            trim((string)($d['notes'] ?? '')) ?: null,
            self::lenderCsv($d['lender_ids'] ?? []),
            bin2hex(random_bytes(24)), $userId ?: null,
        ]);
        $id = (int)Db::pdo()->lastInsertId();
        Log::write('finance', 'app_opened', 'finance_app', $id, ['lead' => $leadId, 'by' => $userId]);
        return ['ok' => true, 'id' => $id, 'existing' => false];
    }

    /** Amount, purpose, notes and which lenders this application is aimed at. */
    public static function updateApp(int $id, array $d, ?int $userId): array
    {
        $a = self::app($id);
        if (!$a) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        Db::pdo()->prepare('UPDATE finance_apps SET amount = ?, purpose = ?, notes = ?, lender_ids = ? WHERE id = ?')
            ->execute([
                self::amount((string)($d['amount'] ?? '')) ?: null,
                mb_substr(trim((string)($d['purpose'] ?? '')), 0, 190) ?: null,
                trim((string)($d['notes'] ?? '')) ?: null,
                self::lenderCsv($d['lender_ids'] ?? []), $id,
            ]);
        Log::write('finance', 'app_updated', 'finance_app', $id, ['by' => $userId]);
        return ['ok' => true];
    }

    /** The seller hands the folder to the office. Alerts every admin, queued. */
    public static function submit(int $id, ?int $userId): array
    {
        $a = self::app($id);
        if (!$a || !in_array($a['status'], ['collecting', 'review'], true)) {
            return ['ok' => false, 'error' => 'state'];
        }
        $p = self::progress($id);
        Db::pdo()->prepare("UPDATE finance_apps SET status = 'review', submitted_at = NOW() WHERE id = ?")->execute([$id]);
        Log::write('finance', 'app_submitted', 'finance_app', $id, ['by' => $userId, 'missing' => $p['missing']]);
        self::alertOffice($id, 'review', $p);
        return ['ok' => true, 'missing' => $p['missing']];
    }

    /** The office has read the folder: it is ready to go out to the lenders. */
    public static function review(int $id, ?int $userId): array
    {
        $a = self::app($id);
        if (!$a || $a['status'] === 'closed') {
            return ['ok' => false, 'error' => 'state'];
        }
        Db::pdo()->prepare('UPDATE finance_apps SET reviewed_at = NOW(), reviewed_by = ? WHERE id = ?')->execute([$userId ?: null, $id]);
        Log::write('finance', 'app_reviewed', 'finance_app', $id, ['by' => $userId]);
        return ['ok' => true];
    }

    /** Back to the seller for the missing pieces (the upload link keeps working). */
    public static function reopen(int $id, ?int $userId): array
    {
        $a = self::app($id);
        if (!$a) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        Db::pdo()->prepare("UPDATE finance_apps SET status = 'collecting', reviewed_at = NULL, reviewed_by = NULL WHERE id = ?")->execute([$id]);
        Log::write('finance', 'app_reopened', 'finance_app', $id, ['by' => $userId]);
        return ['ok' => true];
    }

    public static function close(int $id, ?int $userId): array
    {
        Db::pdo()->prepare("UPDATE finance_apps SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$id]);
        Log::write('finance', 'app_closed', 'finance_app', $id, ['by' => $userId]);
        return ['ok' => true];
    }

    /**
     * The office list. $f: status, agent (scope an agent to their own leads), q.
     */
    public static function allApps(array $f = []): array
    {
        $w = [];
        $args = [];
        if (!empty($f['status']) && in_array($f['status'], ['collecting', 'review', 'sent', 'closed'], true)) {
            $w[] = 'a.status = ?';
            $args[] = $f['status'];
        }
        if (!empty($f['agent'])) {
            $w[] = 'l.assigned_to = ?';
            $args[] = (int)$f['agent'];
        }
        if (!empty($f['q'])) {
            $w[] = '(l.customer_name LIKE ? OR ct.company LIKE ? OR l.vat_number LIKE ?)';
            $like = '%' . trim((string)$f['q']) . '%';
            array_push($args, $like, $like, $like);
        }
        $s = Db::pdo()->prepare(
            "SELECT a.*, l.customer_name, ct.company, l.vat_number, l.assigned_to,
                    COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS agent_name,
                    (SELECT COUNT(*) FROM lead_files f WHERE f.app_id = a.id) AS files
               FROM finance_apps a
               JOIN leads l ON l.id = a.lead_id
               LEFT JOIN contacts ct ON ct.id = l.contact_id
               LEFT JOIN users u ON u.id = l.assigned_to"
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
            . " ORDER BY (a.status = 'review') DESC, a.id DESC LIMIT 300"
        );
        $s->execute($args);
        return $s->fetchAll() ?: [];
    }

    /** How many applications are waiting for the office — the sidebar number. */
    public static function countInReview(): int
    {
        return (int)Db::pdo()->query("SELECT COUNT(*) FROM finance_apps WHERE status = 'review'")->fetchColumn();
    }

    // ---- the rows -------------------------------------------------------------------

    /**
     * One row per required document, plus one privacy row per lender chosen, plus
     * whatever extra files were added. Each row carries the files already there.
     *
     * @return array<int,array{code:string,label:string,required:bool,lender_id:?int,lender:?string,files:array}>
     */
    public static function checklist(int $appId): array
    {
        $a = self::app($appId);
        if (!$a) {
            return [];
        }
        $files = self::forApp($appId);
        $byKey = [];
        foreach ($files as $f) {
            $byKey[(string)$f['slot_code'] . ':' . (int)$f['lender_id']][] = $f;
        }
        $lenders = self::lendersByIds(self::lenderIds($a));
        $rows = [];
        foreach (self::docTypes() as $t) {
            if (!$t['per_lender']) {
                $rows[] = $t + ['lender_id' => null, 'lender' => null, 'files' => $byKey[$t['code'] . ':0'] ?? []];
                continue;
            }
            if (!$lenders) { // no lender chosen yet: one row, and it says so
                $rows[] = $t + ['lender_id' => null, 'lender' => null, 'files' => $byKey[$t['code'] . ':0'] ?? []];
                continue;
            }
            foreach ($lenders as $l) {
                $rows[] = $t + ['lender_id' => (int)$l['id'], 'lender' => (string)$l['name'],
                                'files' => $byKey[$t['code'] . ':' . (int)$l['id']] ?? []];
            }
        }
        $extra = array_values(array_filter($files, fn($f) => (string)$f['slot_code'] === 'altro'));
        $rows[] = ['code' => 'altro', 'label' => self::typeLabel('altro'), 'required' => false,
                   'per_lender' => false, 'lender_id' => null, 'lender' => null, 'files' => $extra];
        return $rows;
    }

    /** ['done' => n, 'required' => m, 'missing' => [labels]] over the required rows. */
    public static function progress(int $appId): array
    {
        $done = 0; $req = 0; $missing = [];
        foreach (self::checklist($appId) as $row) {
            if (!$row['required']) {
                continue;
            }
            $req++;
            if ($row['files']) {
                $done++;
            } else {
                $missing[] = $row['label'] . ($row['lender'] ? ' (' . $row['lender'] . ')' : '');
            }
        }
        return ['done' => $done, 'required' => $req, 'missing' => $missing];
    }

    // ---- files -----------------------------------------------------------------------

    public static function uploadDir(): string
    {
        $root = dirname(__DIR__, 2);
        $dir  = $root . '/storage/uploads/lead-docs';
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            return $dir;
        }
        return $root . '/public/uploads/lead-docs'; // behind public/uploads/.htaccess (deny all)
    }

    public static function file(int $id): ?array
    {
        $s = Db::pdo()->prepare('SELECT * FROM lead_files WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** The general documents of a lead (everything not inside an application). */
    public static function forLead(int $leadId): array
    {
        $s = Db::pdo()->prepare('SELECT * FROM lead_files WHERE lead_id = ? AND app_id IS NULL ORDER BY id DESC');
        $s->execute([$leadId]);
        return $s->fetchAll() ?: [];
    }

    public static function forApp(int $appId): array
    {
        $s = Db::pdo()->prepare('SELECT * FROM lead_files WHERE app_id = ? ORDER BY id');
        $s->execute([$appId]);
        return $s->fetchAll() ?: [];
    }

    /**
     * File one upload. $d: lead_id, contact_id, app_id, slot_code, lender_id,
     * source ('agent'|'office'|'link'), user_id, user_name.
     * @return int the new file id, or 0 with $err set (too_big | bad_type | save_failed | no_file)
     */
    public static function store(array $d, ?array $file, ?string &$err = null): int
    {
        $err = null;
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (!$file || $code === UPLOAD_ERR_NO_FILE) {
            $err = 'no_file';
            return 0;
        }
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            $err = 'too_big';
            return 0;
        }
        if ($code !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            $err = 'save_failed';
            return 0;
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > self::MAX_BYTES) {
            $err = 'too_big';
            return 0;
        }
        $orig = (string)($file['name'] ?? 'file');
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT, true)) {
            $err = 'bad_type';
            return 0;
        }
        $dir = self::uploadDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $err = 'save_failed';
            return 0;
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
            $err = 'save_failed';
            return 0;
        }
        Db::pdo()->prepare(
            'INSERT INTO lead_files (lead_id, contact_id, app_id, slot_code, lender_id, name, path, mime, size_bytes, source, uploaded_by, uploader_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int)$d['lead_id'], ($d['contact_id'] ?? null) ?: null, ($d['app_id'] ?? null) ?: null,
            ($d['slot_code'] ?? null) ?: null, ($d['lender_id'] ?? null) ?: null,
            mb_substr($orig, 0, 190), $stored, mb_substr((string)($file['type'] ?? ''), 0, 100) ?: null,
            (int)$file['size'], in_array($d['source'] ?? '', ['agent', 'office', 'link'], true) ? $d['source'] : 'agent',
            ($d['user_id'] ?? null) ?: null, mb_substr((string)($d['user_name'] ?? ''), 0, 150) ?: null,
        ]);
        $id = (int)Db::pdo()->lastInsertId();
        Log::write('finance', 'file_uploaded', 'lead', (int)$d['lead_id'],
            ['file' => $id, 'app' => $d['app_id'] ?? null, 'slot' => $d['slot_code'] ?? null, 'by' => $d['user_id'] ?? null, 'source' => $d['source'] ?? '']);
        return $id;
    }

    /**
     * A whole file input at once (multiple="multiple").
     * @return array{count:int, errors:array<int,string>}
     */
    public static function storeMany(array $d, ?array $files): array
    {
        $out = ['count' => 0, 'errors' => []];
        if (!$files || !isset($files['name'])) {
            return $out;
        }
        $names = (array)$files['name'];
        foreach (array_keys($names) as $i) {
            $one = ['name' => $files['name'][$i], 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0];
            if ((int)$one['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $err = null;
            if (self::store($d, $one, $err) > 0) {
                $out['count']++;
            } else {
                $out['errors'][] = (string)$one['name'] . ': ' . (string)$err;
            }
        }
        return $out;
    }

    /** Remove a file and forget it. The caller decides who may. */
    public static function delete(int $id, ?int $userId): bool
    {
        $f = self::file($id);
        if (!$f) {
            return false;
        }
        @unlink(self::uploadDir() . '/' . basename((string)$f['path']));
        Db::pdo()->prepare('DELETE FROM lead_files WHERE id = ?')->execute([$id]);
        Log::write('finance', 'file_deleted', 'lead', (int)$f['lead_id'], ['file' => $id, 'name' => $f['name'], 'by' => $userId]);
        return true;
    }

    /** Send a file and stop. Only after the caller's permission check. */
    public static function stream(array $f): void
    {
        $full = self::uploadDir() . '/' . basename((string)$f['path']);
        if (!is_file($full)) {
            http_response_code(404);
            exit('Not found');
        }
        $ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mime = self::INLINE[$ext] ?? (self::MIME[$ext] ?? 'application/octet-stream');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . (isset(self::INLINE[$ext]) ? 'inline' : 'attachment')
            . '; filename="' . str_replace(['"', "\r", "\n"], '', (string)$f['name']) . '"');
        header('Content-Length: ' . (string)filesize($full));
        header('X-Content-Type-Options: nosniff');
        readfile($full);
        exit;
    }

    // ---- lenders ---------------------------------------------------------------------

    public static function lenders(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM finance_lenders' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY name';
        return Db::pdo()->query($sql)->fetchAll() ?: [];
    }

    public static function lender(int $id): ?array
    {
        $s = Db::pdo()->prepare('SELECT * FROM finance_lenders WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** @param array $d id, name, email, phone, notes, active */
    public static function saveLender(array $d, ?array $privacy, ?int $userId): array
    {
        $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 150);
        if ($name === '') {
            return ['ok' => false, 'error' => 'name'];
        }
        $id = (int)($d['id'] ?? 0);
        $err = null;
        $file = self::storePrivacy($privacy, $err);
        if ($err !== null) {
            return ['ok' => false, 'error' => 'file_' . $err];
        }
        $args = [$name, trim((string)($d['email'] ?? '')) ?: null, trim((string)($d['phone'] ?? '')) ?: null,
                 mb_substr(trim((string)($d['notes'] ?? '')), 0, 500) ?: null, !empty($d['active']) ? 1 : 0];
        if ($id > 0) {
            $sql = 'UPDATE finance_lenders SET name = ?, email = ?, phone = ?, notes = ?, active = ?';
            if ($file) {
                $old = self::lender($id);
                if ($old && $old['privacy_path']) {
                    @unlink(self::uploadDir() . '/' . basename((string)$old['privacy_path']));
                }
                $sql .= ', privacy_path = ?, privacy_name = ?';
                $args[] = $file['path'];
                $args[] = $file['name'];
            }
            $args[] = $id;
            Db::pdo()->prepare($sql . ' WHERE id = ?')->execute($args);
        } else {
            Db::pdo()->prepare('INSERT INTO finance_lenders (name, email, phone, notes, active, privacy_path, privacy_name) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($args, [$file['path'] ?? null, $file['name'] ?? null]));
            $id = (int)Db::pdo()->lastInsertId();
        }
        Log::write('finance', 'lender_saved', 'finance_lender', $id, ['name' => $name, 'by' => $userId]);
        return ['ok' => true, 'id' => $id];
    }

    /** Only while nothing points at it; otherwise untick Active. */
    public static function deleteLender(int $id, ?int $userId): array
    {
        $used = (int)Db::pdo()->query('SELECT (SELECT COUNT(*) FROM finance_shares WHERE lender_id = ' . $id . ')
            + (SELECT COUNT(*) FROM lead_files WHERE lender_id = ' . $id . ')')->fetchColumn();
        if ($used > 0) {
            return ['ok' => false, 'error' => 'in_use', 'used' => $used];
        }
        $l = self::lender($id);
        if ($l && $l['privacy_path']) {
            @unlink(self::uploadDir() . '/' . basename((string)$l['privacy_path']));
        }
        Db::pdo()->prepare('DELETE FROM finance_lenders WHERE id = ?')->execute([$id]);
        Log::write('finance', 'lender_deleted', 'finance_lender', $id, ['by' => $userId]);
        return ['ok' => true];
    }

    // ---- sending it on ---------------------------------------------------------------

    /**
     * A link per lender: the same folder, each with its own privacy form. Sending
     * again to the same lender keeps the link it already has.
     * @return array{ok:bool, shares:array, error:?string}
     */
    public static function share(int $appId, array $lenderIds, ?int $userId): array
    {
        $a = self::app($appId);
        if (!$a) {
            return ['ok' => false, 'shares' => [], 'error' => 'not_found'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $lenderIds), fn($i) => $i > 0)));
        if (!$ids) {
            return ['ok' => false, 'shares' => [], 'error' => 'no_lender'];
        }
        $pdo = Db::pdo();
        foreach ($ids as $lid) {
            if (!self::lender($lid)) {
                continue;
            }
            $pdo->prepare('INSERT INTO finance_shares (app_id, lender_id, token, created_by) VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE revoked_at = NULL')
                ->execute([$appId, $lid, bin2hex(random_bytes(24)), $userId ?: null]);
        }
        // The lenders it went to are also the ones its privacy rows follow.
        $keep = array_values(array_unique(array_merge(self::lenderIds($a), $ids)));
        $pdo->prepare("UPDATE finance_apps SET status = 'sent', sent_at = COALESCE(sent_at, NOW()), lender_ids = ? WHERE id = ?")
            ->execute([implode(',', $keep), $appId]);
        Log::write('finance', 'app_sent', 'finance_app', $appId, ['lenders' => $ids, 'by' => $userId]);
        return ['ok' => true, 'shares' => self::shares($appId), 'error' => null];
    }

    public static function shares(int $appId): array
    {
        $s = Db::pdo()->prepare(
            'SELECT s.*, f.name AS lender_name, f.email AS lender_email, f.phone AS lender_phone
               FROM finance_shares s JOIN finance_lenders f ON f.id = s.lender_id
              WHERE s.app_id = ? ORDER BY f.name'
        );
        $s->execute([$appId]);
        return $s->fetchAll() ?: [];
    }

    public static function shareByToken(string $token): ?array
    {
        if (strlen($token) < 20) {
            return null;
        }
        $s = Db::pdo()->prepare(
            'SELECT s.*, f.name AS lender_name, f.privacy_path, f.privacy_name
               FROM finance_shares s JOIN finance_lenders f ON f.id = s.lender_id
              WHERE s.token = ? AND s.revoked_at IS NULL'
        );
        $s->execute([$token]);
        return $s->fetch() ?: null;
    }

    public static function revokeShare(int $id, ?int $userId): void
    {
        Db::pdo()->prepare('UPDATE finance_shares SET revoked_at = NOW() WHERE id = ?')->execute([$id]);
        Log::write('finance', 'share_revoked', 'finance_share', $id, ['by' => $userId]);
    }

    public static function touchShare(int $id): void
    {
        Db::pdo()->prepare('UPDATE finance_shares SET opens = opens + 1, last_opened_at = NOW() WHERE id = ?')->execute([$id]);
    }

    /**
     * What one lender sees: every document of the application except another
     * lender's privacy form, plus the general documents of the customer.
     */
    public static function dossier(array $share): array
    {
        $appId = (int)$share['app_id'];
        $lid   = (int)$share['lender_id'];
        $a     = self::app($appId);
        $out   = [];
        foreach (self::forApp($appId) as $f) {
            if ((int)$f['lender_id'] > 0 && (int)$f['lender_id'] !== $lid) {
                continue; // another lender's privacy form
            }
            $out[] = $f;
        }
        foreach (self::forLead((int)($a['lead_id'] ?? 0)) as $f) {
            $out[] = $f;
        }
        return $out;
    }

    /** The dossier as one zip in a temp file; the caller sends and deletes it. */
    public static function zip(array $share): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }
        $files = self::dossier($share);
        if (!$files) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dossier') ?: null;
        if ($tmp === null) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return null;
        }
        $used = [];
        foreach ($files as $f) {
            $full = self::uploadDir() . '/' . basename((string)$f['path']);
            if (!is_file($full)) {
                continue;
            }
            $label = self::typeLabel((string)($f['slot_code'] ?? '')) ?: 'documento';
            $name  = preg_replace('/[^\w .\-]+/u', '_', ($f['slot_code'] ? $label . ' - ' : '') . (string)$f['name']);
            $n = $name;
            $i = 2;
            while (isset($used[$n])) { $n = $i . ' ' . $name; $i++; }
            $used[$n] = true;
            $zip->addFile($full, $n);
        }
        $zip->close();
        return $tmp;
    }

    // ---- links -----------------------------------------------------------------------

    public static function uploadUrl(string $token): string
    {
        return Config::appBaseUrl() . '/pratica.php?t=' . $token;
    }

    public static function shareUrl(string $token): string
    {
        return Config::appBaseUrl() . '/finanziaria.php?t=' . $token;
    }

    /** The office's own link to this customer's folder. */
    public static function folderUrl(int $leadId, int $appId = 0): string
    {
        return Config::appBaseUrl() . '/dashboard.php?tab=finance' . ($appId > 0 ? '&app=' . $appId : '&lead=' . $leadId);
    }

    // ---- telling the office ----------------------------------------------------------

    /** Documents arrived (general ones, or an application handed over). Queued, never inline. */
    public static function alertOffice(int $appId, string $kind, array $progress = []): void
    {
        try {
            $a = self::app($appId);
            if (!$a) {
                return;
            }
            $who   = trim((string)$a['customer_name']) ?: ('#' . (int)$a['lead_id']);
            $co    = trim((string)($a['company'] ?? ''));
            $link  = self::folderUrl((int)$a['lead_id'], $appId);
            $p     = $progress ?: self::progress($appId);
            $miss  = $p['missing'] ? "\nMancano: " . implode(', ', array_slice($p['missing'], 0, 6)) : '';
            $text  = "📁 Pratica di finanziamento da verificare — $who" . ($co !== '' ? " ($co)" : '')
                   . "\nDocumenti: {$p['done']}/{$p['required']}" . $miss
                   . (!empty($a['amount']) ? "\nImporto: € " . number_format((float)$a['amount'], 2, ',', '.') : '')
                   . "\n\nApri la cartella: $link";
            $html  = '<p>📁 <b>Pratica di finanziamento da verificare</b> — ' . htmlspecialchars($who, ENT_QUOTES)
                   . ($co !== '' ? ' (' . htmlspecialchars($co, ENT_QUOTES) . ')' : '') . '</p>'
                   . '<p>Documenti: <b>' . $p['done'] . '/' . $p['required'] . '</b>'
                   . ($p['missing'] ? '<br>Mancano: ' . htmlspecialchars(implode(', ', $p['missing']), ENT_QUOTES) : '') . '</p>'
                   . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Apri la cartella del cliente</a></p>';
            StaffAlert::toRole('admin', 'staff_finance_' . ($kind === 'review' ? 'review' : 'docs'), $text,
                'Pratica di finanziamento — ' . $who, $html, 'finance_app', $appId);
        } catch (Throwable $e) {
            Log::write('finance', 'alert_failed', 'finance_app', $appId, ['error' => $e->getMessage()]);
        }
    }

    /** General documents (no application): the office is told they are in the folder. */
    public static function alertOfficeFiles(int $leadId, int $count, string $customer, ?string $company): void
    {
        try {
            $link = Config::appBaseUrl() . '/dashboard.php?tab=leads&lead=' . $leadId . '#lead-' . $leadId;
            $who  = trim($customer) ?: ('#' . $leadId);
            $co   = trim((string)$company);
            $text = "📎 $count documenti caricati per $who" . ($co !== '' ? " ($co)" : '') . "\n\nApri la scheda: $link";
            $html = '<p>📎 <b>' . $count . ' documenti</b> caricati per ' . htmlspecialchars($who, ENT_QUOTES)
                  . ($co !== '' ? ' (' . htmlspecialchars($co, ENT_QUOTES) . ')' : '') . '</p>'
                  . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Apri la scheda del cliente</a></p>';
            StaffAlert::toRole('admin', 'staff_lead_docs', $text, 'Documenti cliente — ' . $who, $html, 'lead', $leadId);
        } catch (Throwable $e) {
            Log::write('finance', 'alert_failed', 'lead', $leadId, ['error' => $e->getMessage()]);
        }
    }

    // ---- helpers ---------------------------------------------------------------------

    /** @return int[] the lenders this application is aimed at */
    public static function lenderIds(array $app): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string)($app['lender_ids'] ?? ''))), fn($i) => $i > 0));
    }

    public static function lendersByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (!$ids) {
            return [];
        }
        return Db::pdo()->query('SELECT * FROM finance_lenders WHERE id IN (' . implode(',', $ids) . ') ORDER BY name')->fetchAll() ?: [];
    }

    private static function lenderCsv(mixed $v): ?string
    {
        $ids = array_values(array_filter(array_map('intval', (array)$v), fn($i) => $i > 0));
        return $ids ? implode(',', array_unique($ids)) : null;
    }

    /** "1.234,56" / "1234.56" / "€ 900" → 1234.56; 0 when unreadable. */
    public static function amount(string $raw): float
    {
        $s = preg_replace('/[^\d.,]/', '', trim($raw)) ?? '';
        if ($s === '') {
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

    /** The lender's blank privacy form, kept beside the customers' files. */
    private static function storePrivacy(?array $file, ?string &$err): ?array
    {
        $err = null;
        if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $fake = ['lead_id' => 0];
        $one  = $file;
        $code = (int)$one['error'];
        if ($code !== UPLOAD_ERR_OK || !is_uploaded_file((string)$one['tmp_name'])) {
            $err = 'save_failed';
            return null;
        }
        if ((int)$one['size'] <= 0 || (int)$one['size'] > self::MAX_BYTES) {
            $err = 'too_big';
            return null;
        }
        $ext = strtolower(pathinfo((string)$one['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT, true)) {
            $err = 'bad_type';
            return null;
        }
        $dir = self::uploadDir();
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string)$one['tmp_name'], $dir . '/' . $stored)) {
            $err = 'save_failed';
            return null;
        }
        unset($fake);
        return ['path' => $stored, 'name' => mb_substr((string)$one['name'], 0, 190)];
    }

    /** Human size, for the lists. */
    public static function size(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        return max(1, (int)round($bytes / 1024)) . ' KB';
    }
    /**
     * Send a lender's link where the office types it: an email address, a phone
     * number for WhatsApp, or several separated by commas. Queued like every
     * other message (Notify\StaffAlert's path), so the page never waits on it;
     * the scheduler's next tick delivers it.
     *
     * @return array{ok:bool, sent:int, to:string, error:?string}
     */
    public static function sendShare(int $shareId, string $to, ?int $userId): array
    {
        $s = Db::pdo()->prepare(
            'SELECT s.*, f.name AS lender_name FROM finance_shares s
               JOIN finance_lenders f ON f.id = s.lender_id WHERE s.id = ?'
        );
        $s->execute([$shareId]);
        $sh = $s->fetch();
        if (!$sh || $sh['revoked_at']) {
            return ['ok' => false, 'sent' => 0, 'to' => '', 'error' => 'not_found'];
        }
        $app = self::app((int)$sh['app_id']);
        if (!$app) {
            return ['ok' => false, 'sent' => 0, 'to' => '', 'error' => 'not_found'];
        }
        $targets = [];
        // Commas, semicolons and new lines separate recipients — not spaces:
        // "347 1234567" is one number, and splitting it made two bad ones.
        foreach (preg_split('/[,;\r\n]+/', trim($to)) ?: [] as $one) {
            $one = trim($one);
            if ($one === '') {
                continue;
            }
            if (filter_var($one, FILTER_VALIDATE_EMAIL)) {
                $targets[] = ['email', $one];
                continue;
            }
            $p = \Glue\Notify\Notifier::normalizePhone($one);
            if ($p !== '' && strlen(preg_replace('/\D/', '', $p) ?? '') >= 8) {
                $targets[] = ['whatsapp', $p];
            }
        }
        if (!$targets) {
            return ['ok' => false, 'sent' => 0, 'to' => '', 'error' => 'recipient'];
        }

        $company = (string)Config::get('app.company_name', '') ?: 'CRM';
        $who     = trim((string)$app['customer_name']) ?: ('#' . (int)$app['lead_id']);
        $co      = trim((string)($app['company'] ?? ''));
        $amount  = !empty($app['amount']) ? ' — € ' . number_format((float)$app['amount'], 2, ',', '.') : '';
        $url     = self::shareUrl((string)$sh['token']);
        $text    = "📁 $company — pratica di finanziamento: $who" . ($co !== '' ? " ($co)" : '') . $amount . "\n"
                 . "La documentazione completa è a questo link: $url\n"
                 . 'I documenti si scaricano singolarmente o tutti insieme in un unico file ZIP.';
        $subject = 'Pratica di finanziamento — ' . $who . ($co !== '' ? ' (' . $co . ')' : '');
        $html    = '<p>Pratica di finanziamento: <b>' . htmlspecialchars($who, ENT_QUOTES) . '</b>'
                 . ($co !== '' ? ' (' . htmlspecialchars($co, ENT_QUOTES) . ')' : '')
                 . htmlspecialchars($amount, ENT_QUOTES) . '</p>'
                 . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Apri la documentazione</a></p>'
                 . '<p>I documenti si scaricano singolarmente o tutti insieme in un unico file ZIP.</p>'
                 . '<p>' . htmlspecialchars($company, ENT_QUOTES) . '</p>';

        $sched = new \Glue\Reminder\Scheduler();
        $batch = date('YmdHis') . bin2hex(random_bytes(3));
        $sent  = 0;
        foreach ($targets as [$chan, $addr]) {
            try {
                $sched->enqueue([
                    'entity_type'    => 'finance_share',
                    'entity_id'      => (int)$sh['id'],
                    'rule_key'       => 'lender_share_link',
                    'recipient_type' => 'agent', // an outside recipient, addressed by the payload below
                    'channel'        => $chan,
                    'due_at'         => date('Y-m-d H:i:s'),
                    'payload'        => [
                        'name' => (string)$sh['lender_name'], 'agent_name' => (string)$sh['lender_name'],
                        'agent_phone' => $chan === 'whatsapp' ? $addr : '',
                        'agent_email' => $chan === 'email' ? $addr : '',
                        'raw_wa' => $text, 'raw_subject' => $subject, 'raw_html' => $html,
                    ],
                    'dedupe_key'     => mb_substr('lender_share:' . (int)$sh['id'] . ':' . $addr . ':' . $batch, 0, 128),
                ], false); // queue only: the scheduler cron sends it within the minute
                $sent++;
            } catch (Throwable $e) {
                Log::write('finance', 'share_send_failed', 'finance_share', (int)$sh['id'], ['to' => $addr, 'error' => $e->getMessage()]);
            }
        }
        $list = implode(', ', array_column($targets, 1));
        Db::pdo()->prepare('UPDATE finance_shares SET sent_to = ?, sent_at = NOW() WHERE id = ?')
            ->execute([mb_substr($list, 0, 190), (int)$sh['id']]);
        Log::write('finance', 'share_sent', 'finance_share', (int)$sh['id'], ['to' => $list, 'by' => $userId]);
        return ['ok' => $sent > 0, 'sent' => $sent, 'to' => $list, 'error' => $sent > 0 ? null : 'recipient'];
    }
}
