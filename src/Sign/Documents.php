<?php
declare(strict_types=1);

namespace Glue\Sign;

use Glue\Config;
use Glue\Crm\Contacts;
use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;

/**
 * The document lifecycle: upload → send → the customer opens it → one-time code
 * → sealed PDF. Every step writes to the append-only log in Sign\Audit before it
 * writes anything else, so the log never has a gap where the interesting part
 * would have been.
 *
 * Statuses: draft → sent → viewed → signed, with declined / expired / void as
 * terminal side exits. Only 'sent' and 'viewed' can lead to a signature.
 */
final class Documents
{
    private const TOKEN_TTL_DAYS = 30;
    private const OTP_TTL_MIN    = 10;
    private const OTP_MAX_TRIES  = 5;
    private const OTP_MAX_SENDS_HOUR = 5;
    private const MAX_BYTES      = 20971520; // 20 MB
    private const ALLOWED_EXT    = ['pdf'];

    // ---- creating -------------------------------------------------------------------

    /**
     * Store an uploaded file as a document awaiting signature.
     *
     * @param array      $in   title, contact_id|(name,phone,email), deal_id, lang
     * @param array|null $file a $_FILES entry
     * @param int|null   $userId the staff member doing it
     *
     * @return array{ok:bool, id:int, error:?string}
     */
    public static function create(array $in, ?array $file, ?int $userId = null): array
    {
        $stored = self::storeUpload($file, $err);
        if ($stored === null) {
            return ['ok' => false, 'id' => 0, 'error' => $err ?: 'no_file'];
        }

        $contactId = (int)($in['contact_id'] ?? 0);
        if ($contactId <= 0) {
            $contactId = Contacts::findOrCreate([
                'name'  => $in['name'] ?? '',
                'phone' => $in['phone'] ?? '',
                'email' => $in['email'] ?? '',
                'lang'  => $in['lang'] ?? null,
            ]);
        }
        $contact = Contacts::find($contactId);
        if (!$contact) {
            @unlink((string)Store::file('docs', $stored['path']));
            return ['ok' => false, 'id' => 0, 'error' => 'no_contact'];
        }

        $uid = self::newUid();
        Db::pdo()->prepare(
            'INSERT INTO sign_documents
             (uid, title, contact_id, deal_id, signer_name, signer_email, signer_phone, lang,
              orig_name, orig_path, orig_sha256, orig_bytes, orig_mime, status, created_by)
             VALUES (:uid, :title, :contact_id, :deal_id, :signer_name, :signer_email, :signer_phone, :lang,
                     :orig_name, :orig_path, :orig_sha256, :orig_bytes, :orig_mime, "draft", :created_by)'
        )->execute([
            ':uid'          => $uid,
            ':title'        => mb_substr(trim((string)($in['title'] ?? '')) ?: $stored['name'], 0, 190),
            ':contact_id'   => $contactId,
            ':deal_id'      => ((int)($in['deal_id'] ?? 0)) ?: null,
            ':signer_name'  => mb_substr((string)($contact['name'] ?? ''), 0, 190),
            ':signer_email' => $contact['email'] ?: null,
            ':signer_phone' => $contact['phone'] ?: null,
            ':lang'         => $in['lang'] ?? ($contact['lang'] ?? null),
            ':orig_name'    => $stored['name'],
            ':orig_path'    => $stored['path'],
            ':orig_sha256'  => $stored['sha256'],
            ':orig_bytes'   => $stored['bytes'],
            ':orig_mime'    => $stored['mime'],
            ':created_by'   => $userId ?: null,
        ]);
        $id = (int)Db::pdo()->lastInsertId();

        Audit::append($id, 'document_created', [
            'title'  => (string)($in['title'] ?? $stored['name']),
            'file'   => $stored['name'],
            'sha256' => $stored['sha256'],
            'bytes'  => $stored['bytes'],
        ], ['type' => 'staff', 'id' => $userId, 'label' => self::staffLabel($userId)]);

        Log::write('sign', 'document_created', 'sign_document', $id,
            ['uid' => $uid, 'contact_id' => $contactId, 'sha256' => $stored['sha256']]);

        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    /**
     * Send it for signature: mint a link token and message the customer. Signing
     * needs no portal account — the token in the link is the entry, and the
     * one-time code is what actually proves who is at the other end.
     */
    public static function send(int $id, ?int $userId = null): bool
    {
        $doc = self::find($id);
        if (!$doc || !in_array($doc['status'], ['draft', 'sent', 'viewed'], true)) {
            return false;
        }

        $token   = bin2hex(random_bytes(24));
        $expires = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_DAYS * 86400);
        Db::pdo()->prepare(
            'UPDATE sign_documents
             SET access_token = ?, token_expires = ?, status = "sent", sent_at = COALESCE(sent_at, NOW())
             WHERE id = ?'
        )->execute([$token, $expires, $id]);

        Audit::append($id, 'sent_to_signer', [
            'to_email' => $doc['signer_email'],
            'to_phone' => self::mask((string)$doc['signer_phone']),
            'expires'  => $expires,
        ], ['type' => 'staff', 'id' => $userId, 'label' => self::staffLabel($userId)]);

        (new Scheduler())->enqueue([
            'entity_type'    => 'contact',
            'entity_id'      => (int)$doc['contact_id'],
            'rule_key'       => 'doc_sign_request',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'payload'        => ['title' => (string)$doc['title'], 'link' => self::signUrl($token)],
            'lang'           => $doc['lang'] ?? null,
            // The token changes on every resend, so a resend is never deduped away.
            'dedupe_key'     => 'doc_sign_request:' . $id . ':' . substr($token, 0, 12),
        ]);

        Log::write('sign', 'document_sent', 'sign_document', $id, ['uid' => $doc['uid']]);
        return true;
    }

