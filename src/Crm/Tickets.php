<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Portal\Account;
use Glue\Reminder\Scheduler;

/**
 * Support tickets — a threaded conversation between a customer (contact) and the
 * staff. The customer writes from the portal; the assigned agent (or admin) reads
 * and replies from the dashboard. Each side is notified of the other's messages.
 *
 * This is the "support tickets" + "customer<->agent chat" feature in one place.
 */
final class Tickets
{
    /** Customer opens a new ticket (first message). Returns the ticket id. */
    public static function open(int $contactId, string $subject, string $body, ?int $dealId = null, ?array $attachment = null): int
    {
        $agentId = self::agentForContact($contactId);
        $subject = trim($subject) !== '' ? mb_substr(trim($subject), 0, 190) : 'Support request';

        // Same subject, still open → it's the same conversation: append, don't fork.
        $dup = Db::pdo()->prepare(
            'SELECT id FROM tickets WHERE contact_id = ? AND subject = ? AND status <> "closed"
             ORDER BY id DESC LIMIT 1'
        );
        $dup->execute([$contactId, $subject]);
        $existing = (int)($dup->fetchColumn() ?: 0);
        if ($existing > 0) {
            $contact = Account::find($contactId);
            self::reply($existing, 'customer', $contactId, (string)($contact['name'] ?? ''), $body, $attachment);
            return $existing;
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO tickets (contact_id, assigned_agent_id, deal_id, subject, status, last_sender)
             VALUES (?, ?, ?, ?, "open", "customer")'
        );
        $stmt->execute([$contactId, $agentId, $dealId, $subject]);
        $ticketId = (int)Db::pdo()->lastInsertId();

        $contact = Account::find($contactId);
        self::addMessage($ticketId, 'customer', $contactId, (string)($contact['name'] ?? ''), $body, $attachment);

        Log::write('crm', 'ticket_opened', 'ticket', $ticketId, ['contact_id' => $contactId, 'agent_id' => $agentId]);
        self::notifyStaff($ticketId);
        return $ticketId;
    }

    /**
     * Staff starts a conversation with a customer (e.g. to ask for information
     * or send the offer file). Notifies the customer; if the first message
     * carries a file, the "please read and download the offer" reminder
     * cadence is armed too. Returns the ticket id.
     */
    public static function openFromStaff(
        int $contactId,
        string $senderType,
        ?int $senderId,
        string $senderName,
        string $subject,
        string $body,
        ?array $attachment = null
    ): int {
        $agentId = $senderType === 'agent' && $senderId ? $senderId : self::agentForContact($contactId);
        $subject = trim($subject) !== '' ? mb_substr(trim($subject), 0, 190) : 'Message from us';

        $stmt = Db::pdo()->prepare(
            'INSERT INTO tickets (contact_id, assigned_agent_id, deal_id, subject, status, last_sender)
             VALUES (?, ?, NULL, ?, "pending", ?)'
        );
        $stmt->execute([$contactId, $agentId, $subject, $senderType]);
        $ticketId = (int)Db::pdo()->lastInsertId();

        $messageId = self::addMessage($ticketId, $senderType, $senderId, $senderName, $body, $attachment);
        Log::write('crm', 'ticket_opened_staff', 'ticket', $ticketId,
            ['contact_id' => $contactId, 'by' => $senderType]);

        self::notifyCustomer($ticketId);
        if ($attachment !== null) {
            self::armOfferReminders($ticketId, $messageId);
        }
        return $ticketId;
    }

