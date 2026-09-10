<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use PDO;

/**
 * The warehouse — reads over the article registry the gestionale's ARTICO export
 * fills (see migration 046 and ArticleImport).
 *
 * READ ONLY, on purpose. The export is a full snapshot and the cron re-imports
 * it every fifteen minutes, so anything the CRM wrote into an article would be
 * overwritten by the next file without a word. The gestionale owns this data;
 * this class exists so the office can find and read it, not edit it.
 *
 * Stock can be NEGATIVE — 465 rows are, at the time of writing, because the
 * gestionale has sold more than it recorded receiving. That is the management
 * software's own figure and it is shown as it stands rather than clamped to
 * zero: hiding it would hide the thing the office needs to fix.
 */
final class Articles
{
    /**
     * A page of the registry.
     *
     * @param array $f q | state | category | supplier
     * @return array{rows:array, total:int, page:int, pages:int, per:int}
     */
    public static function search(array $f = [], int $page = 1, int $per = 50): array
    {
        $pdo   = Db::pdo();
        $where = ['1=1'];
        $args  = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            // Code and barcode are what people actually have in front of them —
            // read off a box, a label or a supplier's order line.
            $where[] = '(a.code LIKE ? OR a.barcode LIKE ? OR a.description LIKE ?
                         OR a.supplier LIKE ? OR a.supplier_code1 LIKE ? OR a.supplier_code2 LIKE ?
                         OR a.location LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like, $like, $like, $like);
        }

        $cat = trim((string)($f['category'] ?? ''));
        if ($cat !== '') {
            $where[] = 'a.category = ?';
            $args[]  = $cat;
        }
        $sup = trim((string)($f['supplier'] ?? ''));
        if ($sup !== '') {
            $where[] = 'a.supplier = ?';
            $args[]  = $sup;
        }

        $where[] = match ((string)($f['state'] ?? 'all')) {
            'in_stock' => 'a.stock > 0',
            'negative' => 'a.stock < 0',
            'ordered'  => 'a.stock_ordered > 0',
            'serials'  => 'a.has_serials = 1',
            default    => '1=1',
        };

        $w   = implode(' AND ', $where);
        $per = max(1, min(200, $per));

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM articles a WHERE $w");
        $cnt->execute($args);
        $total = (int)$cnt->fetchColumn();

        $pages = max(1, (int)ceil($total / $per));
        $page  = max(1, min($page, $pages));
        $off   = ($page - 1) * $per;

        // Something in stock is more useful at the top than an alphabetical
        // accident, so the ones you can actually sell lead.
        $stmt = $pdo->prepare(
            "SELECT a.* FROM articles a WHERE $w
              ORDER BY (a.stock > 0) DESC, a.description ASC, a.code ASC
              LIMIT $per OFFSET $off"
        );
        $stmt->execute($args);

        return ['rows' => $stmt->fetchAll() ?: [], 'total' => $total,
                'page' => $page, 'pages' => $pages, 'per' => $per];
    }

    /** Counts for the filter chips. */
    public static function counters(): array
    {
        $r = Db::pdo()->query(
            'SELECT COUNT(*) AS total,
                    SUM(stock > 0) AS in_stock,
                    SUM(stock < 0) AS negative,
                    SUM(stock_ordered > 0) AS ordered,
                    SUM(has_serials = 1) AS serials
               FROM articles'
        )->fetch();
        return array_map('intval', $r ?: ['total' => 0, 'in_stock' => 0, 'negative' => 0,
                                          'ordered' => 0, 'serials' => 0]);
    }

    /**
     * What the warehouse is worth, at cost. Positive and negative are reported
     * apart because netting them tells nobody anything: the honest answer to
     * "what is on the shelves" is the positive figure, and the negative one is
     * a reconciliation job, not a holding.
     */
    public static function stockValue(): array
    {
        $r = Db::pdo()->query(
            'SELECT COALESCE(SUM(CASE WHEN stock > 0 THEN stock * cost_price END), 0) AS on_hand,
                    COALESCE(SUM(CASE WHEN stock < 0 THEN stock * cost_price END), 0) AS owed,
                    COALESCE(SUM(CASE WHEN stock > 0 THEN stock END), 0) AS pieces
               FROM articles'
        )->fetch();
        return ['on_hand' => (float)$r['on_hand'], 'owed' => (float)$r['owed'],
                'pieces' => (float)$r['pieces']];
    }

    public static function find(int $id): ?array
    {
        $s = Db::pdo()->prepare('SELECT * FROM articles WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** @return string[] categories in use, for the filter dropdown. */
    public static function categories(): array
    {
        return Db::pdo()->query(
            "SELECT DISTINCT category FROM articles WHERE category <> '' ORDER BY category"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** @return string[] suppliers in use, for the filter dropdown. */
    public static function suppliers(): array
    {
        return Db::pdo()->query(
            "SELECT DISTINCT supplier FROM articles WHERE supplier <> '' ORDER BY supplier"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Other articles carrying this barcode. Nine are shared between rows —
     * compatible consumables ship under the maker's code — so a barcode lookup
     * that silently picked one would be lying about which item is in hand.
     */
    public static function barcodeTwins(array $article): array
    {
        $bc = trim((string)($article['barcode'] ?? ''));
        if ($bc === '') {
            return [];
        }
        $s = Db::pdo()->prepare(
            'SELECT id, code, description FROM articles WHERE barcode = ? AND id <> ? ORDER BY code'
        );
        $s->execute([$bc, (int)$article['id']]);
        return $s->fetchAll() ?: [];
    }

    /** The last file the cron took, so the page can say how fresh it is. */
    public static function lastImport(): ?array
    {
        $r = Db::pdo()->query('SELECT * FROM article_imports ORDER BY id DESC LIMIT 1')->fetch();
        return $r ?: null;
    }
}