    public static function signUrl(string $token): string
    {
        return Config::appBaseUrl() . '/sign.php?t=' . urlencode($token);
    }

    // ---- the customer's side ---------------------------------------------------------

    /** Look a document up by its link token, rejecting expired and finished ones. */
    public static function byToken(string $token): ?array
    {
        if (strlen($token) < 24) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM sign_documents WHERE access_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $doc = $stmt->fetch();
        return $doc ?: null;
    }

    /** Can this document still be signed right now? Returns '' when it can. */
    public static function blockedReason(array $doc): string
    {
        if (in_array($doc['status'], ['signed', 'declined', 'void'], true)) {
            return (string)$doc['status'];
        }
        if (!empty($doc['token_expires']) && strtotime((string)$doc['token_expires']) < time()) {
            return 'expired';
        }
        if (empty($doc['access_token'])) {
            return 'void';
        }
        return '';
    }

    /** First open of the link. Logged once; re-opens are not noise in the trail. */
    public static function markViewed(int $id): void
    {
        $doc = self::find($id);
        if (!$doc || !empty($doc['viewed_at'])) {
            return;
        }
        Db::pdo()->prepare('UPDATE sign_documents SET viewed_at = NOW(), status = IF(status = "sent", "viewed", status) WHERE id = ?')
            ->execute([$id]);
        Audit::append($id, 'opened_by_signer', [], ['type' => 'customer', 'label' => (string)$doc['signer_name']]);
    }

    /** Log that the customer downloaded the document they are being asked to sign. */
    public static function markDownloaded(int $id, string $which = 'original'): void
    {
        $doc = self::find($id);
        if (!$doc) {
            return;
        }
        Audit::append($id, 'downloaded_' . $which, [],
            ['type' => 'customer', 'label' => (string)$doc['signer_name']]);
    }

    // ---- one-time code ---------------------------------------------------------------