    /**
     * Post a reply. $senderType is customer|agent|admin. Updates the thread status
     * and notifies the other party.
     */
    public static function reply(int $ticketId, string $senderType, ?int $senderId, string $senderName, string $body, ?array $attachment = null): bool
    {
        $body = trim($body);
        $ticket = self::find($ticketId);
        if (!$ticket || ($body === '' && $attachment === null)) {
            return false;
        }
        $messageId = self::addMessage($ticketId, $senderType, $senderId, $senderName, $body, $attachment);

        // A staff reply marks the ticket pending-on-customer; a customer reply reopens it.
        $status = $senderType === 'customer' ? 'open' : 'pending';
        Db::pdo()->prepare('UPDATE tickets SET status = ?, last_sender = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, $senderType, $ticketId]);

        Log::write('crm', 'ticket_reply', 'ticket', $ticketId, ['by' => $senderType]);
        if ($senderType === 'customer') {
            self::notifyStaff($ticketId);
        } else {
            self::notifyCustomer($ticketId);
            if ($attachment !== null) {
                // A staff file is (typically) the offer — chase until downloaded.
                self::armOfferReminders($ticketId, $messageId);
            }
        }
        return true;
    }

    public static function setStatus(int $ticketId, string $status): void
    {
        if (!in_array($status, ['open', 'pending', 'closed'], true)) {
            return;
        }
        Db::pdo()->prepare('UPDATE tickets SET status = ? WHERE id = ?')->execute([$status, $ticketId]);
        Log::write('crm', 'ticket_status', 'ticket', $ticketId, ['status' => $status]);
    }

    private static function addMessage(int $ticketId, string $type, ?int $senderId, string $name, string $body, ?array $attachment = null): int
    {
        Db::pdo()->prepare(
            'INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, sender_name, body, attachment_path, attachment_name)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ticketId, $type, $senderId ?: null, trim($name) ?: null, trim($body),
            $attachment['path'] ?? null, $attachment['name'] ?? null,
        ]);
        return (int)Db::pdo()->lastInsertId();
    }

    // ---- attachments ------------------------------------------------------------

