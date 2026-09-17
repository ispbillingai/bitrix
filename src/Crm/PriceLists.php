<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;

/**
 * Sales price lists (migration 060): named selections of warehouse articles
 * that the agents browse as a catalogue and hand to customers as a PDF.
 *
 * A list holds no copy of the product. Description, code, VAT, stock and the
 * base price are read from `articles` every time, so a list is as current as
 * the fifteen-minute import. What a list does own:
 *
 *   - which articles are in it — the flag, one price_list_items row each;
 *   - how its prices are made — from LISTINO or price list 4, moved up or
 *     down by adjust_pct, shown net or VAT included;
 *   - optionally its own net price for an article, which then wins.
 *
 * An article with no price at all (2,239 of them in the export) is shown as
 * "price on request" rather than as 0,00: a catalogue that offers something
 * for free is worse than one that says to ask.
 *
 * Archived articles drop out of every list on their own; their row is kept,
 * so restoring the article puts it back where it was.
 */
final class PriceLists
{
    public const PER_PAGE = 48;

    /** @return array[] every list with its product count, by name */
    public static function all(bool $visibleOnly = false): array
    {
        return Db::pdo()->query(
            'SELECT l.*,
                    (SELECT COUNT(*) FROM price_list_items i JOIN articles a ON a.id = i.article_id
                      WHERE i.list_id = l.id AND a.archived = 0) AS items
               FROM price_lists l' . ($visibleOnly ? ' WHERE l.visible = 1' : '') . '
              ORDER BY l.name, l.id'
        )->fetchAll() ?: [];
    }

    public static function find(int $id): ?array
    {
        $s = Db::pdo()->prepare('SELECT * FROM price_lists WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /**
     * Create ($id null) or edit a list.
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function save(array $d, ?int $id, ?int $userId): array
    {
        $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 120);
        if ($name === '') {
            return ['ok' => false, 'error' => 'no_name'];
        }
        if ($id !== null && !self::find($id)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $adj = trim((string)($d['adjust_pct'] ?? '')) === '' ? 0.0 : Articles::num((string)$d['adjust_pct']);
        if ($adj <= -100.0 || $adj > 1000.0) {
            return ['ok' => false, 'error' => 'bad_adjust'];
        }
        $f = [
            'name'         => $name,
            'description'  => mb_substr(trim((string)($d['description'] ?? '')), 0, 500) ?: null,
            'price_basis'  => ($d['price_basis'] ?? '') === 'sale4' ? 'sale4' : 'list',
            'adjust_pct'   => round($adj, 2),
            'vat_included' => !empty($d['vat_included']) ? 1 : 0,
            'visible'      => !empty($d['visible']) ? 1 : 0,
        ];
        $pdo = Db::pdo();
        if ($id === null) {
            $pdo->prepare(
                'INSERT INTO price_lists (name, description, price_basis, adjust_pct, vat_included, visible, created_by, updated_by)
                 VALUES (:name, :description, :price_basis, :adjust_pct, :vat_included, :visible, :uid, :uid2)'
            )->execute($f + ['uid' => $userId ?: null, 'uid2' => $userId ?: null]);
            $id = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare(
                'UPDATE price_lists SET name = :name, description = :description, price_basis = :price_basis,
                        adjust_pct = :adjust_pct, vat_included = :vat_included, visible = :visible, updated_by = :uid
                  WHERE id = :id'
            )->execute($f + ['uid' => $userId ?: null, 'id' => $id]);
        }
        Log::write('crm', 'pricelist_saved', 'price_list', $id, ['name' => $name, 'by' => $userId]);
        return ['ok' => true, 'id' => $id];
    }

    /** Remove a list and its selection. The articles themselves are untouched. */
    public static function delete(int $id, ?int $userId): bool
    {
        $l = self::find($id);
        if (!$l) {
            return false;
        }
        $pdo = Db::pdo();
        $pdo->prepare('DELETE FROM price_list_items WHERE list_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM price_lists WHERE id = ?')->execute([$id]);
        Log::write('crm', 'pricelist_deleted', 'price_list', $id, ['name' => $l['name'], 'by' => $userId]);
        return true;
    }

    // ---- reading the catalogue ------------------------------------------------------

    /** The shared FROM/WHERE of a list's catalogue. @return array{0:string, 1:array} */
    private static function itemsSql(int $listId, array $f): array
    {
        $where = ['i.list_id = ?', 'a.archived = 0'];
        $args  = [$listId];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.code LIKE ? OR a.description LIKE ? OR a.barcode LIKE ? OR a.web_description LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like);
        }
        $cat = trim((string)($f['category'] ?? ''));
        if ($cat !== '') {
            $where[] = 'a.category = ?';
            $args[]  = $cat;
        }
        return ['FROM price_list_items i JOIN articles a ON a.id = i.article_id WHERE ' . implode(' AND ', $where), $args];
    }