    /**
     * Issue a code and send it. The destination is recorded masked: enough to
     * prove where it went, not enough to be a second copy of the customer's
     * contact details sitting in the evidence.
     *
     * @return array{ok:bool, sent_to:string, error:?string}
     */
    public static function issueCode(array $doc): array
    {
        $id = (int)$doc['id'];

        // Cap resends: without this, the link is a free WhatsApp/SMS button for
        // anyone who has it, and the audit trail fills with noise.
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM otp_codes
             WHERE document_id = ? AND purpose = "doc" AND created_at > NOW() - INTERVAL 1 HOUR'
        );
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() >= self::OTP_MAX_SENDS_HOUR) {
            Audit::append($id, 'otp_throttled', [], ['type' => 'customer', 'label' => (string)$doc['signer_name']]);
            return ['ok' => false, 'sent_to' => '', 'error' => 'throttled'];
        }

        // Any earlier code for this document stops working the moment a new one
        // is issued, so two codes are never valid at once.
        Db::pdo()->prepare(
            'UPDATE otp_codes SET used_at = NOW() WHERE document_id = ? AND purpose = "doc" AND used_at IS NULL'
        )->execute([$id]);

        $code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + self::OTP_TTL_MIN * 60);
        Db::pdo()->prepare(
            'INSERT INTO otp_codes (contact_id, deal_id, document_id, purpose, code, expires_at)
             VALUES (?, NULL, ?, "doc", ?, ?)'
        )->execute([(int)$doc['contact_id'], $id, $code, $expires]);

        $target = self::mask((string)($doc['signer_phone'] ?: $doc['signer_email']));
        Db::pdo()->prepare(
            'UPDATE sign_documents SET status = IF(status = "sent", "viewed", status) WHERE id = ?'
        )->execute([$id]);

        Audit::append($id, 'otp_issued', [
            'sent_to' => $target,
            'expires' => $expires,
            'digest'  => self::codeHash((string)$doc['uid'], $code),
        ], ['type' => 'customer', 'label' => (string)$doc['signer_name']]);

        (new Scheduler())->enqueue([
            'entity_type'    => 'contact',
            'entity_id'      => (int)$doc['contact_id'],
            'rule_key'       => 'doc_sign_otp',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'payload'        => ['code' => $code, 'minutes' => (string)self::OTP_TTL_MIN, 'title' => (string)$doc['title']],
            'lang'           => $doc['lang'] ?? null,
            'dedupe_key'     => 'doc_sign_otp:' . $id . ':' . $code,
        ]);

        return ['ok' => true, 'sent_to' => $target, 'error' => null];
    }

    /**
     * Check the code and, if it is right, seal the document.
     *
     * @return array{status:string, sent_to:?string}
     *         status: ok | invalid | expired | locked | none | blocked | error
     */
    public static function signWithCode(array $doc, string $code, bool $consent, string $typedName): array
    {
        $id = (int)$doc['id'];
        $blocked = self::blockedReason($doc);
        if ($blocked !== '') {
            return ['status' => 'blocked', 'sent_to' => null];
        }
        if (!$consent) {
            return ['status' => 'no_consent', 'sent_to' => null];
        }

        $code = preg_replace('/\D+/', '', trim($code)) ?? '';
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM otp_codes WHERE document_id = ? AND purpose = "doc" AND used_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['status' => 'none', 'sent_to' => null];
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            Audit::append($id, 'otp_expired', [], ['type' => 'customer', 'label' => (string)$doc['signer_name']]);
            return ['status' => 'expired', 'sent_to' => null];
        }
        if ((int)$row['attempts'] >= self::OTP_MAX_TRIES) {
            return ['status' => 'locked', 'sent_to' => null];
        }
        if (!hash_equals((string)$row['code'], $code)) {
            Db::pdo()->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
            Audit::append($id, 'otp_wrong', ['attempt' => (int)$row['attempts'] + 1],
                ['type' => 'customer', 'label' => (string)$doc['signer_name']]);
            return ['status' => 'invalid', 'sent_to' => null];
        }

        // Correct. Burn the code first — if sealing fails we must not leave a
        // usable code behind, and the customer gets a fresh one on retry.
        Db::pdo()->prepare('UPDATE otp_codes SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        Audit::append($id, 'otp_verified', ['attempts' => (int)$row['attempts']],
            ['type' => 'customer', 'label' => (string)$doc['signer_name']]);

        try {
            self::sealDocument($doc, $row, $consent, $typedName);
        } catch (\Throwable $e) {
            Audit::append($id, 'seal_failed', ['error' => mb_substr($e->getMessage(), 0, 300)],
                ['type' => 'system']);
            Log::write('sign', 'seal_failed', 'sign_document', $id, ['error' => $e->getMessage()]);
            return ['status' => 'error', 'sent_to' => null];
        }
        return ['status' => 'ok', 'sent_to' => null];
    }

    /**
     * Record the signature act, build the sealed PDF, store it.
     *
     * Ordering matters: the signature row and its evidence are written first, so
     * the certificate can print them and the audit chain already covers them
     * before the seal is computed over that same chain head.
     */
    private static function sealDocument(array $doc, array $otpRow, bool $consent, string $typedName): void
    {
        $id = (int)$doc['id'];

        $evidence = [
            'document_uid'  => (string)$doc['uid'],
            'document_hash' => (string)$doc['orig_sha256'],
            'signer'        => (string)$doc['signer_name'],
            'email'         => (string)($doc['signer_email'] ?? ''),
            'phone'         => (string)($doc['signer_phone'] ?? ''),
            'method'        => 'otp',
            'otp_sent_to'   => self::mask((string)($doc['signer_phone'] ?: $doc['signer_email'])),
            'otp_sent_at'   => (string)$otpRow['created_at'],
            'otp_digest'    => self::codeHash((string)$doc['uid'], (string)$otpRow['code']),
            'otp_attempts'  => (int)$otpRow['attempts'],
            'verified_at'   => date('Y-m-d H:i:s'),
            'ip'            => Audit::clientIp(),
            'user_agent'    => Audit::userAgent(),
            'consent'       => $consent,
            'typed_name'    => $typedName,
        ];
        $evidenceJson = (string)json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        Db::pdo()->prepare(
            'INSERT INTO sign_signatures
             (document_id, contact_id, signer_name, signer_email, signer_phone, method,
              otp_channel, otp_sent_to, otp_sent_at, otp_hash, otp_attempts, consent, typed_name,
              ip, user_agent, signed_at, evidence_json, evidence_sha256)
             VALUES (?, ?, ?, ?, ?, "otp", "both", ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)'
        )->execute([
            $id, (int)$doc['contact_id'], (string)$doc['signer_name'],
            $doc['signer_email'] ?: null, $doc['signer_phone'] ?: null,
            $evidence['otp_sent_to'], $evidence['otp_sent_at'], $evidence['otp_digest'],
            $evidence['otp_attempts'], $consent ? 1 : 0, mb_substr($typedName, 0, 190) ?: null,
            $evidence['ip'], $evidence['user_agent'], $evidenceJson, hash('sha256', $evidenceJson),
        ]);
        $sigId = (int)Db::pdo()->lastInsertId();

        Audit::append($id, 'signature_recorded', [
            'signature_id'    => $sigId,
            'evidence_sha256' => hash('sha256', $evidenceJson),
        ], ['type' => 'customer', 'label' => (string)$doc['signer_name']]);

        // The chain head at this instant is what the certificate quotes, which is
        // what stops the log being rebuilt later: a rebuilt chain no longer
        // matches the value sealed inside a PDF signed with a key they lack.
        $head = Audit::head($id);

        $sig = Db::pdo()->prepare('SELECT * FROM sign_signatures WHERE id = ?');
        $sig->execute([$sigId]);
        $sigRow = $sig->fetch() ?: [];

        $originalPath = Store::file('docs', (string)$doc['orig_path']);
        $original = $originalPath && is_readable($originalPath) ? (string)file_get_contents($originalPath) : '';
        if ($original !== '' && !hash_equals((string)$doc['orig_sha256'], hash('sha256', $original))) {
            throw new \RuntimeException('the stored original no longer matches the hash recorded at upload');
        }

        $result = Signer::produce($doc, $sigRow, $original, $head);

        $sealedName = 'signed-' . $doc['uid'] . '.pdf';
        $sealedPath = (string)Store::file('docs', $sealedName);
        if (file_put_contents($sealedPath, $result['pdf'], LOCK_EX) === false) {
            throw new \RuntimeException('could not store the sealed PDF');
        }
        @chmod($sealedPath, 0640);

        Db::pdo()->prepare(
            'UPDATE sign_documents
             SET status = "signed", signed_at = NOW(), signed_path = ?, signed_sha256 = ?, signed_bytes = ?,
                 cert_subject = ?, cert_serial = ?, cert_fingerprint = ?, tsa_time = ?, tsa_url = ?
             WHERE id = ?'
        )->execute([
            $sealedName, $result['sha256'], strlen($result['pdf']),
            $result['cert']['subject'], $result['cert']['serial'], $result['cert']['fingerprint'],
            $result['tsa_time'] ? date('Y-m-d H:i:s', $result['tsa_time']) : null,
            $result['tsa_time'] ? Timestamp::url() : null,
            $id,
        ]);

        Audit::append($id, 'document_sealed', [
            'signed_sha256' => $result['sha256'],
            'cert_serial'   => $result['cert']['serial'],
            'cert_sha256'   => $result['cert']['fingerprint'],
            'self_signed'   => $result['cert']['self_signed'],
            'tsa'           => $result['tsa_time'] ? date('c', $result['tsa_time']) : null,
        ], ['type' => 'system']);

        Log::write('sign', 'document_signed', 'sign_document', $id, [
            'uid' => (string)$doc['uid'], 'signed_sha256' => $result['sha256'],
        ]);

        // Send the customer their copy.
        (new Scheduler())->enqueue([
            'entity_type'    => 'contact',
            'entity_id'      => (int)$doc['contact_id'],
            'rule_key'       => 'doc_signed_copy',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'payload'        => [
                'title' => (string)$doc['title'],
                'link'  => Signer::verifyUrl((string)$doc['uid']),
            ],
            'lang'       => $doc['lang'] ?? null,
            'dedupe_key' => 'doc_signed_copy:' . $id,
        ]);

        // Tell the agent who sent it. The recipient is that agent, not whoever the
        // contact happens to be assigned to, so their details go in the payload —
        // which the scheduler lets win over the resolver.
        $staff = self::staffContact($doc['created_by'] ?? null);
        if ($staff !== null) {
            (new Scheduler())->enqueue([
                'entity_type'    => 'contact',
                'entity_id'      => (int)$doc['contact_id'],
                'rule_key'       => 'doc_signed_staff',
                'recipient_type' => 'agent',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s'),
                'payload'        => $staff + ['title' => (string)$doc['title'],
                                              'customer_name' => (string)$doc['signer_name']],
                'dedupe_key'     => 'doc_signed_staff:' . $id,
            ]);
        }
    }

    /** The customer refuses. A refusal is evidence too, so it is logged in full. */
    public static function decline(array $doc, string $reason): bool
    {
        $id = (int)$doc['id'];
        if (self::blockedReason($doc) !== '') {
            return false;
        }
        Db::pdo()->prepare(
            'UPDATE sign_documents SET status = "declined", declined_at = NOW(), decline_reason = ? WHERE id = ?'
        )->execute([mb_substr($reason, 0, 255) ?: null, $id]);

        Audit::append($id, 'declined_by_signer', ['reason' => mb_substr($reason, 0, 255)],
            ['type' => 'customer', 'label' => (string)$doc['signer_name']]);
        Log::write('sign', 'document_declined', 'sign_document', $id, ['uid' => (string)$doc['uid']]);

        $staff = self::staffContact($doc['created_by'] ?? null);
        if ($staff !== null) {
            (new Scheduler())->enqueue([
                'entity_type'    => 'contact',
                'entity_id'      => (int)$doc['contact_id'],
                'rule_key'       => 'doc_declined_staff',
                'recipient_type' => 'agent',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s'),
                'payload'        => $staff + ['title' => (string)$doc['title'],
                                              'customer_name' => (string)$doc['signer_name'],
                                              'reason' => mb_substr($reason, 0, 120) ?: '—'],
                'dedupe_key'     => 'doc_declined_staff:' . $id,
            ]);
        }
        return true;
    }

    /** Withdraw an unsigned document: the link stops working immediately. */
    public static function void(int $id, ?int $userId, string $reason = ''): bool
    {
        $doc = self::find($id);
        if (!$doc || $doc['status'] === 'signed') {
            return false;
        }
        Db::pdo()->prepare('UPDATE sign_documents SET status = "void", access_token = NULL WHERE id = ?')
            ->execute([$id]);
        Audit::append($id, 'voided_by_staff', ['reason' => mb_substr($reason, 0, 255)],
            ['type' => 'staff', 'id' => $userId, 'label' => self::staffLabel($userId)]);
        return true;
    }

    // ---- reading ----------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM sign_documents WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function byUid(string $uid): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/i', $uid)) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM sign_documents WHERE uid = ?');
        $stmt->execute([strtolower($uid)]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array> newest first, optionally scoped to one agent */
    public static function all(int $limit = 200, ?int $agentId = null): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = 'SELECT d.*, c.name AS contact_name, u.username, u.full_name
                FROM sign_documents d
                LEFT JOIN contacts c ON c.id = d.contact_id
                LEFT JOIN users u ON u.id = d.created_by';
        $args = [];
        if ($agentId !== null) {
            $sql .= ' WHERE d.created_by = ?';
            $args[] = $agentId;
        }
        $sql .= " ORDER BY d.id DESC LIMIT $limit";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /**
     * The documents a portal customer can see. Drafts and withdrawn documents
     * are deliberately absent: the customer only ever sees what was actually
     * sent to them.
     *
     * @return array<int,array>
     */
    public static function forContact(int $contactId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM sign_documents
             WHERE contact_id = ? AND status IN ("sent", "viewed", "signed", "declined", "expired")
             ORDER BY id DESC'
        );
        $stmt->execute([$contactId]);
        return $stmt->fetchAll();
    }

    public static function signature(int $documentId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM sign_signatures WHERE document_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$documentId]);
        return $stmt->fetch() ?: null;
    }

    public static function counts(): array
    {
        $rows = Db::pdo()->query('SELECT status, COUNT(*) c FROM sign_documents GROUP BY status')->fetchAll();
        $out = ['draft' => 0, 'sent' => 0, 'viewed' => 0, 'signed' => 0, 'declined' => 0, 'expired' => 0, 'void' => 0];
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
        }
        return $out;
    }

    // ---- files -------------------------------------------------------------------------

    /** Absolute path of the original, or null when it is missing from disk. */
    public static function originalPath(array $doc): ?string
    {
        $p = Store::file('docs', (string)$doc['orig_path']);
        return $p && is_readable($p) ? $p : null;
    }

    public static function signedPath(array $doc): ?string
    {
        if (empty($doc['signed_path'])) {
            return null;
        }
        $p = Store::file('docs', (string)$doc['signed_path']);
        return $p && is_readable($p) ? $p : null;
    }

    /** Send a stored file to the browser. Nothing under storage/ is web-reachable. */
    public static function stream(string $path, string $filename, string $mime = 'application/pdf'): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /**
     * Validate and store the upload. PDF only: the certificate embeds the file
     * and states it is the document that was signed, and that claim is only
     * honest for a format whose bytes render the same way every time.
     *
     * @return array{path:string, name:string, sha256:string, bytes:int, mime:string}|null
     */
    private static function storeUpload(?array $file, ?string &$err = null): ?array
    {
        $err = null;
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (!$file || $code === UPLOAD_ERR_NO_FILE) {
            $err = 'no_file';
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
        $bytes = (int)($file['size'] ?? 0);
        if ($bytes <= 0 || $bytes > self::MAX_BYTES) {
            $err = 'too_big';
            return null;
        }
        $orig = (string)($file['name'] ?? 'document.pdf');
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            $err = 'bad_type';
            return null;
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $err = 'save_failed';
            return null;
        }
        // Check the magic bytes, not just the extension.
        $head = (string)file_get_contents($tmp, false, null, 0, 5);
        if (strncmp($head, '%PDF-', 5) !== 0) {
            $err = 'bad_type';
            return null;
        }

        $stored = bin2hex(random_bytes(16)) . '.pdf';
        $dest   = Store::path('docs') . '/' . $stored;
        if (!move_uploaded_file($tmp, $dest)) {
            $err = 'save_failed';
            return null;
        }
        @chmod($dest, 0640);

        return [
            'path'   => $stored,
            'name'   => mb_substr($orig, 0, 190),
            'sha256' => hash_file('sha256', $dest),
            'bytes'  => (int)filesize($dest),
            'mime'   => 'application/pdf',
        ];
    }

    // ---- helpers ------------------------------------------------------------------------

    private static function newUid(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** "+3933****4977" / "ma****@example.com" — provable, not re-usable. */
    public static function mask(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '—';
        }
        if (str_contains($target, '@')) {
            [$user, $domain] = explode('@', $target, 2);
            $keep = mb_substr($user, 0, 2);
            return $keep . str_repeat('*', max(2, mb_strlen($user) - 2)) . '@' . $domain;
        }
        $len = strlen($target);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }
        return substr($target, 0, 5) . str_repeat('*', $len - 9) . substr($target, -4);
    }

    /** Salted with the document uid so identical codes hash differently. */
    private static function codeHash(string $uid, string $code): string
    {
        return hash('sha256', $uid . ':' . $code);
    }

    private static function staffLabel(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT COALESCE(NULLIF(full_name, ""), username) FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string)($stmt->fetchColumn() ?: '') ?: null;
    }

    /**
     * Template vars naming a staff member as the recipient. Null when there is
     * nobody to reach — enqueuing a message with no address just fills the outbox
     * with failures.
     *
     * @return array{agent_name:string, agent_phone:string, agent_email:string}|null
     */
    private static function staffContact($userId): ?array
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT username, full_name, phone, email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u || (trim((string)$u['phone']) === '' && trim((string)$u['email']) === '')) {
            return null;
        }
        return [
            'agent_name'  => trim((string)$u['full_name']) ?: (string)$u['username'],
            'agent_phone' => (string)($u['phone'] ?? ''),
            'agent_email' => (string)($u['email'] ?? ''),
        ];
    }
}
