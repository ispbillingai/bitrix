<?php
declare(strict_types=1);

namespace Glue\Install;

use Glue\Config;
use Glue\Crm\Contacts;
use Glue\Db;
use Glue\Event\Log;
use Glue\Sign\Documents as SignDocs;

/**
 * Installation reports — the Jotform "Installazione Cashmatic" form, in-house.
 *
 * A report is drafted by the technician on site (machine data + photos), then
 * "send for signature" renders it to PDF and hands it to the existing signing
 * flow (Sign\Documents): the customer gets the link on WhatsApp/email, proves
 * themself with the one-time code, and the sealed PDF shows up in their portal
 * and on the customer page like every other signed document. After that the
 * report row is frozen — the PDF the customer signed must stay the PDF we show.
 */
final class Reports
{
    private const PHOTO_MAX_BYTES = 15728640; // 15 MB per shot, phone originals included
    private const PHOTO_EXT       = ['jpg', 'jpeg', 'png', 'webp'];
    /** Longest side after normalisation — keeps a 10-photo report PDF around 2 MB. */
    private const PHOTO_MAX_PX    = 1600;

    public const UPS_VALUES  = ['present', 'absent'];
    public const CASH_VALUES = ['none', 'checks', 'card', 'cash'];
    public const TYPES       = ['installation', 'test'];
    /** "4 days before the end of the test, two notices must arrive." */
    public const TEST_NOTICE_DAYS = 4;
    /** Suggested machine models (free text still allowed — new models arrive). */
    public const MODELS = ['SP 360', 'SP 460', 'SP 560', 'SP 860', 'SP 1060'];

    // ---- lifecycle -------------------------------------------------------------------

    /** Open a draft on a customer. The clock starts now; the tech edits the rest. */
    public static function create(int $contactId, ?int $userId): int
    {
        $tech = self::techName($userId);
        Db::pdo()->prepare(
            'INSERT INTO install_reports (contact_id, technician_id, technician_name, started_at, created_by)
             VALUES (?, ?, ?, NOW(), ?)'
        )->execute([$contactId, $userId ?: null, $tech, $userId ?: null]);
        $id = (int)Db::pdo()->lastInsertId();
        Log::write('install', 'report_created', 'install_report', $id, ['contact_id' => $contactId]);
        return $id;
    }

    /** Editable while draft only — a sent report is what the customer signed. */
    public static function update(int $id, array $d): bool
    {
        $r = self::find($id);
        if (!$r || $r['status'] !== 'draft') {
            return false;
        }
        $ups  = in_array($d['ups_installed'] ?? '', self::UPS_VALUES, true) ? $d['ups_installed'] : 'absent';
        $cash = in_array($d['cash_collected'] ?? '', self::CASH_VALUES, true) ? $d['cash_collected'] : 'none';
        $type = in_array($d['report_type'] ?? '', self::TYPES, true) ? $d['report_type'] : 'installation';
        // The TEST end date is the technician's to type, unlike finished_at: it
        // is a promise about the future (when the trial ends), not a record of
        // when the work stopped.
        $testEnd = self::date($d['test_end_date'] ?? '');
        // finished_at is deliberately NOT accepted here: the owner's rule is that
        // the end time is the system's word, not the technician's — it is stamped
        // in send(), the moment the report goes to the customer for signature.
        Db::pdo()->prepare(
            'UPDATE install_reports SET
                report_type = ?, test_end_date = ?,
                started_at = ?, machine_model = ?, serial_number = ?, ground_value = ?,
                local_ip = ?, public_ip = ?, adsl_provider = ?, vpn_address = ?, remote_assist_id = ?,
                ups_installed = ?, cash_collected = ?, notes = ?
             WHERE id = ?'
        )->execute([
            $type, $type === 'test' ? $testEnd : null,
            self::dt($d['started_at'] ?? ''),
            self::s($d['machine_model'] ?? '', 80), self::s($d['serial_number'] ?? '', 80),
            self::s($d['ground_value'] ?? '', 40),
            self::s($d['local_ip'] ?? '', 64), self::s($d['public_ip'] ?? '', 64),
            self::s($d['adsl_provider'] ?? '', 80), self::s($d['vpn_address'] ?? '', 80),
            self::s($d['remote_assist_id'] ?? '', 190),
            $ups, $cash, trim((string)($d['notes'] ?? '')) ?: null,
            $id,
        ]);
        return true;
    }

