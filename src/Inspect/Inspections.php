<?php
declare(strict_types=1);

namespace Glue\Inspect;

use Glue\Config;
use Glue\Crm\Contacts;
use Glue\Db;
use Glue\Event\Log;
use Glue\Install\Reports as InstallReports;
use Glue\Notify\StaffAlert;
use Glue\Reminder\Scheduler;
use Glue\Reminder\Templates;
use Glue\Sign\Documents as SignDocs;

/**
 * Surveys (sopralluoghi) — the technician's site report, and the opinion the
 * technical group writes on top of it.
 *
 * The first half is the installation report's, deliberately: a sheet filled in
 * on site becomes a PDF and goes through the existing Sign\Documents flow, so
 * the customer signs it with a one-time code from their own phone and the
 * sealed copy lands in their portal like every other signed document.
 *
 * The second half is what makes a survey different. A signed survey is not
 * finished — it is a question. onSigned() hands it to the technical group, one
 * of them takes it in charge, writes an opinion on the state of the system with
 * a rating out of five, and that opinion goes to the customer on WhatsApp and
 * email. Four states, in order: draft, sent, signed, reviewed.
 */
final class Inspections
{
    public const STARS_MIN = 1;
    public const STARS_MAX = 5;

    /**
     * What the technician walks round and ticks off, in the order the sheet
     * shows them. Each is a yes/no plus a line of description.
     *
     * The codes are stored; the labels are translated (isp_it_<lowercase code>
     * in lang/ui.*.php). Adding a sixth item means one entry here and one label
     * in each language — no migration, because the answers live in their own
     * table keyed on the code.
     */
    public const ITEMS = ['RACK', 'UPS', 'ROUTER', 'SWITCH', 'AP'];

    // ---- lifecycle -------------------------------------------------------------------

    /** Open a draft on a customer. */
    public static function create(int $contactId, ?int $userId): int
    {
        Db::pdo()->prepare(
            'INSERT INTO inspections (contact_id, technician_id, technician_name, inspected_at, created_by)
             VALUES (?, ?, ?, NOW(), ?)'
        )->execute([$contactId, $userId ?: null, self::techName($userId), $userId ?: null]);
        $id = (int)Db::pdo()->lastInsertId();
        Log::write('inspect', 'created', 'inspection', $id, ['contact_id' => $contactId]);
        return $id;
    }

    /** Editable while draft only — a sent survey is what the customer signed. */
    public static function update(int $id, array $d): bool
    {
        $r = self::find($id);
        if (!$r || $r['status'] !== 'draft') {
            return false;
        }
        Db::pdo()->prepare(
            'UPDATE inspections SET
                inspected_at = ?, site_address = ?, findings = ?, works_needed = ?, notes = ?
             WHERE id = ?'
        )->execute([
            self::dt($d['inspected_at'] ?? ''),
            self::s($d['site_address'] ?? '', 190),
            trim((string)($d['findings'] ?? '')) ?: null,
            trim((string)($d['works_needed'] ?? '')) ?: null,
            trim((string)($d['notes'] ?? '')) ?: null,
            $id,
        ]);
        self::saveItems($id, $d);
        return true;
    }

    /**
     * The checklist: one row per item, written every save.
     *
     * A description is kept even when the box is unticked — "non presente, il
     * cliente ne vuole uno" is exactly the kind of note a survey exists to
     * carry, and clearing it because the tick is off would throw it away.
     *
     * @param array $d the posted form: items[CODE] (tick) and item_note[CODE]
     */
    public static function saveItems(int $id, array $d): void
    {
        $ticks = (array)($d['items'] ?? []);
        $notes = (array)($d['item_note'] ?? []);
        $stmt = Db::pdo()->prepare(
            'INSERT INTO inspection_items (inspection_id, code, present, note) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE present = VALUES(present), note = VALUES(note)'
        );
        foreach (self::ITEMS as $code) {
            $stmt->execute([
                $id,
                $code,
                !empty($ticks[$code]) ? 1 : 0,
                self::s((string)($notes[$code] ?? ''), 190),
            ]);
        }
    }

