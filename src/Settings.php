<?php
declare(strict_types=1);

namespace Glue;

use PDO;
use Throwable;

/**
 * Dashboard-editable settings, stored as flat dot-path keys (e.g.
 * 'bitrix.base_url') in the `settings` table. At boot these overlay the file
 * config, so Config::get('bitrix.base_url') transparently returns the
 * dashboard value when present, or the config.php default otherwise.
 *
 * Only DB credentials live in config.php (chicken-and-egg); everything else is
 * configurable from the web UI.
 */
final class Settings
{
    private static ?array $flat = null;

    /** All settings as a flat key=>value map. Empty if the table doesn't exist yet. */
    public static function all(): array
    {
        if (self::$flat === null) {
            self::$flat = [];
            try {
                $rows = Db::pdo()->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
                self::$flat = $rows ?: [];
            } catch (Throwable) {
                self::$flat = []; // table missing / DB down — fall back to file config
            }
        }
        return self::$flat;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute([$key, $value]);
        self::$flat = null; // bust cache
    }

    /** Save many at once. Keys are dot-paths; null/'' values are stored as-is. */
    public static function setMany(array $pairs): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            );
            foreach ($pairs as $k => $v) {
                $stmt->execute([$k, $v === null ? null : (string)$v]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::$flat = null;
    }

    /**
     * Expand the flat dot-path map into a nested array for Config::applyOverlay.
     * 'a.b.c' => x  becomes  ['a' => ['b' => ['c' => x]]]. Blank strings are
     * skipped so an empty field never blanks out a real config.php default.
     */
    public static function nested(): array
    {
        $out = [];
        foreach (self::all() as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts = explode('.', $key);
            $ref =& $out;
            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    $ref[$part] = self::coerce($value);
                } else {
                    if (!isset($ref[$part]) || !is_array($ref[$part])) {
                        $ref[$part] = [];
                    }
                    $ref =& $ref[$part];
                }
            }
            unset($ref);
        }
        return $out;
    }

    /** Turn stored strings into ints/bools/JSON arrays where they obviously are. */
    private static function coerce(string $v): mixed
    {
        if ($v === 'true')  return true;
        if ($v === 'false') return false;
        if (is_numeric($v) && (string)(int)$v === $v) return (int)$v;
        $trim = ltrim($v);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $v;
    }
}