    /**
     * Render, file with the signing flow, and message the customer the link.
     *
     * @return array{ok:bool, error:?string} error: not_draft | no_contact |
     *         no_channel | (whatever Documents::createFromBytes reports)
     */
    public static function send(int $id, ?int $userId): array
    {
        $r = self::find($id);
        if (!$r || $r['status'] !== 'draft') {
            return ['ok' => false, 'error' => 'not_draft'];
        }
        $contact = Contacts::find((int)$r['contact_id']);
        if (!$contact) {
            return ['ok' => false, 'error' => 'no_contact'];
        }
        if (trim((string)($contact['phone'] ?? '')) === '' && trim((string)($contact['email'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'no_channel'];
        }
        // A test installation without its end date has nothing to time the
        // end-of-test notices against — refuse to send until it is filled in.
        if (($r['report_type'] ?? '') === 'test' && empty($r['test_end_date'])) {
            return ['ok' => false, 'error' => 'no_test_date'];
        }

        // The name printed on the PDF is decided the moment it is rendered.
        $tech = (string)($r['technician_name'] ?? '') ?: self::techName((int)$r['created_by'] ?: $userId);
        $r['technician_name'] = $tech;

        // The end of the installation is NOW — the moment the tech asks the
        // customer to sign. Stamped by the system so it cannot be back-dated.
        $r['finished_at'] = date('Y-m-d H:i:s');
        Db::pdo()->prepare('UPDATE install_reports SET finished_at = ? WHERE id = ?')
            ->execute([$r['finished_at'], $id]);

        $lang  = in_array($contact['lang'] ?? '', ['en', 'it'], true) ? (string)$contact['lang'] : 'it';
        $bytes = ReportPdf::build($r, self::photosWithBytes($id), $contact, $lang);

        $title = trim('Rapporto di installazione — ' . (string)$contact['name']
            . (trim((string)$r['machine_model']) !== '' ? ' — ' . trim((string)$r['machine_model']) : '')
            . (trim((string)$r['serial_number']) !== '' ? ' (' . trim((string)$r['serial_number']) . ')' : ''));

        $res = SignDocs::createFromBytes(
            ['title' => $title, 'contact_id' => (int)$r['contact_id'], 'lang' => $lang],
            $bytes, 'rapporto-installazione-' . $id . '.pdf', $userId
        );
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error']];
        }
        SignDocs::send((int)$res['id'], $userId);

        Db::pdo()->prepare(
            'UPDATE install_reports
             SET status = "sent", sent_at = NOW(), sign_document_id = ?, technician_name = ?
             WHERE id = ?'
        )->execute([(int)$res['id'], $tech, $id]);

        // The test-end notices are timed the moment the report is finalised —
        // after this the row is frozen, so the date can never drift under them.
        if (($r['report_type'] ?? '') === 'test' && !empty($r['test_end_date'])) {
            self::enqueueTestNotices($r, $contact);
        }

        Log::write('install', 'report_sent', 'install_report', $id,
            ['sign_document_id' => (int)$res['id'], 'contact_id' => (int)$r['contact_id']]);
        return ['ok' => true, 'error' => null];
    }

    /**
     * Admin cleanup. A signed report is a record and is refused; an unsigned
     * sign request is withdrawn so the link in the customer's chat dies too.
     */
    public static function delete(int $id, ?int $userId): bool
    {
        $r = self::find($id);
        if (!$r) {
            return false;
        }
        if (!empty($r['sign_document_id'])) {
            $doc = SignDocs::find((int)$r['sign_document_id']);
            if ($doc && $doc['status'] === 'signed') {
                return false;
            }
            if ($doc) {
                SignDocs::void((int)$doc['id'], $userId, 'installation report deleted');
            }
        }
        foreach (self::photos($id) as $p) {
            @unlink(self::uploadDir() . '/' . basename((string)$p['path']));
        }
        Db::pdo()->prepare('DELETE FROM install_report_photos WHERE report_id = ?')->execute([$id]);
        Db::pdo()->prepare('DELETE FROM install_reports WHERE id = ?')->execute([$id]);
        // A deleted test report must not still page anyone about its trial.
        Db::pdo()->prepare(
            "UPDATE reminders SET status = 'cancelled'
             WHERE status = 'pending' AND dedupe_key IN (?, ?)"
        )->execute(['test_end_customer:' . $id, 'test_end_company:' . $id]);
        Log::write('install', 'report_deleted', 'install_report', $id, []);
        return true;
    }

    // ---- reading ---------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT r.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    c.company AS customer_company, c.address, c.city, c.province,
                    d.status AS doc_status, d.uid AS doc_uid, d.signed_at AS doc_signed_at
             FROM install_reports r
             JOIN contacts c ON c.id = r.contact_id
             LEFT JOIN sign_documents d ON d.id = r.sign_document_id
             WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array> newest first; $ownerId scopes to one technician */
    public static function all(int $limit = 200, ?int $ownerId = null): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = 'SELECT r.*, c.name AS customer_name, u.username, u.full_name,
                       d.status AS doc_status, d.signed_at AS doc_signed_at
                FROM install_reports r
                JOIN contacts c ON c.id = r.contact_id
                LEFT JOIN users u ON u.id = r.created_by
                LEFT JOIN sign_documents d ON d.id = r.sign_document_id';
        $args = [];
        if ($ownerId !== null) {
            $sql .= ' WHERE r.created_by = ?';
            $args[] = $ownerId;
        }
        $sql .= " ORDER BY r.id DESC LIMIT $limit";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** The customer page's section: this contact's reports, newest first. */
    public static function forContact(int $contactId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT r.*, d.status AS doc_status, d.signed_at AS doc_signed_at
             FROM install_reports r
             LEFT JOIN sign_documents d ON d.id = r.sign_document_id
             WHERE r.contact_id = ? ORDER BY r.id DESC LIMIT 50'
        );
        $stmt->execute([$contactId]);
        return $stmt->fetchAll();
    }

