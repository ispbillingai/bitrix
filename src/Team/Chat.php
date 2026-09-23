<?php
declare(strict_types=1);

namespace Glue\Team;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;

/**
 * The team's chat: staff talking to staff inside the CRM, one-to-one or in a
 * named group, plus each person's own conversation with the AI assistant.
 * Same shape as the customer chat (Crm\Tickets) — text, a file, a voice note or
 * a video per message — but between users, not with a contact, so it has its
 * own tables (migration 055) rather than a flag on tickets.
 *
 * Membership is the whole permission model: you read and write a chat because
 * you are in it. dashboard.php checks isMember() before every read, post,
 * poll and download; nothing here trusts a chat id from the request on its own.
 */
final class Chat
{
    // ---- finding and creating chats ------------------------------------------------

    /** The chat between two people, created on first use. */
    public static function direct(int $a, int $b, ?int $creator = null): int
    {
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return 0;
        }
        $key = 'd:' . min($a, $b) . ':' . max($a, $b);
        $id = self::byKey($key);
        if ($id > 0) {
            return $id;
        }
        return self::create('direct', null, $key, [$a, $b], $creator);
    }

    /** One person's conversation with the assistant, created on first use. */
    public static function ai(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $key = 'ai:' . $userId;
        $id = self::byKey($key);
        return $id > 0 ? $id : self::create('ai', null, $key, [$userId], $userId);
    }

    /** A named group. The creator is always a member. */
    public static function group(string $name, array $userIds, int $creator): int
    {
        $name = mb_substr(trim($name), 0, 190);
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($i) => $i > 0)));
        if (!in_array($creator, $ids, true)) {
            $ids[] = $creator;
        }
        if ($name === '' || count($ids) < 2) {
            return 0;
        }
        return self::create('group', $name, null, $ids, $creator);
    }

    private static function byKey(string $key): int
    {
        $s = Db::pdo()->prepare('SELECT id FROM team_chats WHERE direct_key = ?');
        $s->execute([$key]);
        return (int)($s->fetchColumn() ?: 0);
    }

    private static function create(string $kind, ?string $name, ?string $key, array $userIds, ?int $creator): int
    {
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO team_chats (kind, name, direct_key, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$kind, $name, $key, $creator ?: null]);
        $id = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT IGNORE INTO team_chat_members (chat_id, user_id) VALUES (?, ?)');
        foreach ($userIds as $u) {
            $ins->execute([$id, (int)$u]);
        }
        Log::write('team', 'chat_created', 'team_chat', $id, ['kind' => $kind, 'members' => $userIds, 'by' => $creator]);

        // Tell the people who have just been put in it. Not the assistant chat,
        // which a person opens for themselves, and never the creator — they are
        // looking at the conversation they just started.
        if ($kind !== 'ai') {
            self::invite($id, $name, array_filter($userIds, fn($u) => (int)$u !== (int)$creator), (int)$creator);
        }
        return $id;
    }

    /**
     * "You have a message waiting in the CRM."
     *
     * A chat nobody knows about is a chat nobody answers: the CRM only shows an
     * unread badge to someone who is already logged in, and the people most
     * worth reaching — technicians and sellers — are the ones not sitting at
     * the dashboard. So the invitation goes to the phone.
     *
     * WhatsApp by preference and email only when there is no phone, rather than
     * both: this is a nudge to go and look, and the same nudge twice in two
     * places is noise. Queued, never inline — starting a chat must not wait out
     * the gateway's gap between sends.
     */
    private static function invite(int $chatId, ?string $name, array $userIds, int $creator): void
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds), fn($i) => $i > 0));
        if (!$userIds) {
            return;
        }
        $pdo = Db::pdo();
        $by = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(full_name), ''), username) FROM users WHERE id = ?");
        $by->execute([$creator]);
        $byName = (string)($by->fetchColumn() ?: '');

        $link = rtrim((string)Config::appBaseUrl(), '/') . '/dashboard.php?tab=team&c=' . $chatId;
        $lang = \Glue\Reminder\Templates::lang(Config::get('app.default_lang', 'it'));

        $in = implode(',', array_fill(0, count($userIds), '?'));
        $q = $pdo->prepare(
            "SELECT id, COALESCE(NULLIF(TRIM(full_name), ''), username) AS name, phone, email
               FROM users WHERE id IN ($in) AND active = 1"
        );
        $q->execute($userIds);

        foreach ($q->fetchAll() ?: [] as $u) {
            $phone = trim((string)($u['phone'] ?? ''));
            $email = trim((string)($u['email'] ?? ''));
            if ($phone === '' && $email === '') {
                continue;
            }
            $vars = [
                'name'       => (string)$u['name'],
                'agent_name' => (string)$u['name'],
                'by'         => $byName,
                // Only a group has a name worth quoting. A direct chat left it
                // reading "Anna ti ha scritto: «Anna»", so the templates wrap
                // it in {?chat}…{/chat} and it simply disappears.
                'chat'       => trim((string)$name),
                'company'    => (string)Config::get('app.company_name', 'CRM'),
                'link'       => $link,
            ];
            $mail = \Glue\Reminder\Templates::email('team_chat_invite', $vars, $lang);
            \Glue\Notify\StaffAlert::toUser(
                (int)$u['id'], 'team_chat_invite',
                \Glue\Reminder\Templates::whatsapp('team_chat_invite', $vars, $lang),
                (string)$mail['subject'], (string)$mail['html'],
                'team_chat', $chatId, 'whatsapp'
            );
        }
    }

    // ---- membership ------------------------------------------------------------------

    public static function isMember(int $chatId, int $userId): bool
    {
        if ($chatId <= 0 || $userId <= 0) {
            return false;
        }
        $s = Db::pdo()->prepare('SELECT 1 FROM team_chat_members WHERE chat_id = ? AND user_id = ?');
        $s->execute([$chatId, $userId]);
        return (bool)$s->fetchColumn();
    }

    /** @return array<int,array> the members, as user rows (active or not: history keeps its names) */
    public static function members(int $chatId): array
    {
        $s = Db::pdo()->prepare(
            'SELECT u.id, u.username, u.full_name, u.role, u.active, m.last_read_id
               FROM team_chat_members m JOIN users u ON u.id = m.user_id
              WHERE m.chat_id = ? ORDER BY u.full_name, u.username'
        );
        $s->execute([$chatId]);
        return $s->fetchAll() ?: [];
    }

    /** Add people to a group (a direct or assistant chat cannot grow). Returns the names added. */
    public static function addMembers(int $chatId, array $userIds, int $actor, string $actorName): array
    {
        $chat = self::find($chatId);
        if (!$chat || $chat['kind'] !== 'group') {
            return [];
        }
        $pdo = Db::pdo();
        $ins = $pdo->prepare('INSERT IGNORE INTO team_chat_members (chat_id, user_id) VALUES (?, ?)');
        $who = $pdo->prepare('SELECT full_name, username FROM users WHERE id = ? AND active = 1');
        $added = [];
        $addedIds = [];
        foreach (array_unique(array_map('intval', $userIds)) as $u) {
            if ($u <= 0) {
                continue;
            }
            $who->execute([$u]);
            $row = $who->fetch();
            if (!$row) {
                continue;
            }
            $ins->execute([$chatId, $u]);
            if ($ins->rowCount() > 0) {
                $added[] = trim((string)$row['full_name']) ?: (string)$row['username'];
                $addedIds[] = $u;
            }
        }
        if ($added) {
            self::post($chatId, null, null, $actorName . ' ha aggiunto ' . implode(', ', $added), null, 'system');
            Log::write('team', 'chat_members_added', 'team_chat', $chatId, ['users' => $userIds, 'by' => $actor]);
            // Being added to an existing group is the same event from the
            // recipient's side as being put in a new one, so it gets the same
            // invitation — only the people actually added, not the whole group.
            self::invite($chatId, (string)($chat['name'] ?? ''), $addedIds, $actor);
        }
        return $added;
    }

    /** Leave a group. The last one out leaves an empty group behind, which nobody's list shows. */
    public static function leave(int $chatId, int $userId, string $userName): bool
    {
        $chat = self::find($chatId);
        if (!$chat || $chat['kind'] !== 'group' || !self::isMember($chatId, $userId)) {
            return false;
        }
        Db::pdo()->prepare('DELETE FROM team_chat_members WHERE chat_id = ? AND user_id = ?')->execute([$chatId, $userId]);
        self::post($chatId, null, null, $userName . ' ha lasciato il gruppo', null, 'system');
        Log::write('team', 'chat_left', 'team_chat', $chatId, ['user' => $userId]);
        return true;
    }

    public static function rename(int $chatId, string $name): bool
    {
        $name = mb_substr(trim($name), 0, 190);
        $chat = self::find($chatId);
        if (!$chat || $chat['kind'] !== 'group' || $name === '') {
            return false;
        }
        Db::pdo()->prepare('UPDATE team_chats SET name = ? WHERE id = ?')->execute([$name, $chatId]);
        return true;
    }

    // ---- reading ---------------------------------------------------------------------

    public static function find(int $chatId): ?array
    {
        $s = Db::pdo()->prepare('SELECT * FROM team_chats WHERE id = ?');
        $s->execute([$chatId]);
        return $s->fetch() ?: null;
    }

    /**
     * Everything this person is in, most recent first, each with the unread
     * count and — for a direct chat — the other person, so the list can be
     * drawn without a query per row. The assistant chat is not listed here:
     * the view pins it at the top itself.
     */
    public static function listFor(int $userId): array
    {
        $s = Db::pdo()->prepare(
            "SELECT c.*, m.last_read_id,
                    (SELECT COUNT(*) FROM team_messages x
                      WHERE x.chat_id = c.id AND x.id > m.last_read_id
                        AND x.role <> 'system' AND (x.sender_id IS NULL OR x.sender_id <> ?)) AS unread,
                    (SELECT COUNT(*) FROM team_chat_members y WHERE y.chat_id = c.id) AS member_count,
                    (SELECT COALESCE(NULLIF(TRIM(u.full_name), ''), u.username)
                       FROM team_chat_members o JOIN users u ON u.id = o.user_id
                      WHERE o.chat_id = c.id AND o.user_id <> ? LIMIT 1) AS other_name,
                    (SELECT x.body FROM team_messages x WHERE x.chat_id = c.id ORDER BY x.id DESC LIMIT 1) AS last_body,
                    (SELECT x.attachment_kind FROM team_messages x WHERE x.chat_id = c.id ORDER BY x.id DESC LIMIT 1) AS last_kind
               FROM team_chats c JOIN team_chat_members m ON m.chat_id = c.id AND m.user_id = ?
              WHERE c.kind <> 'ai'
              ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT 300"
        );
        $s->execute([$userId, $userId, $userId]);
        return $s->fetchAll() ?: [];
    }

    /** Messages of a chat after a given id, oldest first, meta decoded. */
    public static function thread(int $chatId, int $afterId = 0, int $limit = 300): array
    {
        $limit = max(1, min(2000, $limit));
        $s = Db::pdo()->prepare(
            "SELECT * FROM team_messages WHERE chat_id = ? AND id > ? ORDER BY id ASC LIMIT $limit"
        );
        $s->execute([$chatId, $afterId]);
        $rows = $s->fetchAll() ?: [];
        foreach ($rows as &$r) {
            $r['meta'] = $r['meta'] !== null ? (json_decode((string)$r['meta'], true) ?: null) : null;
        }
        unset($r);
        return $rows;
    }

    /** The newest N messages of a chat, oldest first — the first page opens at the end. */
    public static function recent(int $chatId, int $limit = 200): array
    {
        $limit = max(1, min(2000, $limit));
        $s = Db::pdo()->prepare('SELECT id FROM team_messages WHERE chat_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . ($limit - 1));
        $s->execute([$chatId]);
        $from = (int)($s->fetchColumn() ?: 0);
        return self::thread($chatId, max(0, $from - 1), $limit);
    }

    public static function message(int $messageId): ?array
    {
        $s = Db::pdo()->prepare('SELECT * FROM team_messages WHERE id = ?');
        $s->execute([$messageId]);
        $m = $s->fetch() ?: null;
        if ($m && $m['meta'] !== null) {
            $m['meta'] = json_decode((string)$m['meta'], true) ?: null;
        }
        return $m;
    }

    /** Move this member's read marker up to $lastId (never back). */
    public static function markRead(int $chatId, int $userId, int $lastId): void
    {
        Db::pdo()->prepare(
            'UPDATE team_chat_members SET last_read_id = GREATEST(last_read_id, ?) WHERE chat_id = ? AND user_id = ?'
        )->execute([$lastId, $chatId, $userId]);
    }

    /** Unread messages across every chat — the number on the sidebar entry. */
    public static function unreadTotal(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $s = Db::pdo()->prepare(
            "SELECT COUNT(*) FROM team_messages x
               JOIN team_chat_members m ON m.chat_id = x.chat_id AND m.user_id = ?
              WHERE x.id > m.last_read_id AND x.role <> 'system'
                AND (x.sender_id IS NULL OR x.sender_id <> ?)"
        );
        $s->execute([$userId, $userId]);
        return (int)$s->fetchColumn();
    }

    // ---- writing ---------------------------------------------------------------------

    /**
     * Post a message. $role: 'user' (a person), 'assistant' (the AI), 'system'
     * (the CRM noting something in the thread). Returns the message id.
     */
    public static function post(int $chatId, ?int $senderId, ?string $senderName, string $body, ?array $attachment = null,
                                string $role = 'user', ?array $meta = null): int
    {
        $body = trim($body);
        if ($body === '' && $attachment === null) {
            return 0;
        }
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO team_messages (chat_id, sender_id, sender_name, role, body, attachment_path, attachment_name, attachment_kind, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $chatId, $senderId ?: null, $senderName !== null ? mb_substr(trim($senderName), 0, 190) : null, $role, $body,
            $attachment['path'] ?? null, $attachment['name'] ?? null, $attachment['kind'] ?? null,
            $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE team_chats SET last_message_at = NOW() WHERE id = ?')->execute([$chatId]);
        if ($senderId) {
            self::markRead($chatId, $senderId, $id); // your own message is read by definition
        }
        return $id;
    }

    public static function setMeta(int $messageId, ?array $meta): void
    {
        Db::pdo()->prepare('UPDATE team_messages SET meta = ? WHERE id = ?')
            ->execute([$meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, $messageId]);
    }

    // ---- attachments -----------------------------------------------------------------

    /** 25 MB: room for a short phone video. php.ini's upload_max_filesize/post_max_size may cap it lower. */
    public const UPLOAD_MAX_BYTES = 26214400;

    private const EXT_IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'];
    private const EXT_AUDIO = ['mp3', 'm4a', 'ogg', 'oga', 'opus', 'wav', 'aac', 'amr', 'weba'];
    private const EXT_VIDEO = ['mp4', 'mov', 'mkv', 'avi', 'm4v'];
    private const EXT_FILE  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip', 'ppt', 'pptx', 'json'];

    /**
     * What a stored file is, for the bubble: a player, a video, a picture or a
     * download link. webm is both a voice note (Chrome's MediaRecorder) and a
     * video container, and 3gp likewise, so there the browser's MIME decides.
     */
    public static function kindOf(string $name, string $mime = ''): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = strtolower($mime);
        if ($ext === 'webm' || $ext === '3gp') {
            return str_starts_with($mime, 'video/') ? 'video' : 'audio';
        }
        if (in_array($ext, self::EXT_IMAGE, true)) {
            return 'image';
        }
        if (in_array($ext, self::EXT_AUDIO, true)) {
            return 'audio';
        }
        if (in_array($ext, self::EXT_VIDEO, true)) {
            return 'video';
        }
        return 'file';
    }

    /** The effective upload ceiling on this server, in bytes (the smaller of ours and php.ini's). */
    public static function uploadLimitBytes(): int
    {
        $ini = static function (string $k): int {
            $v = trim((string)ini_get($k));
            if ($v === '' || $v === '0' || $v === '-1') {
                return PHP_INT_MAX;
            }
            $n = (float)$v;
            return (int)match (strtolower(substr($v, -1))) {
                'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
            };
        };
        return (int)min(self::UPLOAD_MAX_BYTES, $ini('upload_max_filesize'), $ini('post_max_size'));
    }

    public static function uploadDir(): string
    {
        $cfg = (string)Config::get('app.team_upload_dir', '');
        if ($cfg !== '') {
            return rtrim($cfg, '/\\');
        }
        $root = dirname(__DIR__, 2);
        $preferred = $root . '/storage/uploads/team';
        if (is_dir($preferred) || @mkdir($preferred, 0775, true)) {
            return $preferred;
        }
        // Behind public/uploads/.htaccess (deny all): only ?tdl= serves it.
        return $root . '/public/uploads/team';
    }

    /**
     * Validate + store a $_FILES entry. Returns ['path','name','kind'] or null;
     * $err = too_big | bad_type | save_failed when a file WAS chosen and refused.
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
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $ok   = array_merge(self::EXT_IMAGE, self::EXT_AUDIO, self::EXT_VIDEO, self::EXT_FILE, ['webm', '3gp']);
        if (!in_array($ext, $ok, true)) {
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
        return ['path' => $stored, 'name' => mb_substr($orig, 0, 190), 'kind' => self::kindOf($orig, (string)($file['type'] ?? ''))];
    }

    /**
     * Stream a message's file and exit. Only after isMember(). Audio and video
     * honour Range requests, which is what lets a player seek and what iOS
     * Safari insists on before it plays anything at all.
     */
    public static function streamAttachment(array $msg): void
    {
        $path = self::uploadDir() . '/' . basename((string)$msg['attachment_path']);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Not found');
        }
        $name = (string)($msg['attachment_name'] ?: 'file');
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $kind = (string)($msg['attachment_kind'] ?: self::kindOf($name));
        $mimes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'heic' => 'image/heic',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg',
            'wav' => 'audio/wav', 'aac' => 'audio/aac', 'amr' => 'audio/amr', 'weba' => 'audio/webm',
            'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'pdf' => 'application/pdf',
        ];
        $mime = $mimes[$ext] ?? 'application/octet-stream';
        if ($ext === 'webm') {
            $mime = $kind === 'video' ? 'video/webm' : 'audio/webm';
        } elseif ($ext === '3gp') {
            $mime = $kind === 'video' ? 'video/3gpp' : 'audio/3gpp';
        }
        $inline = in_array($kind, ['audio', 'video', 'image'], true) || $ext === 'pdf';
        $size = (int)filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        if (($kind === 'audio' || $kind === 'video') && isset($_SERVER['HTTP_RANGE'])
            && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $r)) {
            if ($r[1] !== '') {
                $start = (int)$r[1];
                $end = $r[2] !== '' ? min((int)$r[2], $size - 1) : $size - 1;
            } elseif ($r[2] !== '') {
                $start = max(0, $size - (int)$r[2]);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            $status = 206;
        }
        http_response_code($status);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"');
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . ($end - $start + 1));
        if ($status === 206) {
            header("Content-Range: bytes $start-$end/$size");
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            exit;
        }
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, (int)min(65536, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($fh);
        exit;
    }

    /** Active staff for the "new chat" picker: everyone but yourself. */
    public static function people(int $exceptUserId): array
    {
        $s = Db::pdo()->prepare(
            'SELECT id, username, full_name, role FROM users WHERE active = 1 AND id <> ? ORDER BY full_name, username'
        );
        $s->execute([$exceptUserId]);
        return $s->fetchAll() ?: [];
    }
}