    private const UPLOAD_MAX_BYTES = 10485760; // 10 MB
    private const UPLOAD_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx',
                                'xls', 'xlsx', 'csv', 'txt', 'zip'];

    /**
     * Where attachments live. Preferred: storage/ above the web root. On hosts
     * where that isn't writable (open_basedir, perms) fall back to a folder
     * inside public/ that .htaccess blocks from direct download — files are
     * only ever served through the permission-checked ?dl= endpoint.
     */
    public static function uploadDir(): string
    {
        $cfg = (string)Config::get('app.upload_dir', '');
        if ($cfg !== '') {
            return rtrim($cfg, '/\\');
        }
        $root = dirname(__DIR__, 2);
        $preferred = $root . '/storage/uploads/tickets';
        if (is_dir($preferred) || @mkdir($preferred, 0775, true)) {
            return $preferred;
        }
        return $root . '/public/uploads/tickets';
    }

    /**
     * Validate + store a $_FILES entry. Returns ['path' => stored-filename,
     * 'name' => original-filename] or null if nothing usable was uploaded.
     * $err is set to 'too_big' | 'bad_type' | 'save_failed' when a file WAS
     * chosen but couldn't be stored (null + empty $err = no file chosen).
     */
    public static function storeUpload(?array $file, ?string &$err = null): ?array
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
        if ((int)$file['size'] <= 0 || (int)$file['size'] > self::UPLOAD_MAX_BYTES) {
            $err = 'too_big';
            return null;
        }
        $orig = (string)($file['name'] ?? 'file');
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::UPLOAD_EXT, true)) {
            $err = 'bad_type';
            return null;
        }
        $dir = self::uploadDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
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

    /** A message row + its ticket's owners, for download permission checks. */
    public static function messageFile(int $messageId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT m.id, m.attachment_path, m.attachment_name, t.contact_id, t.assigned_agent_id
             FROM ticket_messages m JOIN tickets t ON t.id = m.ticket_id
             WHERE m.id = ? AND m.attachment_path IS NOT NULL'
        );
        $stmt->execute([$messageId]);
        return $stmt->fetch() ?: null;
    }

    /** Stream a stored attachment and exit. Call only after a permission check. */
    public static function streamAttachment(array $msg): void
    {
        $path = self::uploadDir() . '/' . basename((string)$msg['attachment_path']);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Not found');
        }
        $name = (string)($msg['attachment_name'] ?: 'attachment');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }

    // ---- offer tracking (seen / downloaded / accepted) -------------------------

    /** The customer opened this thread — staff sees "seen" with a timestamp. */
    public static function markCustomerSeen(int $ticketId): void
    {
        Db::pdo()->prepare('UPDATE tickets SET customer_seen_at = NOW() WHERE id = ?')
            ->execute([$ticketId]);
    }

    /**
     * The customer downloaded an attachment: stamp it (first time only) and stop
     * the "please read the offer" chase for that message.
     */
    public static function markDownloaded(int $messageId): void
    {
        Db::pdo()->prepare(
            'UPDATE ticket_messages SET downloaded_at = NOW() WHERE id = ? AND downloaded_at IS NULL'
        )->execute([$messageId]);
        Db::pdo()->prepare(
            "UPDATE reminders SET status = 'cancelled'
             WHERE status = 'pending' AND rule_key = 'offer_read' AND dedupe_key LIKE ?"
        )->execute(['offer_read:msg:' . $messageId . ':%']);

        // Downloading a staff file (the offer) advances the deal on the pipeline.
        $q = Db::pdo()->prepare(
            "SELECT t.contact_id FROM ticket_messages m JOIN tickets t ON t.id = m.ticket_id
             WHERE m.id = ? AND m.sender_type <> 'customer'"
        );
        $q->execute([$messageId]);
        $contactId = $q->fetchColumn();
        if ($contactId) {
            self::syncDealOffer((int)$contactId, 'downloaded');
        }
    }

    /**
     * Mirror the offer lifecycle onto the customer's open deal so the agent can
     * follow it on the pipeline board without opening the chat:
     *   sent       -> Quote sent stage, yellow LED
     *   downloaded -> Negotiation stage, orange LED
     *   accepted   -> green LED (signing then moves the deal to Won)
     */
    private static function syncDealOffer(int $contactId, string $status): void
    {
        $stmt = Db::pdo()->prepare(
            "SELECT id, stage_code, offer_status FROM deals
             WHERE contact_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$contactId]);
        $deal = $stmt->fetch();
        if (!$deal) {
            return;
        }
        // Never move the LED backwards — except a brand-new offer, which restarts it.
        $rank = ['sent' => 1, 'downloaded' => 2, 'accepted' => 3];
        $cur  = (string)($deal['offer_status'] ?? '');
        if ($status !== 'sent' && isset($rank[$cur]) && $rank[$cur] >= $rank[$status]) {
            return;
        }
        Db::pdo()->prepare('UPDATE deals SET offer_status = ? WHERE id = ?')
            ->execute([$status, (int)$deal['id']]);

        $quote = (string)Config::get('crm.deal_quote_stage', 'QUOTE');
        $nego  = (string)Config::get('crm.deal_negotiation_stage', 'NEGOTIATION');
        $sign  = (string)Config::get('crm.deal_signature_stage', 'SIGNATURE');
        if ($status === 'sent' && $deal['stage_code'] !== $quote) {
            Deals::moveStage((int)$deal['id'], $quote);
        } elseif ($status === 'downloaded' && $deal['stage_code'] === $quote) {
            Deals::moveStage((int)$deal['id'], $nego);
        } elseif ($status === 'accepted' && in_array($deal['stage_code'], [$quote, $nego], true)) {
            Deals::moveStage((int)$deal['id'], $sign);
        }
    }

    /**
     * The customer accepts the offer (a staff message with a file): stamp it,
     * stop the read-reminders, and notify + urge the agent to send the contract.
     * Returns false if the message isn't an acceptable offer of this customer.
     */
    public static function acceptOffer(int $messageId, int $contactId): bool
    {
        $stmt = Db::pdo()->prepare(
            "SELECT m.id, m.ticket_id, m.accepted_at, m.sender_type, t.contact_id, t.subject
             FROM ticket_messages m JOIN tickets t ON t.id = m.ticket_id
             WHERE m.id = ? AND m.attachment_path IS NOT NULL AND m.sender_type <> 'customer'"
        );
        $stmt->execute([$messageId]);
        $m = $stmt->fetch();
        if (!$m || (int)$m['contact_id'] !== $contactId) {
            return false;
        }
        if (!empty($m['accepted_at'])) {
            return true; // already accepted — idempotent
        }
        Db::pdo()->prepare('UPDATE ticket_messages SET accepted_at = NOW() WHERE id = ?')
            ->execute([$messageId]);
        Db::pdo()->prepare(
            "UPDATE reminders SET status = 'cancelled'
             WHERE status = 'pending' AND rule_key = 'offer_read' AND dedupe_key LIKE ?"
        )->execute(['offer_read:msg:' . $messageId . ':%']);
        Log::write('crm', 'offer_accepted', 'ticket', (int)$m['ticket_id'],
            ['message_id' => $messageId, 'contact_id' => $contactId]);
        self::syncDealOffer($contactId, 'accepted');

        // Urge the agent (or the office) to send the contract for signature.
        $t = self::find((int)$m['ticket_id']);
        if ($t) {
            $agent = null;
            if (!empty($t['assigned_agent_id'])) {
                $q = Db::pdo()->prepare('SELECT full_name, username, email, phone FROM users WHERE id = ?');
                $q->execute([(int)$t['assigned_agent_id']]);
                $agent = $q->fetch() ?: null;
            }
            (new Scheduler())->enqueue([
                'entity_type'    => 'contact',
                'entity_id'      => $contactId,
                'rule_key'       => 'offer_accepted',
                'recipient_type' => 'agent',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s'),
                'payload'        => [
                    'name'          => trim((string)($agent['full_name'] ?? '')) ?: (string)($agent['username'] ?? ''),
                    'customer_name' => (string)($t['customer_name'] ?? 'the customer'),
                    'subject'       => (string)$t['subject'],
                    'id'            => (string)$m['ticket_id'],
                    'agent_phone'   => (string)($agent['phone'] ?? ''),
                    'agent_email'   => (string)($agent['email'] ?? ''),
                ],
                'dedupe_key'     => 'offer_accepted:msg:' . $messageId,
            ]);
        }
        return true;
    }

    /**
     * Chase the customer to read + download the offer file: one reminder per
     * configured day offset (reminders.offer_read_days, default day 2 and 5).
     * Cancelled automatically when they download or accept the offer.
     */
    private static function armOfferReminders(int $ticketId, int $messageId): void
    {
        $t = self::find($ticketId);
        if (!$t) {
            return;
        }
        self::syncDealOffer((int)$t['contact_id'], 'sent');
        $token = Account::invite((int)$t['contact_id']);
        $sched = new Scheduler();
        $days = (array)Config::get('reminders.offer_read_days', [2, 5]);
        foreach ($days as $d) {
            $d = max(1, (int)$d);
            $sched->enqueue([
                'entity_type'    => 'contact',
                'entity_id'      => (int)$t['contact_id'],
                'rule_key'       => 'offer_read',
                'recipient_type' => 'customer',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s', time() + $d * 86400),
                'payload'        => [
                    'subject' => (string)$t['subject'],
                    'id'      => (string)$ticketId,
                    'link'    => Account::magicLink($token),
                ],
                'dedupe_key'     => 'offer_read:msg:' . $messageId . ':d' . $d,
            ]);
        }
    }

    /** Contacts a staff member may start a conversation with (admin: everyone). */
    public static function customersForStaff(?int $agentId = null): array
    {
        if ($agentId === null) {
            return Db::pdo()->query(
                'SELECT id, name, email, phone FROM contacts ORDER BY name ASC LIMIT 500'
            )->fetchAll();
        }
        $stmt = Db::pdo()->prepare(
            'SELECT DISTINCT c.id, c.name, c.email, c.phone FROM contacts c
             WHERE EXISTS (SELECT 1 FROM deals d WHERE d.contact_id = c.id AND d.assigned_to = ?)
                OR EXISTS (SELECT 1 FROM leads l WHERE l.contact_id = c.id AND l.assigned_to = ?)
             ORDER BY c.name ASC LIMIT 500'
        );
        $stmt->execute([$agentId, $agentId]);
        return $stmt->fetchAll();
    }

    // ---- reads ----------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT t.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    u.full_name AS agent_name, u.username AS agent_username
             FROM tickets t
             LEFT JOIN contacts c ON c.id = t.contact_id
             LEFT JOIN users u ON u.id = t.assigned_agent_id
             WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Tickets for one customer (portal). */
    public static function forContact(int $contactId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT t.*, (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS msgs
             FROM tickets t WHERE t.contact_id = ? ORDER BY t.updated_at DESC'
        );
        $stmt->execute([$contactId]);
        return $stmt->fetchAll();
    }

    /** Staff list; $agentId scopes to one seller's tickets, null = all. */
    public static function forStaff(?int $agentId = null, int $limit = 300): array
    {
        $limit = max(1, min(1000, $limit));
        $where = $agentId ? ' WHERE t.assigned_agent_id = ' . (int)$agentId : '';
        return Db::pdo()->query(
            "SELECT t.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    u.full_name AS agent_name, u.username AS agent_username
             FROM tickets t
             LEFT JOIN contacts c ON c.id = t.contact_id
             LEFT JOIN users u ON u.id = t.assigned_agent_id
             $where ORDER BY (t.status='closed'), t.updated_at DESC LIMIT $limit"
        )->fetchAll();
    }

    public static function thread(int $ticketId): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id ASC');
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    public static function count(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM tickets' . ($where ? " WHERE $where" : '');
        return (int)Db::pdo()->query($sql)->fetchColumn();
    }

    /** The agent assigned to this customer's most recent deal, else lead. */
    public static function agentForContact(int $contactId): ?int
    {
        $sql = 'SELECT assigned_to FROM deals WHERE contact_id = ? AND assigned_to IS NOT NULL ORDER BY id DESC LIMIT 1';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([$contactId]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $stmt = Db::pdo()->prepare('SELECT assigned_to FROM leads WHERE contact_id = ? AND assigned_to IS NOT NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$contactId]);
        return (int)($stmt->fetchColumn() ?: 0) ?: null;
    }

    // ---- notifications --------------------------------------------------------

    // Both notifications are queued (not sent inline) so a slow or unreachable
    // SMTP/WhatsApp gateway can never hang the dashboard or the portal — the
    // cron (bin/scheduler.php) delivers them within a minute.

    private static function notifyStaff(int $ticketId): void
    {
        $t = self::find($ticketId);
        if (!$t || empty($t['assigned_agent_id'])) {
            return; // unassigned tickets wait for admin in the dashboard
        }
        $agent = Db::pdo()->prepare('SELECT full_name, username, email, phone FROM users WHERE id = ?');
        $agent->execute([(int)$t['assigned_agent_id']]);
        $a = $agent->fetch();
        if (!$a) {
            return;
        }
        (new Scheduler())->enqueue([
            'entity_type'    => 'contact',
            'entity_id'      => (int)$t['contact_id'],
            'rule_key'       => 'ticket_staff',
            'recipient_type' => 'agent',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            // payload wins over resolved vars, so it carries the agent's contact
            // details (the resolver can't derive them from a bare contact row).
            'payload'        => [
                'name'          => trim((string)($a['full_name'] ?? '')) ?: (string)($a['username'] ?? ''),
                'customer_name' => (string)($t['customer_name'] ?? 'the customer'),
                'id'            => (string)$ticketId,
                'subject'       => (string)$t['subject'],
                'agent_phone'   => (string)($a['phone'] ?? ''),
                'agent_email'   => (string)($a['email'] ?? ''),
            ],
        ]);
    }

    private static function notifyCustomer(int $ticketId): void
    {
        $t = self::find($ticketId);
        if (!$t) {
            return;
        }
        $token = Account::invite((int)$t['contact_id']); // fresh magic link so they can click straight in
        (new Scheduler())->enqueue([
            'entity_type'    => 'contact',
            'entity_id'      => (int)$t['contact_id'],
            'rule_key'       => 'ticket_reply',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'payload'        => [
                'id'      => (string)$ticketId,
                'subject' => (string)$t['subject'],
                'link'    => Account::magicLink($token),
            ],
        ]);
    }

}
