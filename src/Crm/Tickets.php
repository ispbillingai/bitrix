<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Portal\Account;
use Glue\Reminder\Templates;

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
        self::addMessage($ticketId, $senderType, $senderId, $senderName, $body, $attachment);

        // A staff reply marks the ticket pending-on-customer; a customer reply reopens it.
        $status = $senderType === 'customer' ? 'open' : 'pending';
        Db::pdo()->prepare('UPDATE tickets SET status = ?, last_sender = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, $senderType, $ticketId]);

        Log::write('crm', 'ticket_reply', 'ticket', $ticketId, ['by' => $senderType]);
        if ($senderType === 'customer') {
            self::notifyStaff($ticketId);
        } else {
            self::notifyCustomer($ticketId);
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

    private static function addMessage(int $ticketId, string $type, ?int $senderId, string $name, string $body, ?array $attachment = null): void
    {
        Db::pdo()->prepare(
            'INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, sender_name, body, attachment_path, attachment_name)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ticketId, $type, $senderId ?: null, trim($name) ?: null, trim($body),
            $attachment['path'] ?? null, $attachment['name'] ?? null,
        ]);
    }

    // ---- attachments ------------------------------------------------------------

    private const UPLOAD_MAX_BYTES = 10485760; // 10 MB
    private const UPLOAD_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx',
                                'xls', 'xlsx', 'csv', 'txt', 'zip'];

    public static function uploadDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads/tickets';
    }

    /**
     * Validate + store a $_FILES entry. Returns ['path' => stored-filename,
     * 'name' => original-filename] or null if nothing usable was uploaded.
     */
    public static function storeUpload(?array $file): ?array
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > self::UPLOAD_MAX_BYTES) {
            return null;
        }
        $orig = (string)($file['name'] ?? 'file');
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::UPLOAD_EXT, true)) {
            return null;
        }
        $dir = self::uploadDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
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
        $vars = [
            'name'          => trim((string)($a['full_name'] ?? '')) ?: (string)($a['username'] ?? ''),
            'customer_name' => (string)($t['customer_name'] ?? 'the customer'),
            'company'       => self::company(),
            'id'            => (string)$ticketId,
            'subject'       => (string)$t['subject'],
        ];
        $lang = Config::get('app.default_lang', 'it'); // staff get the office language
        $notifier = new Notifier();
        if (!empty($a['phone'])) {
            $notifier->whatsapp((string)$a['phone'], Templates::whatsapp('ticket_staff', $vars, $lang));
        }
        if (!empty($a['email'])) {
            $mail = Templates::email('ticket_staff', $vars, $lang);
            $notifier->email((string)$a['email'], $mail['subject'], $mail['html']);
        }
    }

    private static function notifyCustomer(int $ticketId): void
    {
        $t = self::find($ticketId);
        if (!$t) {
            return;
        }
        $token = Account::invite((int)$t['contact_id']); // fresh magic link so they can click straight in
        $vars = [
            'name'    => (string)($t['customer_name'] ?? 'there'),
            'company' => self::company(),
            'id'      => (string)$ticketId,
            'subject' => (string)$t['subject'],
            'link'    => Account::magicLink($token),
        ];
        $lang = self::contactLang((int)$t['contact_id']);
        $notifier = new Notifier();
        if (!empty($t['customer_phone'])) {
            $notifier->whatsapp((string)$t['customer_phone'], Templates::whatsapp('ticket_reply', $vars, $lang));
        }
        if (!empty($t['customer_email'])) {
            $mail = Templates::email('ticket_reply', $vars, $lang);
            $notifier->email((string)$t['customer_email'], $mail['subject'], $mail['html']);
        }
    }

    private static function contactLang(int $contactId): ?string
    {
        $c = Account::find($contactId);
        return $c['lang'] ?? null;
    }

    private static function company(): string
    {
        return (string)Config::get('mail.from_name', '') ?: (string)Config::get('app.company_name', 'our company');
    }
}
