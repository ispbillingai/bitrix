<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;

/**
 * A product's sheet: its photos, its documents (datasheets, manuals, brochures)
 * and the longer text and link the price-list catalogue shows (migration 060).
 *
 * All of it is CRM-owned. The ARTICO export carries none of it (its "Immagine"
 * column says BLOB and nothing more), so nothing here is ever converged by the
 * import, and setting it does not detach a gestionale article.
 *
 * Files live outside the web root and leave only through the dashboard's
 * ?amf= endpoint. Photos are normalised on the way in — upright, bounded,
 * JPEG on a white ground — and each gets a small copy for the catalogue grid
 * and the PDF, so a 40-product price list is not 40 phone originals.
 */
final class ArticleMedia
{
    public const PHOTO_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    public const FILE_EXT  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
                              'txt', 'csv', 'zip', 'jpg', 'jpeg', 'png', 'webp', 'mp4', 'dwg', 'dxf'];
    public const PHOTO_MAX_BYTES = 15 * 1024 * 1024;
    public const FILE_MAX_BYTES  = 25 * 1024 * 1024;

    private const PHOTO_MAX_PX = 1600;
    private const THUMB_PX     = 480;
    /** 60 megapixels: ~240 MB decoded, inside the 512 MB the upload handler asks for. */
    private const MAX_PIXELS   = 60_000_000;

    /** Opened in the browser rather than downloaded. */
    private const INLINE = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                            'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf',
                            'txt' => 'text/plain; charset=utf-8', 'mp4' => 'video/mp4'];

    public static function dir(): string
    {
        $root = dirname(__DIR__, 2);
        $dir  = $root . '/storage/uploads/articles';
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            return $dir;
        }
        $fallback = $root . '/public/uploads/articles'; // behind public/uploads/.htaccess (deny all)
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }
        return $fallback;
    }

    public static function find(int $id): ?array
    {
        $s = Db::pdo()->prepare('SELECT * FROM article_media WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** The photos, cover first. */
    public static function photos(int $articleId): array
    {
        return self::ofKind($articleId, 'photo');
    }

    public static function files(int $articleId): array
    {
        return self::ofKind($articleId, 'file');
    }

    private static function ofKind(int $articleId, string $kind): array
    {
        $s = Db::pdo()->prepare('SELECT * FROM article_media WHERE article_id = ? AND kind = ? ORDER BY sort, id');
        $s->execute([$articleId, $kind]);
        return $s->fetchAll() ?: [];
    }

    /**
     * Media rows by id, keyed by id — the catalogue carries each article's
     * cover id in its own query and fetches the rows in one go.
     *
     * @param int[] $ids
     * @return array<int,array>
     */
    public static function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Db::pdo()->query('SELECT * FROM article_media WHERE id IN (' . implode(',', $chunk) . ')')->fetchAll() ?: [];
            foreach ($rows as $r) {
                $out[(int)$r['id']] = $r;
            }
        }
        return $out;
    }

    /**
     * Each article's cover photo (its first), for a list of articles.
     *
     * @param int[] $articleIds
     * @return array<int,array> article id => media row
     */
    public static function coversFor(array $articleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds))));
        if (!$ids) {
            return [];
        }
        $rows = Db::pdo()->query(
            "SELECT * FROM article_media WHERE kind = 'photo' AND article_id IN (" . implode(',', $ids) . ')
              ORDER BY article_id, sort, id'
        )->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['article_id']] ??= $r;
        }
        return $out;
    }

    /**
     * Store every photo of a multi-file input.
     * @return array{count:int, errors:string[]}
     */
    public static function addPhotos(int $articleId, ?array $files, ?int $userId): array
    {
        return self::addMany($articleId, 'photo', $files, $userId);
    }

    /** @return array{count:int, errors:string[]} */
    public static function addFiles(int $articleId, ?array $files, ?int $userId): array
    {
        return self::addMany($articleId, 'file', $files, $userId);
    }

    private static function addMany(int $articleId, string $kind, ?array $files, ?int $userId): array
    {
        $out = ['count' => 0, 'errors' => []];
        if (!Articles::find($articleId)) {
            $out['errors'][] = 'not_found';
            return $out;
        }
        $next = (int)Db::pdo()->query(
            'SELECT COALESCE(MAX(sort), 0) FROM article_media WHERE article_id = ' . $articleId . " AND kind = '" . $kind . "'"
        )->fetchColumn();

        foreach (self::filesList($files) as $f) {
            $err    = null;
            $stored = $kind === 'photo' ? self::storePhoto($f, $err) : self::storeFile($f, $err);
            if ($stored === null) {
                $out['errors'][] = $f['name'] . ': ' . $err;
                continue;
            }
            $next += 10;
            Db::pdo()->prepare(
                'INSERT INTO article_media (article_id, kind, path, thumb_path, name, mime, size_bytes, sort, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$articleId, $kind, $stored['path'], $stored['thumb'], $stored['name'],
                        $stored['mime'], $stored['bytes'], $next, $userId ?: null]);
            $out['count']++;
        }
        if ($out['count'] > 0) {
            Log::write('crm', 'article_media_added', 'article', $articleId,
                ['kind' => $kind, 'count' => $out['count'], 'by' => $userId]);
        }
        return $out;
    }

    public static function delete(int $id, ?int $userId): bool
    {
        $m = self::find($id);
        if (!$m) {
            return false;
        }
        self::unlinkFiles($m);
        Db::pdo()->prepare('DELETE FROM article_media WHERE id = ?')->execute([$id]);
        Log::write('crm', 'article_media_deleted', 'article', (int)$m['article_id'],
            ['kind' => $m['kind'], 'name' => $m['name'], 'by' => $userId]);
        return true;
    }

    /** Put a photo first: it becomes the product's picture in the catalogue. */
    public static function makeCover(int $id): bool
    {
        $m = self::find($id);
        if (!$m || $m['kind'] !== 'photo') {
            return false;
        }
        $min = (int)Db::pdo()->query(
            'SELECT COALESCE(MIN(sort), 0) FROM article_media WHERE article_id = ' . (int)$m['article_id'] . " AND kind = 'photo'"
        )->fetchColumn();
        Db::pdo()->prepare('UPDATE article_media SET sort = ? WHERE id = ?')->execute([$min - 10, $id]);
        return true;
    }

    /** Everything filed on an article, gone with it (a CRM product deleted for real). */
    public static function deleteAllFor(int $articleId): void
    {
        foreach (array_merge(self::photos($articleId), self::files($articleId)) as $m) {
            self::unlinkFiles($m);
        }
        Db::pdo()->prepare('DELETE FROM article_media WHERE article_id = ?')->execute([$articleId]);
    }

    private static function unlinkFiles(array $m): void
    {
        @unlink(self::dir() . '/' . basename((string)$m['path']));
        if (!empty($m['thumb_path'])) {
            @unlink(self::dir() . '/' . basename((string)$m['thumb_path']));
        }
    }

    /** Send a stored file and stop. Only after the caller's permission check. */
    public static function stream(array $m, bool $thumb = false): void
    {
        $name = $thumb && !empty($m['thumb_path']) ? (string)$m['thumb_path'] : (string)$m['path'];
        $full = self::dir() . '/' . basename($name);
        if (!is_file($full)) {
            http_response_code(404);
            exit('Not found');
        }
        $ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mime = self::INLINE[$ext] ?? 'application/octet-stream';
        $file = str_replace(['"', "\r", "\n"], '', (string)$m['name']);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . (isset(self::INLINE[$ext]) ? 'inline' : 'attachment') . '; filename="' . $file . '"');
        header('Content-Length: ' . (string)filesize($full));
        header('X-Content-Type-Options: nosniff');
        // Stored names are random and never rewritten, so a day of caching is safe
        // and keeps the catalogue grid quick when an agent pages back and forth.
        header('Cache-Control: private, max-age=86400');
        readfile($full);
        exit;
    }

    /** The bytes the PDF embeds: the small copy when there is one. Null when unreadable. */
    public static function pdfBytes(array $m): ?string
    {
        foreach ([(string)($m['thumb_path'] ?? ''), (string)$m['path']] as $name) {
            if ($name === '') {
                continue;
            }
            $full = self::dir() . '/' . basename($name);
            if (is_file($full)) {
                $b = (string)file_get_contents($full);
                return $b !== '' ? $b : null;
            }
        }
        return null;
    }

    /**
     * The product sheet's own text and link. The link must be a plain web
     * address: it becomes the href the catalogue photo opens and a link in
     * the PDF, so anything that is not http(s) is refused, not stored.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function saveSheet(int $articleId, string $description, string $url, ?int $userId): array
    {
        if (!Articles::find($articleId)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $url = trim($url);
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');   // "www.maker.it/x" is what people paste
        }
        if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || mb_strlen($url) > 500)) {
            return ['ok' => false, 'error' => 'bad_url'];
        }
        $desc = trim(str_replace("\r\n", "\n", $description));
        Db::pdo()->prepare('UPDATE articles SET web_description = ?, info_url = ? WHERE id = ?')
            ->execute([$desc !== '' ? mb_substr($desc, 0, 5000) : null, $url !== '' ? $url : null, $articleId]);
        Log::write('crm', 'article_sheet_saved', 'article', $articleId, ['url' => $url !== '', 'by' => $userId]);
        return ['ok' => true];
    }

    /** Lift the request's memory limit to $to — never lower it (the CLI runs unlimited). */
    public static function raiseMemory(string $to): void
    {
        $cur = trim((string)ini_get('memory_limit'));
        if ($cur !== '-1' && self::iniBytes($cur) < self::iniBytes($to)) {
            @ini_set('memory_limit', $to);
        }
    }

    /**
     * The most one form submission can carry, in bytes. Past post_max_size PHP
     * drops the whole body — files AND fields — so the page checks the total
     * before sending rather than let a batch of phone photos vanish silently.
     */
    public static function postLimit(): int
    {
        $post = self::iniBytes((string)ini_get('post_max_size'));
        return $post > 0 ? max(1048576, $post - 512 * 1024) : 0;   // room for the other fields
    }

    /** "25M" -> 26214400. */
    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        };
    }

    public static function size(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        return max(1, (int)round($bytes / 1024)) . ' KB';
    }

    // ---- storing ------------------------------------------------------------------

    /** Flatten PHP's multi-file $_FILES shape into one array per file. */
    private static function filesList(?array $files): array
    {
        if (!$files || !isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return (int)($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
        }
        $out = [];
        foreach ($files['name'] as $i => $name) {
            if ((int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string)$name,
                'type'     => (string)($files['type'][$i] ?? ''),
                'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
                'error'    => (int)($files['error'][$i] ?? UPLOAD_ERR_OK),
                'size'     => (int)($files['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    /** Common checks. Returns the lower-case extension, or null with $err set. */
    private static function accept(array $f, array $exts, int $max, ?string &$err): ?string
    {
        $code = (int)$f['error'];
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            $err = 'too_big';
            return null;
        }
        if ($code !== UPLOAD_ERR_OK || (string)$f['tmp_name'] === '' || !is_uploaded_file((string)$f['tmp_name'])) {
            $err = 'save_failed';
            return null;
        }
        if ((int)$f['size'] <= 0 || (int)$f['size'] > $max) {
            $err = 'too_big';
            return null;
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) {
            $err = 'bad_type';
            return null;
        }
        return $ext === 'jpeg' ? 'jpg' : $ext;
    }

    /** @return array{path:string, thumb:?string, name:string, mime:string, bytes:int}|null */
    private static function storeFile(array $f, ?string &$err): ?array
    {
        $ext = self::accept($f, self::FILE_EXT, self::FILE_MAX_BYTES, $err);
        if ($ext === null) {
            return null;
        }
        $dir    = self::dir();
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $stored)) {
            $err = 'save_failed';
            return null;
        }
        @chmod($dir . '/' . $stored, 0640);
        return [
            'path'  => $stored,
            'thumb' => null,
            'name'  => mb_substr((string)$f['name'], 0, 190),
            'mime'  => self::INLINE[$ext] ?? 'application/octet-stream',
            'bytes' => (int)filesize($dir . '/' . $stored),
        ];
    }

    /** @return array{path:string, thumb:?string, name:string, mime:string, bytes:int}|null */
    private static function storePhoto(array $f, ?string &$err): ?array
    {
        $ext = self::accept($f, self::PHOTO_EXT, self::PHOTO_MAX_BYTES, $err);
        if ($ext === null) {
            return null;
        }
        $tmp  = (string)$f['tmp_name'];
        $info = @getimagesize($tmp);
        if ($info === false) {           // the extension lied, or a format PHP cannot read (HEIC)
            $err = 'bad_type';
            return null;
        }
        if ((int)$info[0] * (int)$info[1] > self::MAX_PIXELS) {
            $err = 'too_big';            // decoding it would not fit in the request's memory
            return null;
        }

        $dir  = self::dir();
        $base = bin2hex(random_bytes(16));
        self::raiseMemory('512M');   // one decoded phone photo is tens of MB
        $img  = self::decode($tmp, $info);
        if ($img !== null) {
            $big   = self::jpeg($img, self::PHOTO_MAX_PX, 82);
            $small = self::jpeg($img, self::THUMB_PX, 78);
            imagedestroy($img);
            if ($big !== null) {
                $path  = $base . '.jpg';
                $thumb = $small !== null ? $base . '-t.jpg' : null;
                if (file_put_contents($dir . '/' . $path, $big, LOCK_EX) === false
                    || ($thumb !== null && file_put_contents($dir . '/' . $thumb, $small, LOCK_EX) === false)) {
                    $err = 'save_failed';
                    return null;
                }
                @chmod($dir . '/' . $path, 0640);
                if ($thumb !== null) {
                    @chmod($dir . '/' . $thumb, 0640);
                }
                return ['path' => $path, 'thumb' => $thumb, 'name' => mb_substr((string)$f['name'], 0, 190),
                        'mime' => 'image/jpeg', 'bytes' => strlen($big)];
            }
        }

        // No GD, or GD could not read it: keep the original as it came.
        $path = $base . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $path)) {
            $err = 'save_failed';
            return null;
        }
        @chmod($dir . '/' . $path, 0640);
        return ['path' => $path, 'thumb' => null, 'name' => mb_substr((string)$f['name'], 0, 190),
                'mime' => self::INLINE[$ext] ?? 'application/octet-stream', 'bytes' => (int)filesize($dir . '/' . $path)];
    }

    /**
     * Decode to a true-colour image no larger than PHOTO_MAX_PX, upright, on
     * WHITE. Product shots are often PNGs cut out on a transparent ground;
     * flattened naively that ground turns black, and a black box is not what a
     * catalogue should show.
     *
     * Scaling and flattening happen in ONE copy onto a bounded canvas, and the
     * rotation after it: a 12-megapixel phone photo is ~48 MB decoded, and the
     * web request's memory would not hold it twice.
     *
     * @return \GdImage|null
     */
    private static function decode(string $tmp, array $info)
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring((string)file_get_contents($tmp));
        if ($src === false) {
            return null;
        }
        $w   = imagesx($src);
        $h   = imagesy($src);
        $k   = min(1.0, self::PHOTO_MAX_PX / max($w, $h));
        $nw  = max(1, (int)round($w * $k));
        $nh  = max(1, (int)round($h * $k));
        $img = imagecreatetruecolor($nw, $nh);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagealphablending($img, true);
        imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        // GD drops the EXIF tag, so a portrait phone photo would lie on its side.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            $rot  = match ((int)($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($rot !== 0) {
                $r = imagerotate($img, $rot, 0);
                if ($r !== false) {
                    imagedestroy($img);
                    $img = $r;
                }
            }
        }
        return $img;
    }

    /** @param \GdImage $img */
    private static function jpeg($img, int $maxPx, int $quality): ?string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scaled = $img;
        if (max($w, $h) > $maxPx) {
            $k = $maxPx / max($w, $h);
            $scaled = imagescale($img, max(1, (int)round($w * $k)), max(1, (int)round($h * $k)),
                defined('IMG_BICUBIC') ? IMG_BICUBIC : IMG_BILINEAR_FIXED) ?: $img;
        }
        ob_start();
        imagejpeg($scaled, null, $quality);
        $bytes = (string)ob_get_clean();
        if ($scaled !== $img) {
            imagedestroy($scaled);
        }
        return $bytes !== '' ? $bytes : null;
    }
}
