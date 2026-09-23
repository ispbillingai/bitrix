<?php
declare(strict_types=1);

namespace Glue;

use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Glue\Reminder\Templates;

/**
 * "Ho dimenticato la password" for staff logins — a one-time link sent to the
 * account's own WhatsApp and email.
 *
 * Three rules this follows, all of them about the same thing: the login page is
 * open to the internet, so it must not become a way to learn things.
 *
 *   * It NEVER says whether an account exists. Whatever is typed in, the answer
 *     is the same sentence. An honest "no such user" turns the form into a
 *     directory of who works here.
 *   * The link goes to the address already ON the account. Nobody chooses where
 *     it is sent, so knowing a username buys nothing.
 *   * It is rate limited per account and per caller, because a form that sends
 *     a WhatsApp on demand is otherwise a way to make someone's phone buzz all
 *     night, at the company's expense.
 *
 * The token is single-use and short-lived, and spending one cancels every other
 * link outstanding for that account.
 */
final class PasswordReset
{
    /** How long a link stays good. Long enough to read a message, not a day. */
    private const TTL_MIN = 60;

    /** Per account, per hour. */
    private const MAX_PER_USER = 3;

    /** Per caller IP, per hour — one person forgetting several people's passwords. */
    private const MAX_PER_IP = 8;

    /** The shortest password a reset will accept. Higher than Auth's floor of 3. */
    public const MIN_LEN = 8;