    /** Columns a catalogue row needs; never the cost price. */
    private const COLS = 'a.id, a.code, a.barcode, a.description, a.web_description, a.info_url, a.category,
                          a.subcategory, a.list_price, a.sale_price4, a.vat_rate, a.stock, a.stock_available,
                          a.stock_ordered, i.price AS pl_price,
                          (SELECT m.id FROM article_media m WHERE m.article_id = a.id AND m.kind = \'photo\'
                            ORDER BY m.sort, m.id LIMIT 1) AS cover_id';

    /** Grouped by category, then by name — the order a printed catalogue reads in. */
    private const ORDER = "ORDER BY (a.category IS NULL OR a.category = ''), a.category, a.description, a.code";

    /**
     * One page of a list's catalogue.
     * @param array $f q | category
     * @return array{rows:array, total:int, page:int, pages:int, per:int}
     */
    public static function items(int $listId, array $f = [], int $page = 1, int $per = self::PER_PAGE): array
    {
        [$from, $args] = self::itemsSql($listId, $f);
        $pdo = Db::pdo();
        $c = $pdo->prepare("SELECT COUNT(*) $from");
        $c->execute($args);
        $total = (int)$c->fetchColumn();
        $per   = max(1, min(200, $per));
        $pages = max(1, (int)ceil($total / $per));
        $page  = max(1, min($page, $pages));
        $off   = ($page - 1) * $per;
        $s = $pdo->prepare('SELECT ' . self::COLS . " $from " . self::ORDER . " LIMIT $per OFFSET $off");
        $s->execute($args);
        return ['rows' => $s->fetchAll() ?: [], 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
    }

    /** Every row the filter finds, for the PDF. */
    public static function allItems(int $listId, array $f = []): array
    {
        [$from, $args] = self::itemsSql($listId, $f);
        $s = Db::pdo()->prepare('SELECT ' . self::COLS . " $from " . self::ORDER);
        $s->execute($args);
        return $s->fetchAll() ?: [];
    }

    /** One product as this list shows it, or null when it is not (or no longer) in the list. */
    public static function item(int $listId, int $articleId): ?array
    {
        $s = Db::pdo()->prepare('SELECT ' . self::COLS . '
              FROM price_list_items i JOIN articles a ON a.id = i.article_id
             WHERE i.list_id = ? AND i.article_id = ? AND a.archived = 0');
        $s->execute([$listId, $articleId]);
        return $s->fetch() ?: null;
    }

    /** @return string[] the categories that occur in this list */
    public static function categories(int $listId): array
    {
        $s = Db::pdo()->prepare(
            "SELECT DISTINCT a.category FROM price_list_items i JOIN articles a ON a.id = i.article_id
              WHERE i.list_id = ? AND a.archived = 0 AND a.category <> '' ORDER BY a.category"
        );
        $s->execute([$listId]);
        return $s->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    // ---- prices -------------------------------------------------------------------

    /**
     * The warehouse price a list starts from. Each basis falls back to the other
     * when its own column is empty, like the quote builder does — LISTINO is
     * blank on 2,326 articles, price list 4 on most of the rest.
     */
    public static function basePrice(array $a, string $basis): float
    {
        $list = (float)($a['list_price'] ?? 0);
        $s4   = (float)($a['sale_price4'] ?? 0);
        return $basis === 'sale4' ? ($s4 > 0 ? $s4 : $list) : ($list > 0 ? $list : $s4);
    }

    /** Net price in this list: the list's own when set, otherwise the adjusted base. */
    public static function netPrice(array $row, array $list): float
    {
        if (isset($row['pl_price']) && $row['pl_price'] !== null) {
            return round((float)$row['pl_price'], 2);
        }
        return round(self::basePrice($row, (string)$list['price_basis']) * (1 + (float)$list['adjust_pct'] / 100), 2);
    }

    public static function vatRate(array $row): float
    {
        return $row['vat_rate'] !== null && $row['vat_rate'] !== '' ? (float)$row['vat_rate'] : 22.0;
    }

    /** The figure the catalogue prints: net, or VAT included when the list says so. 0 = on request. */
    public static function shownPrice(array $row, array $list): float
    {
        $net = self::netPrice($row, $list);
        return (int)$list['vat_included'] === 1 ? round($net * (1 + self::vatRate($row) / 100), 2) : $net;
    }

    /** in_stock | on_order | none — what an agent may promise, not the shelf count. */
    public static function availability(array $row): string
    {
        if ((float)$row['stock_available'] > 0 || (float)$row['stock'] > 0) {
            return 'in_stock';
        }
        return (float)$row['stock_ordered'] > 0 ? 'on_order' : 'none';
    }

    // ---- the flag -----------------------------------------------------------------

    /**
     * The lists an article is in, from its own record: every list with in_list
     * and the list's own price, if any.
     */
    public static function forArticle(int $articleId, bool $visibleOnly = false): array
    {
        $s = Db::pdo()->prepare(
            'SELECT l.*, (i.article_id IS NOT NULL) AS in_list, i.price AS pl_price
               FROM price_lists l
               LEFT JOIN price_list_items i ON i.list_id = l.id AND i.article_id = ?'
            . ($visibleOnly ? ' WHERE l.visible = 1' : '') . ' ORDER BY l.name, l.id'
        );
        $s->execute([$articleId]);
        return $s->fetchAll() ?: [];
    }

    /**
     * Set the flag on one article across every list, from the tick boxes on its
     * record. $on: the list ids ticked; $prices: list id => typed net price
     * (blank = the warehouse price).
     */
    public static function setForArticle(int $articleId, array $on, array $prices, ?int $userId): bool
    {
        if (!Articles::find($articleId)) {
            return false;
        }
        $on  = array_flip(array_map('intval', $on));
        $pdo = Db::pdo();
        $up  = $pdo->prepare(
            'INSERT INTO price_list_items (list_id, article_id, price, added_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE price = VALUES(price)'
        );
        $del = $pdo->prepare('DELETE FROM price_list_items WHERE list_id = ? AND article_id = ?');
        $changes = [];
        foreach (self::all() as $l) {
            $lid = (int)$l['id'];
            if (isset($on[$lid])) {
                $up->execute([$lid, $articleId, self::priceOrNull($prices[$lid] ?? ''), $userId ?: null]);
                $changes[$lid] = 1;
            } else {
                $del->execute([$lid, $articleId]);
                $changes[$lid] = 0;
            }
        }
        Log::write('crm', 'article_lists_set', 'article', $articleId, ['lists' => $changes, 'by' => $userId]);
        return true;
    }

    /**
     * The bulk editor: of the articles that were on screen ($shown), the ticked
     * ones ($on) are in the list — with the price typed next to them — and the
     * rest are not. Rows that were not on screen are left alone.
     *
     * @return array{added:int, removed:int}
     */
    public static function setMembers(int $listId, array $shown, array $on, array $prices, ?int $userId): array
    {
        $out = ['added' => 0, 'removed' => 0];
        if (!self::find($listId)) {
            return $out;
        }
        $shown = array_values(array_unique(array_filter(array_map('intval', $shown))));
        $on    = array_flip(array_map('intval', $on));
        if (!$shown) {
            return $out;
        }
        $pdo = Db::pdo();
        $had = $pdo->prepare('SELECT article_id FROM price_list_items WHERE list_id = ? AND article_id IN ('
            . implode(',', $shown) . ')');
        $had->execute([$listId]);
        $had = array_flip(array_map('intval', $had->fetchAll(\PDO::FETCH_COLUMN) ?: []));

        $up = $pdo->prepare(
            'INSERT INTO price_list_items (list_id, article_id, price, added_by)
             SELECT ?, id, ?, ? FROM articles WHERE id = ?
             ON DUPLICATE KEY UPDATE price = VALUES(price)'
        );
        $del = $pdo->prepare('DELETE FROM price_list_items WHERE list_id = ? AND article_id = ?');
        foreach ($shown as $aid) {
            if (isset($on[$aid])) {
                $up->execute([$listId, self::priceOrNull($prices[$aid] ?? ''), $userId ?: null, $aid]);
                $out['added'] += isset($had[$aid]) ? 0 : 1;
            } elseif (isset($had[$aid])) {
                $del->execute([$listId, $aid]);
                $out['removed']++;
            }
        }
        if ($out['added'] || $out['removed']) {
            Log::write('crm', 'pricelist_items_set', 'price_list', $listId, $out + ['by' => $userId]);
        }
        return $out;
    }

    /**
     * Put in the list everything a warehouse filter finds (a whole category, a
     * supplier). Articles already in it keep their own price.
     */
    public static function addMatching(int $listId, array $filter, ?int $userId): int
    {
        if (!self::find($listId)) {
            return 0;
        }
        // INSERT IGNORE skips what is already in: no membership filter needed,
        // and the one on screen ("only what is in") would make this a no-op.
        unset($filter['pricelist'], $filter['pl_mode']);
        [$w, $args] = Articles::filterSql($filter);
        $s = Db::pdo()->prepare(
            "INSERT IGNORE INTO price_list_items (list_id, article_id, added_by)
             SELECT ?, a.id, ? FROM articles a WHERE $w"
        );
        $s->execute(array_merge([$listId, $userId ?: null], $args));
        $n = $s->rowCount();
        if ($n > 0) {
            Log::write('crm', 'pricelist_items_set', 'price_list', $listId, ['added' => $n, 'removed' => 0, 'bulk' => true, 'by' => $userId]);
        }
        return $n;
    }

    /** Take one article out of a list. */
    public static function removeArticle(int $listId, int $articleId, ?int $userId): bool
    {
        $s = Db::pdo()->prepare('DELETE FROM price_list_items WHERE list_id = ? AND article_id = ?');
        $s->execute([$listId, $articleId]);
        if ($s->rowCount() > 0) {
            Log::write('crm', 'pricelist_items_set', 'price_list', $listId,
                ['added' => 0, 'removed' => 1, 'article' => $articleId, 'by' => $userId]);
            return true;
        }
        return false;
    }

    /** @param int[] $articleIds @return array<int, ?float> article id => the list's own price */
    public static function membership(int $listId, array $articleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds))));
        if (!$ids) {
            return [];
        }
        $s = Db::pdo()->prepare('SELECT article_id, price FROM price_list_items WHERE list_id = ? AND article_id IN ('
            . implode(',', $ids) . ')');
        $s->execute([$listId]);
        $out = [];
        foreach ($s->fetchAll() ?: [] as $r) {
            $out[(int)$r['article_id']] = $r['price'] !== null ? (float)$r['price'] : null;
        }
        return $out;
    }

    private static function priceOrNull($raw): ?float
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        return max(0.0, round(Articles::num($raw), 2));
    }
}