    /**
     * The checklist as the sheet and the PDF read it: every item, in order,
     * whether or not it has been answered yet.
     *
     * @return array<int,array{code:string, present:bool, note:string}>
     */
    public static function items(int $id): array
    {
        $stmt = Db::pdo()->prepare('SELECT code, present, note FROM inspection_items WHERE inspection_id = ?');
        $stmt->execute([$id]);
        $have = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $have[(string)$row['code']] = $row;
        }
        $out = [];
        foreach (self::ITEMS as $code) {
            $out[] = [
                'code'    => $code,
                'present' => (int)($have[$code]['present'] ?? 0) === 1,
                'note'    => (string)($have[$code]['note'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Render, file with the signing flow, and message the customer the link.
     *
     * @return array{ok:bool, error:?string} not_draft | no_contact | no_channel | …
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

        $tech = (string)($r['technician_name'] ?? '') ?: self::techName((int)$r['created_by'] ?: $userId);
        $r['technician_name'] = $tech;

        $lang  = in_array($contact['lang'] ?? '', ['en', 'it'], true) ? (string)$contact['lang'] : 'it';
        $bytes = InspectionPdf::build($r, self::photosWithBytes($id), $contact, $lang, self::items($id));

        $title = trim('Rapporto di sopralluogo — ' . (string)$contact['name']);

        $res = SignDocs::createFromBytes(
            ['title' => $title, 'contact_id' => (int)$r['contact_id'], 'lang' => $lang],
            $bytes, 'sopralluogo-' . $id . '.pdf', $userId
        );
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error']];
        }
        SignDocs::send((int)$res['id'], $userId);

        Db::pdo()->prepare(
            'UPDATE inspections SET status = "sent", sent_at = NOW(), sign_document_id = ?, technician_name = ?
             WHERE id = ?'
        )->execute([(int)$res['id'], $tech, $id]);

        Log::write('inspect', 'sent', 'inspection', $id,
            ['sign_document_id' => (int)$res['id'], 'contact_id' => (int)$r['contact_id']]);
        return ['ok' => true, 'error' => null];
    }

    /**
     * The customer signed. Called from Sign\Documents when a document seals; a
     * no-op for every document that is not a survey.
     *
     * This is where a survey stops being paperwork and becomes a question for
     * the technical group, so it is also where they are told about it.
     */
    public static function onSigned(int $signDocumentId): void
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM inspections WHERE sign_document_id = ? AND status = "sent" LIMIT 1'
        );
        $stmt->execute([$signDocumentId]);
        $r = $stmt->fetch();
        if (!$r) {
            return;
        }
        $id = (int)$r['id'];
        Db::pdo()->prepare('UPDATE inspections SET status = "signed", signed_at = NOW() WHERE id = ?')
            ->execute([$id]);

        $customer = (string)(Contacts::find((int)$r['contact_id'])['name'] ?? '');
        $link = rtrim((string)Config::appBaseUrl(), '/') . '/dashboard.php?tab=inspections&id=' . $id;
        $text = '🔎 ' . (string)Config::get('app.company_name', 'CRM')
            . " — sopralluogo firmato da {$customer} (#{$id}). "
            . "Va preso in carico e va espresso il parere sullo stato dell'impianto:\n{$link}";
        $html = '<p>🔎 Sopralluogo firmato da <b>' . htmlspecialchars($customer, ENT_QUOTES) . '</b> (#' . $id . ').</p>'
            . '<p>Va preso in carico e va espresso il parere sullo stato dell\'impianto.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Apri il sopralluogo</a></p>';

        // Every technician, exactly like an assistance request: the first one to
        // take it in charge owns it. Queued — sealing a document must never wait
        // on a WhatsApp, and this runs inside the customer's signing request.
        if (StaffAlert::toRole('tech', 'inspection_to_review', $text,
                'Sopralluogo da valutare — ' . $customer, $html, 'inspection', $id) === 0) {
            StaffAlert::toRole(StaffAlert::OFFICE, 'inspection_to_review', $text,
                'Sopralluogo da valutare — ' . $customer, $html, 'inspection', $id);
        }
        Log::write('inspect', 'signed', 'inspection', $id, ['sign_document_id' => $signDocumentId]);
    }