    /**
     * What to show as the report's state: its own draft/sent, promoted to the
     * sign document's word (viewed/signed/declined/…) once one exists.
     */
    public static function displayStatus(array $r): string
    {
        if (($r['status'] ?? '') === 'draft') {
            return 'draft';
        }
        return (string)($r['doc_status'] ?? 'sent');
    }

    // ---- photos ----------------------------------------------------------------------

    public static function photos(int $reportId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM install_report_photos WHERE report_id = ? ORDER BY FIELD(kind, "ground", "final"), id'
        );
        $stmt->execute([$reportId]);
        return $stmt->fetchAll();
    }

    /**
     * Store the photos of a multi-file input (photos[]). Phone originals are
     * normalised to a bounded JPEG when GD is available — 5 MB HEIC-refugees
     * re-shot as 4000-px JPEGs would otherwise balloon the report PDF past what
     * the signing flow accepts. Without GD the original file is kept (a JPEG
     * still embeds; anything else is listed by name in the PDF).
     *
     * @param array $files the raw $_FILES entry
     * @return array{saved:int, errors:array<int,string>}
     */
    public static function addPhotos(int $reportId, string $kind, ?array $files): array
    {
        $kind = $kind === 'ground' ? 'ground' : 'final';
        $out = ['saved' => 0, 'errors' => []];
        $r = self::find($reportId);
        if (!$r || $r['status'] !== 'draft') { // a sent report is what was signed
            $out['errors'][] = 'not_draft';
            return $out;
        }
        foreach (self::filesList($files) as $f) {
            $err = null;
            $stored = self::storePhoto($f, $err);
            if ($stored === null) {
                if ($err !== null) {
                    $out['errors'][] = $err;
                }
                continue;
            }
            Db::pdo()->prepare(
                'INSERT INTO install_report_photos (report_id, kind, path, orig_name, bytes) VALUES (?,?,?,?,?)'
            )->execute([$reportId, $kind, $stored['path'], $stored['name'], $stored['bytes']]);
            $out['saved']++;
        }
        return $out;
    }

    /** Draft-time correction only; the file goes with the row. */
    public static function deletePhoto(int $photoId, int $reportId): bool
    {
        $r = self::find($reportId);
        if (!$r || $r['status'] !== 'draft') {
            return false;
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM install_report_photos WHERE id = ? AND report_id = ?');
        $stmt->execute([$photoId, $reportId]);
        $p = $stmt->fetch();
        if (!$p) {
            return false;
        }
        @unlink(self::uploadDir() . '/' . basename((string)$p['path']));
        Db::pdo()->prepare('DELETE FROM install_report_photos WHERE id = ?')->execute([$photoId]);
        return true;
    }

    /** A photo row + its report's owner, for the ?ipf= permission check. */
    public static function photoFile(int $photoId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT p.*, r.created_by, r.contact_id FROM install_report_photos p
             JOIN install_reports r ON r.id = p.report_id WHERE p.id = ?'
        );
        $stmt->execute([$photoId]);
        return $stmt->fetch() ?: null;
    }

    /** Stream a stored photo and exit. Call only after a permission check. */
    public static function streamPhoto(array $p): void
    {
        $path = self::uploadDir() . '/' . basename((string)$p['path']);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Not found');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext]
            ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

    /** @return array<int,array{bytes:string, kind:string, name:string}> for the PDF */
    public static function photosWithBytes(int $reportId): array
    {
        $out = [];
        foreach (self::photos($reportId) as $p) {
            $path = self::uploadDir() . '/' . basename((string)$p['path']);
            $bytes = is_file($path) ? (string)file_get_contents($path) : '';
            $out[] = ['bytes' => $bytes, 'kind' => (string)$p['kind'], 'name' => (string)($p['orig_name'] ?: $p['path'])];
        }
        return $out;
    }

    /** Same rule as ticket attachments: storage/ preferred, blocked public/ fallback. */
    public static function uploadDir(): string
    {
        $root = dirname(__DIR__, 2);
        $preferred = $root . '/storage/uploads/install';
        if (is_dir($preferred) || @mkdir($preferred, 0775, true)) {
            return $preferred;
        }
        $fallback = $root . '/public/uploads/install';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }
        return $fallback;
    }

    // ---- internals -------------------------------------------------------------------

    /** Flatten PHP's multi-file $_FILES shape into one array per file. */
    private static function filesList(?array $files): array
    {
        if (!$files || !isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) { // single-file input posted the old way
            return (int)($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
        }
        $out = [];
        foreach ($files['name'] as $i => $name) {
            if ((int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string)$name,
                'type'     => (string)($files['type'][$i] ?? ''),
                'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
                'error'    => (int)($files['error'][$i] ?? UPLOAD_ERR_OK),
                'size'     => (int)($files['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    /** @return array{path:string, name:string, bytes:int}|null */
    private static function storePhoto(array $f, ?string &$err): ?array
    {
        $err = null;
        if ((int)$f['error'] !== UPLOAD_ERR_OK) {
            $err = in_array((int)$f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'too_big' : 'save_failed';
            return null;
        }
        if ((int)$f['size'] <= 0 || (int)$f['size'] > self::PHOTO_MAX_BYTES) {
            $err = 'too_big';
            return null;
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::PHOTO_EXT, true)) {
            $err = 'bad_type';
            return null;
        }
        $tmp = (string)$f['tmp_name'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $err = 'save_failed';
            return null;
        }
        $info = @getimagesize($tmp);
        if ($info === false) { // extension lied, or a format PHP can't read (HEIC)
            $err = 'bad_type';
            return null;
        }

        $dir = self::uploadDir();
        $normalized = self::normalizeJpeg($tmp, $info);
        if ($normalized !== null) {
            $stored = bin2hex(random_bytes(16)) . '.jpg';
            if (file_put_contents($dir . '/' . $stored, $normalized, LOCK_EX) === false) {
                $err = 'save_failed';
                return null;
            }
        } else { // no GD (or undecodable): keep the original bytes
            $stored = bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
                $err = 'save_failed';
                return null;
            }
        }
        @chmod($dir . '/' . $stored, 0640);
        return [
            'path'  => $stored,
            'name'  => mb_substr((string)$f['name'], 0, 190),
            'bytes' => (int)filesize($dir . '/' . $stored),
        ];
    }

    /**
     * Bounded, upright JPEG via GD, or null to keep the original. EXIF
     * orientation is applied here because GD strips the tag: without this,
     * every portrait phone photo lies on its side in the report.
     */
    private static function normalizeJpeg(string $tmp, array $info): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $raw = (string)file_get_contents($tmp);
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            return null;
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            switch ((int)($exif['Orientation'] ?? 1)) {
                case 3: $img = imagerotate($img, 180, 0); break;
                case 6: $img = imagerotate($img, -90, 0); break;
                case 8: $img = imagerotate($img, 90, 0); break;
            }
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $max = max($w, $h);
        if ($max > self::PHOTO_MAX_PX) {
            $scale = self::PHOTO_MAX_PX / $max;
            $img = imagescale($img, (int)round($w * $scale), (int)round($h * $scale),
                defined('IMG_BICUBIC') ? IMG_BICUBIC : IMG_BILINEAR_FIXED) ?: $img;
        }
        ob_start();
        imagejpeg($img, null, 80);
        $jpeg = (string)ob_get_clean();
        imagedestroy($img);
        return $jpeg !== '' ? $jpeg : null;
    }

    /**
     * The two end-of-test notices, due TEST_NOTICE_DAYS before the trial ends
     * (at 10:00, so nobody's phone buzzes at midnight; a date already inside
     * the window fires on the next scheduler tick instead of never). One to
     * the customer, one to the company — the logistics contact from Settings,
     * or the first active admin with a reachable channel when that is empty.
     */
    private static function enqueueTestNotices(array $r, array $contact): void
    {
        $id   = (int)$r['id'];
        $due  = date('Y-m-d H:i:s', max(time(),
            strtotime((string)$r['test_end_date'] . ' 10:00:00') - self::TEST_NOTICE_DAYS * 86400));
        $vars = [
            'model'  => trim((string)($r['machine_model'] ?? '')) ?: '—',
            'serial' => trim((string)($r['serial_number'] ?? '')) ?: '—',
            'date'   => date('d/m/Y', strtotime((string)$r['test_end_date'])),
            'days'   => (string)self::TEST_NOTICE_DAYS,
        ];

        (new \Glue\Reminder\Scheduler())->enqueue([
            'entity_type'    => 'contact',
            'entity_id'      => (int)$r['contact_id'],
            'rule_key'       => 'test_end_customer',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => $due,
            'payload'        => $vars,
            'lang'           => $contact['lang'] ?? null,
            'dedupe_key'     => 'test_end_customer:' . $id,
        ]);

        $co = self::companyContact();
        if ($co !== null) {
            (new \Glue\Reminder\Scheduler())->enqueue([
                'entity_type'    => 'contact',
                'entity_id'      => (int)$r['contact_id'],
                'rule_key'       => 'test_end_company',
                'recipient_type' => 'agent',
                'channel'        => 'both',
                'due_at'         => $due,
                // payload wins over the resolver, so it carries who to reach
                'payload'        => $co + $vars + [
                    'customer_name'  => (string)($contact['name'] ?? ''),
                    'customer_phone' => (string)($contact['phone'] ?? ''),
                ],
                'dedupe_key'     => 'test_end_company:' . $id,
            ]);
        } else {
            Log::write('install', 'test_notice_no_company_contact', 'install_report', $id, []);
        }
    }

    /** @return array{agent_name:string, agent_phone:string, agent_email:string}|null */
    private static function companyContact(): ?array
    {
        $phone = trim((string)Config::get('logistics.phone', ''));
        $email = trim((string)Config::get('logistics.email', ''));
        $name  = (string)Config::get('app.company_name', 'CRM');
        if ($phone === '' && $email === '') {
            $stmt = Db::pdo()->query(
                "SELECT full_name, username, phone, email FROM users
                 WHERE role = 'admin' AND active = 1
                   AND (COALESCE(phone,'') <> '' OR COALESCE(email,'') <> '')
                 ORDER BY id LIMIT 1"
            );
            $u = $stmt->fetch();
            if (!$u) {
                return null;
            }
            $name  = trim((string)$u['full_name']) ?: (string)$u['username'];
            $phone = (string)($u['phone'] ?? '');
            $email = (string)($u['email'] ?? '');
        }
        return ['agent_name' => $name, 'agent_phone' => $phone, 'agent_email' => $email];
    }

    private static function techName(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT COALESCE(NULLIF(full_name, ""), username) FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string)($stmt->fetchColumn() ?: '') ?: null;
    }

    private static function s(string $v, int $len): ?string
    {
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, $len);
    }

    /** A bare date input ("2026-09-20"); normalise, refuse garbage. */
    private static function date(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** datetime-local posts "2026-09-05T17:47"; normalise, refuse garbage. */
    private static function dt(string $v): ?string
    {
        $v = trim(str_replace('T', ' ', $v));
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