    /**
     * Ask for a link. Returns nothing the caller can use to tell whether the
     * account existed — that is the point.
     *
     * @param string $identifier username, email or phone, as typed
     */
    public static function request(string $identifier, ?string $ip = null): void
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return;
        }
        $ip = $ip ?? (string)($_SERVER['REMOTE_ADDR'] ?? '');

        if (self::ipThrottled($ip)) {
            Log::write('auth', 'password_reset_throttled', 'user', 0, ['ip' => $ip]);
            return;
        }
        $user = self::findUser($identifier);
        if (!$user) {
            // Logged, so a burst of guesses is visible in Eventi, but the caller
            // is told the same thing either way.
            Log::write('auth', 'password_reset_unknown', 'user', 0,
                ['typed' => mb_substr($identifier, 0, 60), 'ip' => $ip]);
            return;
        }
        if (self::userThrottled((int)$user['id'])) {
            Log::write('auth', 'password_reset_throttled', 'user', (int)$user['id'], ['ip' => $ip]);
            return;
        }

        $phone = trim((string)($user['phone'] ?? ''));
        $email = trim((string)($user['email'] ?? ''));
        if ($phone === '' && $email === '') {
            // Nothing to send to. An administrator has to set it by hand — and
            // the caller still sees the same message, so this is only visible in
            // the log, where somebody can act on it.
            Log::write('auth', 'password_reset_unreachable', 'user', (int)$user['id'], []);
            return;
        }
        $channel = $phone !== '' && $email !== '' ? 'both' : ($phone !== '' ? 'whatsapp' : 'email');

        $token = bin2hex(random_bytes(24));
        Db::pdo()->prepare(
            'INSERT INTO password_resets (user_id, token, channel, expires_at, request_ip)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            (int)$user['id'], $token, $channel,
            date('Y-m-d H:i:s', time() + self::TTL_MIN * 60),
            mb_substr($ip, 0, 45) ?: null,
        ]);

        $name = trim((string)($user['full_name'] ?? '')) ?: (string)$user['username'];
        $vars = [
            'name'        => $name,
            'agent_name'  => $name,
            'agent_phone' => $phone,
            'agent_email' => $email,
            'company'     => (string)Config::get('app.company_name', 'CRM'),
            'link'        => self::link($token),
            'minutes'     => (string)self::TTL_MIN,
        ];
        $lang = Templates::lang(Config::get('app.default_lang', 'it'));

        // Addressed like an agent (agent_phone / agent_email in the payload is
        // what the dispatcher reads), and due now — so it goes inline if this
        // request still has inline budget, and otherwise on the cron's next
        // tick. Either way the person is not left waiting on WhatsApp's gap.
        (new Scheduler())->enqueue([
            'entity_type'    => 'user',
            'entity_id'      => (int)$user['id'],
            'rule_key'       => 'password_reset',
            'recipient_type' => 'agent',
            'channel'        => $channel,
            'due_at'         => date('Y-m-d H:i:s'),
            'lang'           => $lang,
            'payload'        => $vars + [
                'raw_wa'      => Templates::whatsapp('password_reset', $vars, $lang),
                'raw_subject' => (string)Templates::email('password_reset', $vars, $lang)['subject'],
                'raw_html'    => (string)Templates::email('password_reset', $vars, $lang)['html'],
            ],
            // One link per row: two requests a minute apart are two real links,
            // and deduping them would leave the second press doing nothing.
            'dedupe_key'     => 'pwreset:' . $token,
        ]);

        Log::write('auth', 'password_reset_sent', 'user', (int)$user['id'],
            ['channel' => $channel, 'ip' => $ip]);
    }

    /** The account a live token belongs to, or null. */
    public static function resolve(string $token): ?array
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            "SELECT r.id AS reset_id, r.user_id, r.expires_at,
                    u.username, u.full_name, u.active
               FROM password_resets r
               JOIN users u ON u.id = r.user_id
              WHERE r.token = ? AND r.used_at IS NULL AND r.expires_at >= NOW() AND u.active = 1
              LIMIT 1"
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Spend the token and set the new password.
     *
     * @return string '' on success, otherwise the reason key for the page
     */
    public static function complete(string $token, string $password, string $confirm): string
    {
        $row = self::resolve($token);
        if (!$row) {
            return 'bad_token';
        }
        if ($password !== $confirm) {
            return 'mismatch';
        }
        if (mb_strlen($password) < self::MIN_LEN) {
            return 'too_short';
        }

        $pdo = Db::pdo();
        // Spend this one first: if anything below throws, the link is already
        // gone rather than left reusable.
        $spend = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        $spend->execute([(int)$row['reset_id']]);
        if ($spend->rowCount() === 0) {
            return 'bad_token'; // somebody else spent it between resolve and here
        }

        Auth::setPassword((int)$row['user_id'], $password);

        // Every other link outstanding for this account dies with it. Somebody
        // who asked twice, or an attacker who got a link issued, must not still
        // be holding a working one.
        $pdo->prepare(
            'UPDATE password_resets SET used_at = NOW()
              WHERE user_id = ? AND used_at IS NULL'
        )->execute([(int)$row['user_id']]);

        Log::write('auth', 'password_reset_done', 'user', (int)$row['user_id'], []);
        return '';
    }

    public static function link(string $token): string
    {
        return rtrim(Config::appBaseUrl(), '/') . '/reset-password.php?t=' . $token;
    }

    // ---- internals -------------------------------------------------------------

    /**
     * Username, email or phone — whichever the person remembers. The phone is
     * compared on digits alone, because nobody types their own number the same
     * way twice.
     */
    private static function findUser(string $identifier): ?array
    {
        $digits = preg_replace('/\D+/', '', $identifier);
        $stmt = Db::pdo()->prepare(
            "SELECT id, username, full_name, phone, email
               FROM users
              WHERE active = 1
                AND (username = :u1
                     OR (email <> '' AND email = :u2)
                     OR (:d <> '' AND LENGTH(:d2) >= 8
                         AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '+', ''), ' ', ''), '-', ''), '.', '') LIKE CONCAT('%', :d3)))
              ORDER BY id LIMIT 1"
        );
        $stmt->execute([
            ':u1' => $identifier,
            ':u2' => $identifier,
            ':d'  => $digits, ':d2' => $digits, ':d3' => $digits,
        ]);
        return $stmt->fetch() ?: null;
    }

    private static function userThrottled(int $userId): bool
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM password_resets
              WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() >= self::MAX_PER_USER;
    }

    private static function ipThrottled(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM password_resets
              WHERE request_ip = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)'
        );
        $stmt->execute([mb_substr($ip, 0, 45)]);
        return (int)$stmt->fetchColumn() >= self::MAX_PER_IP;
    }
}
