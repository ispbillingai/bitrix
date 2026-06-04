<?php
declare(strict_types=1);

namespace Glue;

use RuntimeException;

/**
 * Loads config/config.php (a returned array) once and exposes dot-path lookups:
 *   Config::get('bitrix.base_url')
 *   Config::get('reminders.sign_offsets_days', [10, 5, 0])
 */
final class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        if (!is_file($path)) {
            throw new RuntimeException(
                "Missing config: $path — copy config/config.sample.php to config/config.php"
            );
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new RuntimeException("Config file must return an array: $path");
        }
        self::$data = $data;
        self::$loaded = true;
    }

    /**
     * Deep-merge an overlay over the loaded config (dashboard settings win over
     * file defaults). Scalars and lists are replaced; nested maps are merged.
     */
    public static function applyOverlay(array $overlay): void
    {
        self::$data = self::deepMerge(self::$data, $overlay);
    }

    private static function deepMerge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])
                && array_keys($v) !== range(0, count($v) - 1)) { // assoc map, not a list
                $base[$k] = self::deepMerge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /** Dot-path getter with a default. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** Whole sub-array (e.g. the 'db' block), or [] if absent. */
    public static function section(string $key): array
    {
        $v = self::get($key, []);
        return is_array($v) ? $v : [];
    }
}
