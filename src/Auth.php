<?php
declare(strict_types=1);

namespace Glue;

use PDO;
use Throwable;

/**
 * Dashboard user accounts. Passwords are bcrypt-hashed. On first use the table
 * is seeded with admin/admin so there's always a known login; change it from
 * the Users page. config.dashboard.password remains a master fallback so an
 * operator can never be locked out.
 */
final class Auth
{
    /** Create the default admin/admin if no users exist. No-op if table missing. */
    public static function ensureSeed(): void
    {
        try {
            if (self::count() === 0) {
                self::create('admin', 'admin', 'admin');
            }
        } catch (Throwable) {
            // users table not migrated yet — ignore; login falls back to config
        }
    }

    public static function count(): int
    {
        return (int)Db::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** Verify credentials; returns the user row (id, username, role) or null. */
    public static function verify(string $username, string $password): ?array
    {
        try {
            $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
            $stmt->execute([$username]);
            $u = $stmt->fetch();
            if ($u && password_verify($password, $u['password_hash'])) {
                unset($u['password_hash']);
                return $u;
            }
        } catch (Throwable) {
            // table missing — caller handles config fallback
        }
        return null;
    }

    /** @return array<int,array> all users (without hashes) */
    public static function all(): array
    {
        $rows = Db::pdo()->query('SELECT id, username, role, active, created_at FROM users ORDER BY id')->fetchAll();
        return $rows ?: [];
    }

    public static function create(string $username, string $password, string $role = 'admin'): int
    {
        $username = trim($username);
        if ($username === '' || strlen($password) < 3) {
            throw new \InvalidArgumentException('username required and password must be at least 3 characters');
        }
        $stmt = Db::pdo()->prepare(
            'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role ?: 'admin']);
        return (int)Db::pdo()->lastInsertId();
    }

    public static function setPassword(int $id, string $password): void
    {
        if (strlen($password) < 3) {
            throw new \InvalidArgumentException('password must be at least 3 characters');
        }
        Db::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Db::pdo()->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    public static function delete(int $id): void
    {
        Db::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}