    /**
     * One technician takes it in charge. Atomic: the UPDATE only wins on a row
     * nobody holds, so two of them pressing together cannot both start writing.
     */
    public static function claim(int $id, int $userId): bool
    {
        $stmt = Db::pdo()->prepare(
            'UPDATE inspections SET claimed_by = ?, claimed_at = NOW()
              WHERE id = ? AND status = "signed" AND claimed_by IS NULL'
        );
        $stmt->execute([$userId, $id]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        Log::write('inspect', 'claimed', 'inspection', $id, ['by' => $userId]);
        return true;
    }

    /** Save the opinion without sending it — a draft the technician comes back to. */
    public static function saveOpinion(int $id, string $text, int $stars, ?int $userId): bool
    {
        $r = self::find($id);
        if (!$r || !in_array((string)$r['status'], ['signed', 'reviewed'], true)) {
            return false;
        }
        Db::pdo()->prepare(
            'UPDATE inspections SET opinion_text = ?, opinion_stars = ?, opinion_by = ?, opinion_at = NOW()
              WHERE id = ?'
        )->execute([
            trim($text) ?: null,
            self::clampStars($stars) ?: null,
            $userId ?: null,
            $id,
        ]);
        return true;
    }

    /**
     * Send the opinion to the customer and close the survey.
     *
     * @return array{ok:bool, error:?string} not_ready | no_text | no_stars | no_channel
     */
    public static function sendOpinion(int $id, ?int $userId): array
    {
        $r = self::find($id);
        if (!$r || (string)$r['status'] !== 'signed') {
            return ['ok' => false, 'error' => 'not_ready'];
        }
        $text  = trim((string)($r['opinion_text'] ?? ''));
        $stars = self::clampStars((int)($r['opinion_stars'] ?? 0));
        if ($text === '') {
            return ['ok' => false, 'error' => 'no_text'];
        }
        if ($stars === 0) {
            return ['ok' => false, 'error' => 'no_stars'];
        }
        $contact = Contacts::find((int)$r['contact_id']);
        if (!$contact) {
            return ['ok' => false, 'error' => 'no_contact'];
        }
        $phone = trim((string)($contact['phone'] ?? ''));
        $email = trim((string)($contact['email'] ?? ''));
        if ($phone === '' && $email === '') {
            return ['ok' => false, 'error' => 'no_channel'];
        }
        $channel = $phone !== '' && $email !== '' ? 'both' : ($phone !== '' ? 'whatsapp' : 'email');
        $lang = Templates::lang($contact['lang'] ?? null);

        // The customer reads what the system needs; this is how they say yes.
        // Minted here and not at survey time: a survey with no opinion has
        // nothing to quote for.
        $token = (string)($r['offer_token'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            Db::pdo()->prepare('UPDATE inspections SET offer_token = ? WHERE id = ?')->execute([$token, $id]);
        }

        (new Scheduler())->enqueue([
            'entity_type'    => 'contact',
            'entity_id'      => (int)$r['contact_id'],
            'rule_key'       => 'inspection_opinion',
            'recipient_type' => 'customer',
            'channel'        => $channel,
            'due_at'         => date('Y-m-d H:i:s'),
            'lang'           => $lang,
            'payload'        => [
                'stars'   => self::starBar($stars),
                'rating'  => $stars . '/' . self::STARS_MAX,
                'opinion' => $text,
                'id'      => (string)$id,
                'link'    => self::offerLink($token),
            ],
            'dedupe_key'     => 'inspection_opinion:' . $id,
        ]);

        Db::pdo()->prepare(
            'UPDATE inspections SET status = "reviewed", opinion_sent_at = NOW(), opinion_channel = ?,
                    opinion_by = COALESCE(opinion_by, ?), opinion_at = COALESCE(opinion_at, NOW())
              WHERE id = ?'
        )->execute([$channel, $userId ?: null, $id]);

        Log::write('inspect', 'opinion_sent', 'inspection', $id,
            ['stars' => $stars, 'channel' => $channel, 'by' => $userId]);
        return ['ok' => true, 'error' => null];
    }

    // ---- the offer request -----------------------------------------------------------

    public static function offerLink(string $token): string
    {
        return rtrim((string)Config::appBaseUrl(), '/') . '/offerta.php?t=' . $token;
    }

    /** The survey a live offer token belongs to, with its customer. */
    public static function byOfferToken(string $token): ?array
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT i.*, c.name AS customer_name, c.company
               FROM inspections i JOIN contacts c ON c.id = i.contact_id
              WHERE i.offer_token = ? AND i.status = "reviewed" LIMIT 1'
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The customer asks for a quote to put the system right.
     *
     * Idempotent: pressing the button twice — or opening the link again on a
     * second phone — records the first ask and does not page the group again.
     *
     * @return array{ok:bool, already:bool}
     */
    public static function requestOffer(string $token, string $note): array
    {
        $r = self::byOfferToken($token);
        if (!$r) {
            return ['ok' => false, 'already' => false];
        }
        $id = (int)$r['id'];
        if (!empty($r['offer_requested_at'])) {
            return ['ok' => true, 'already' => true];
        }
        Db::pdo()->prepare('UPDATE inspections SET offer_requested_at = NOW(), offer_note = ? WHERE id = ?')
            ->execute([trim($note) ?: null, $id]);

        self::alertReviewGroup($r, trim($note));
        Log::write('inspect', 'offer_requested', 'inspection', $id, ['contact_id' => (int)$r['contact_id']]);
        return ['ok' => true, 'already' => false];
    }

    /**
     * The verification group: accounts ticked "gruppo di verifica", whatever
     * their role — verifying a system is a job, not a rank.
     *
     * Falls back to the office when nobody is ticked yet. A customer's request
     * for work must never land nowhere because a checkbox has not been set.
     *
     * @return array<int,int> user ids
     */
    public static function reviewGroup(): array
    {
        $ids = Db::pdo()->query(
            'SELECT id FROM users WHERE active = 1 AND in_review_group = 1 ORDER BY id'
        )->fetchAll(\PDO::FETCH_COLUMN);
        if ($ids) {
            return array_map('intval', $ids);
        }
        return array_map('intval', Db::pdo()->query(
            "SELECT id FROM users WHERE active = 1 AND role IN ('admin','office') ORDER BY id"
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function alertReviewGroup(array $r, string $note): void
    {
        $who  = (string)($r['customer_name'] ?? '');
        $link = rtrim((string)Config::appBaseUrl(), '/') . '/dashboard.php?tab=inspections&id=' . (int)$r['id'];
        $stars = self::starBar((int)($r['opinion_stars'] ?? 0));
        $text = '💶 ' . (string)Config::get('app.company_name', 'CRM')
            . " — {$who} chiede un'offerta per la sistemazione dell'impianto "
            . "dopo il sopralluogo #" . (int)$r['id'] . " ({$stars})."
            . ($note !== '' ? "\n«{$note}»" : '')
            . "\nApri il sopralluogo: {$link}";
        $html = '<p>💶 <b>' . htmlspecialchars($who, ENT_QUOTES) . '</b> chiede un\'offerta per la '
            . 'sistemazione dell\'impianto dopo il sopralluogo #' . (int)$r['id']
            . ' (' . htmlspecialchars($stars, ENT_QUOTES) . ').</p>'
            . ($note !== '' ? '<blockquote>' . nl2br(htmlspecialchars($note, ENT_QUOTES)) . '</blockquote>' : '')
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Apri il sopralluogo</a></p>';

        StaffAlert::toUserIds(self::reviewGroup(), 'inspection_offer_request', $text,
            'Richiesta di offerta — ' . $who, $html, 'inspection', (int)$r['id']);
    }

    // ---- photos ----------------------------------------------------------------------

    /** @return array{saved:int, errors:array<int,string>} */
    public static function addPhotos(int $id, ?array $files): array
    {
        $out = ['saved' => 0, 'errors' => []];
        $r = self::find($id);
        if (!$r || $r['status'] !== 'draft') { // a sent survey is what was signed
            $out['errors'][] = 'not_draft';
            return $out;
        }
        // Same handling as an installation report's photos: the same phones take
        // them and hit the same problems. See Install\Reports::storeUploads.
        $res = InstallReports::storeUploads($files, self::uploadDir());
        $ins = Db::pdo()->prepare(
            'INSERT INTO inspection_photos (inspection_id, path, orig_name, bytes) VALUES (?,?,?,?)'
        );
        foreach ($res['saved'] as $p) {
            $ins->execute([$id, $p['path'], $p['name'], $p['bytes']]);
            $out['saved']++;
        }
        $out['errors'] = $res['errors'];
        return $out;
    }

    public static function deletePhoto(int $id, int $photoId): bool
    {
        $r = self::find($id);
        if (!$r || $r['status'] !== 'draft') {
            return false;
        }
        $q = Db::pdo()->prepare('SELECT path FROM inspection_photos WHERE id = ? AND inspection_id = ?');
        $q->execute([$photoId, $id]);
        $path = (string)($q->fetchColumn() ?: '');
        if ($path === '') {
            return false;
        }
        @unlink(self::uploadDir() . '/' . basename($path));
        Db::pdo()->prepare('DELETE FROM inspection_photos WHERE id = ?')->execute([$photoId]);
        return true;
    }

    /** @return array<int,array> */
    public static function photos(int $id): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM inspection_photos WHERE inspection_id = ? ORDER BY id');
        $stmt->execute([$id]);
        return $stmt->fetchAll() ?: [];
    }

    /** @return array<int,array{bytes:string, name:string}> */
    public static function photosWithBytes(int $id): array
    {
        $out = [];
        foreach (self::photos($id) as $p) {
            $path = self::uploadDir() . '/' . basename((string)$p['path']);
            $out[] = [
                'bytes' => is_file($path) ? (string)file_get_contents($path) : '',
                'name'  => (string)($p['orig_name'] ?: $p['path']),
            ];
        }
        return $out;
    }

    /** The photo row plus who may see it, for the ?ispf= door. */
    public static function photoFile(int $photoId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT p.*, i.created_by, i.claimed_by, i.status, i.contact_id
               FROM inspection_photos p JOIN inspections i ON i.id = p.inspection_id
              WHERE p.id = ?'
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

    public static function uploadDir(): string
    {
        $root = dirname(__DIR__, 2);
        $preferred = $root . '/storage/uploads/inspect';
        if (is_dir($preferred) || @mkdir($preferred, 0775, true)) {
            return $preferred;
        }
        $fallback = $root . '/public/uploads/inspect';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }
        return $fallback;
    }

    // ---- reads -----------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM inspections WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param ?int $ownerId scope to one technician: the surveys they opened, and
     *        the ones waiting for an opinion (which are everybody's until claimed)
     * @return array<int,array>
     */
    public static function all(int $limit = 200, ?int $ownerId = null): array
    {
        $limit = max(1, min(500, $limit));
        $sql =
            "SELECT i.*, c.name AS customer_name, c.company, c.phone, c.email,
                    u.full_name AS claimer_name, u.username AS claimer_username,
                    d.status AS sign_status, d.uid AS sign_uid
               FROM inspections i
               JOIN contacts c ON c.id = i.contact_id
               LEFT JOIN users u ON u.id = i.claimed_by
               LEFT JOIN sign_documents d ON d.id = i.sign_document_id";
        $args = [];
        if ($ownerId !== null) {
            $sql .= ' WHERE (i.created_by = ? OR i.claimed_by = ? OR i.status = "signed")';
            $args = [$ownerId, $ownerId];
        }
        $sql .= " ORDER BY (i.status = 'signed') DESC, i.id DESC LIMIT $limit";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll() ?: [];
    }

    /** Surveys on one customer, for their record. */
    public static function forContact(int $contactId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT i.*, d.status AS sign_status, d.uid AS sign_uid
               FROM inspections i
               LEFT JOIN sign_documents d ON d.id = i.sign_document_id
              WHERE i.contact_id = ? ORDER BY i.id DESC'
        );
        $stmt->execute([$contactId]);
        return $stmt->fetchAll() ?: [];
    }

    /** How many are waiting for an opinion — the badge on the tab. */
    public static function awaitingCount(): int
    {
        return (int)Db::pdo()->query('SELECT COUNT(*) FROM inspections WHERE status = "signed"')->fetchColumn();
    }

    public static function clampStars(int $n): int
    {
        return ($n >= self::STARS_MIN && $n <= self::STARS_MAX) ? $n : 0;
    }

    /** ★★★★☆ — what the customer actually reads in a WhatsApp. */
    public static function starBar(int $n): string
    {
        $n = self::clampStars($n);
        return $n === 0 ? '' : str_repeat('★', $n) . str_repeat('☆', self::STARS_MAX - $n);
    }

    // ---- internals -------------------------------------------------------------------

    private static function techName(?int $userId): string
    {
        if (!$userId) {
            return '';
        }
        $s = Db::pdo()->prepare("SELECT COALESCE(NULLIF(TRIM(full_name), ''), username) FROM users WHERE id = ?");
        $s->execute([$userId]);
        return (string)($s->fetchColumn() ?: '');
    }

    private static function s(string $v, int $max): ?string
    {
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function dt(string $v): ?string
    {
        $ts = $v !== '' ? strtotime($v) : false;
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
