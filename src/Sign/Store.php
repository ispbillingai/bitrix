<?php
declare(strict_types=1);

namespace Glue\Sign;

use Glue\Config;

/**
 * Where signing files live on disk. Same rule as ticket attachments: prefer
 * storage/ above the web root, fall back to a folder under public/ that
 * .htaccess blocks, and never serve either directly — originals and signed PDFs
 * only ever leave through a permission-checked endpoint.
 *
 * Kinds: 'docs' (originals + sealed PDFs) and 'keys' (the signing key pair).
 */
final class Store
{
    public static function path(string $kind): string
    {
        $cfg = (string)Config::get('sign.storage_dir', '');
        if ($cfg !== '') {
            $dir = rtrim($cfg, '/\\') . '/' . $kind;
            self::ensure($dir);
            return $dir;
        }
        $root = dirname(__DIR__, 2);
        $preferred = $root . '/storage/sign/' . $kind;
        if (is_dir($preferred) || @mkdir($preferred, 0770, true)) {
            return $preferred;
        }
        // Last resort, inside the web root. Drop an explicit deny next to the
        // files rather than relying on the parent's rules — this folder can hold
        // the signing private key, and a stray directive that re-opens
        // public/uploads must not take it with it.
        $fallback = $root . '/public/uploads/sign/' . $kind;
        self::ensure($fallback);
        $deny = $fallback . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        return $fallback;
    }

    /** Absolute path of a stored file, or null if the name escapes the folder. */
    public static function file(string $kind, string $name): ?string
    {
        $name = basename($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }
        return self::path($kind) . '/' . $name;
    }

    private static function ensure(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
    }
}
