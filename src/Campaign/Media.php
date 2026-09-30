<?php
declare(strict_types=1);

namespace Glue\Campaign;

use Glue\Config;

/**
 * The photo or document a campaign carries (migration 063).
 *
 * WhatsApp is the reason these files are PUBLIC: TextMeBot does not receive an
 * upload, it fetches a URL, so the file has to be readable without a session
 * and its address has to end in a real extension. They therefore live in
 * public/uploads/campaigns (its own .htaccess grants what the parent denies)
 * under a random 32-character name — marketing material sent to customers
 * anyway, at an address nobody can guess.
 *
 * kind decides how it goes out: an IMAGE rides on TextMeBot's `file` parameter
 * and shows in the chat; anything else is a DOCUMENT and rides on `document`,
 * which is what makes a PDF arrive as a file instead of failing silently.
 */
final class Media
{
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];
    public const DOC_EXT   = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'];
    /** WhatsApp itself refuses much more than this, and the file travels by URL. */
    public const MAX_BYTES = 15 * 1024 * 1024;

    private const MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/public/uploads/campaigns';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Take the uploaded file.
     *
     * @return array{path:string,name:string,mime:string,kind:string}|null
     *         null with $err set: no_file | too_big | bad_type | save_failed
     */
    public static function store(?array $file, ?string &$err = null): ?array
    {
        $err  = null;
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (!$file || $code === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            $err = 'too_big';
            return null;
        }
        if ($code !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            $err = 'save_failed';
            return null;
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > self::MAX_BYTES) {
            $err = 'too_big';
            return null;
        }
        $orig = (string)($file['name'] ?? 'file');
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $ext  = $ext === 'jpeg' ? 'jpg' : $ext;
        $isImage = in_array($ext, self::IMAGE_EXT, true);
        if (!$isImage && !in_array($ext, self::DOC_EXT, true)) {
            $err = 'bad_type';
            return null;
        }
        // An "image" that is not one would reach the customer as a broken chat
        // bubble, so the bytes have to agree with the extension.
        if ($isImage && @getimagesize((string)$file['tmp_name']) === false) {
            $err = 'bad_type';
            return null;
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], self::dir() . '/' . $stored)) {
            $err = 'save_failed';
            return null;
        }
        @chmod(self::dir() . '/' . $stored, 0644);   // Apache serves it to WhatsApp
        return [
            'path' => $stored,
            'name' => mb_substr($orig, 0, 190),
            'mime' => self::MIME[$ext] ?? 'application/octet-stream',
            'kind' => $isImage ? 'image' : 'document',
        ];
    }

    /** The address WhatsApp fetches. Absolute: TextMeBot is not on this server. */
    public static function url(string $path): string
    {
        return Config::appBaseUrl() . '/uploads/campaigns/' . basename($path);
    }

    public static function fullPath(string $path): string
    {
        return self::dir() . '/' . basename($path);
    }

    public static function remove(?string $path): void
    {
        if ($path !== null && $path !== '') {
            @unlink(self::fullPath($path));
        }
    }
}
