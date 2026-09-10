<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;
use PDO;

/**
 * The warehouse — reads over the article registry the gestionale's ARTICO export
 * fills (see migration 046 and ArticleImport).
 *
 * Two kinds of row live here, and the difference is the whole design:
 *
 *   origin='gestionale'  shipped by the ARTICO export. The cron re-imports a
 *                        full snapshot every fifteen minutes and converges
 *                        every column, so the file is the truth for these.
 *   origin='crm'         added or edited here. The import leaves them entirely
 *                        alone and prune never deletes them.
 *
 * Editing a gestionale article DETACHES it — origin flips to 'crm' — because an
 * edit the next snapshot silently reverts is worse than refusing the edit. The
 * caller is expected to have said so on screen first.
 *
 * Deleting works the same way round: a CRM article is deleted for real, while a
 * gestionale one is ARCHIVED, because its code is still in the file and a hard
 * delete would simply be undone at the next import.
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
        // Archived articles are hidden everywhere except their own filter: they
        // are the gestionale rows somebody "deleted", and the import keeps
        // refreshing them underneath without putting them back on screen.
        $where = [((string)($f['state'] ?? 'all') === 'archived') ? 'a.archived = 1' : 'a.archived = 0'];
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
            'crm'      => "a.origin = 'crm'",
            'archived' => '1=1',   // the archived flag above is the filter
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
                    SUM(has_serials = 1) AS serials,
                    SUM(origin = \'crm\') AS crm
               FROM articles WHERE archived = 0'
        )->fetch();
        $out = array_map('intval', $r ?: ['total' => 0, 'in_stock' => 0, 'negative' => 0,
                                          'ordered' => 0, 'serials' => 0, 'crm' => 0]);
        $out['archived'] = (int)Db::pdo()->query('SELECT COUNT(*) FROM articles WHERE archived = 1')->fetchColumn();
        return $out;
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
               FROM articles WHERE archived = 0'
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

    // ---- writing ---------------------------------------------------------------

    /** The columns a person may set. Stock is deliberately not among them. */
    private const EDITABLE = [
        'code', 'barcode', 'description', 'list_price', 'cost_price',
        'sale_price4', 'sale_price4_gross', 'vat_rate', 'location',
        'category', 'subcategory', 'supplier', 'supplier_code1', 'supplier_code2',
        'has_serials',
    ];

    /** "12,50" / "12.50" / " 1.234,56 " -> 1234.56. Italian typing, mostly. */
    private static function num(string $raw): float
    {
        $v = trim($raw);
        if ($v === '') {
            return 0.0;
        }
        $v = (string)preg_replace('/[^0-9,.\-]/', '', $v);
        $lastComma = strrpos($v, ',');
        $lastDot   = strrpos($v, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $v = str_replace('.', '', $v);      // 1.234,56 -> 1234,56
            $v = str_replace(',', '.', $v);
        } else {
            $v = str_replace(',', '', $v);      // 1,234.56 -> 1234.56
        }
        return (float)$v;
    }

    /**
     * Add a product the gestionale does not ship. origin='crm', so the import
     * neither converges nor prunes it.
     *
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function create(array $d, ?int $userId = null): array
    {
        $code = trim((string)($d['code'] ?? ''));
        if ($code === '') {
            return ['ok' => false, 'error' => 'no_code'];
        }
        if (trim((string)($d['description'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'no_description'];
        }
        // code is the identity across the whole registry, gestionale rows
        // included — a clash would be silently swallowed by the import's upsert.
        $dup = Db::pdo()->prepare('SELECT id FROM articles WHERE code = ?');
        $dup->execute([$code]);
        if ($dup->fetchColumn()) {
            return ['ok' => false, 'error' => 'code_taken'];
        }

        $f = self::fields($d);
        $f['code'] = $code;
        $cols = array_keys($f);
        Db::pdo()->prepare(
            'INSERT INTO articles (' . implode(', ', $cols) . ", origin, updated_by, crm_edited_at)
             VALUES (:" . implode(', :', $cols) . ", 'crm', :uid, NOW())"
        )->execute($f + ['uid' => $userId ?: null]);
        $id = (int)Db::pdo()->lastInsertId();

        Log::write('crm', 'article_created', 'article', $id, ['code' => $code, 'by' => $userId]);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Edit an article. A gestionale row is DETACHED by the edit (origin becomes
     * 'crm'), which is the only way the change survives the next snapshot.
     *
     * @return array{ok:bool, detached?:bool, error?:string}
     */
    public static function update(int $id, array $d, ?int $userId = null): array
    {
        $a = self::find($id);
        if (!$a) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $code = trim((string)($d['code'] ?? ''));
        if ($code === '') {
            return ['ok' => false, 'error' => 'no_code'];
        }
        if (trim((string)($d['description'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'no_description'];
        }
        if ($code !== (string)$a['code']) {
            $dup = Db::pdo()->prepare('SELECT id FROM articles WHERE code = ? AND id <> ?');
            $dup->execute([$code, $id]);
            if ($dup->fetchColumn()) {
                return ['ok' => false, 'error' => 'code_taken'];
            }
        }

        $f = self::fields($d);
        $f['code'] = $code;
        $set = implode(', ', array_map(static fn(string $c) => "$c = :$c", array_keys($f)));
        Db::pdo()->prepare(
            "UPDATE articles SET $set, origin = 'crm', updated_by = :uid, crm_edited_at = NOW() WHERE id = :id"
        )->execute($f + ['uid' => $userId ?: null, 'id' => $id]);

        $detached = (string)$a['origin'] === 'gestionale';
        Log::write('crm', 'article_updated', 'article', $id,
            ['code' => $code, 'detached' => $detached, 'by' => $userId]);
        return ['ok' => true, 'detached' => $detached];
    }

    /**
     * Remove a product. A CRM one goes for real; a gestionale one is archived,
     * because its code is still in the export and a delete would be undone at
     * the next import.
     *
     * @return array{ok:bool, archived?:bool, error?:string}
     */
    public static function delete(int $id, ?int $userId = null): array
    {
        $a = self::find($id);
        if (!$a) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ((string)$a['origin'] === 'crm') {
            Db::pdo()->prepare('DELETE FROM articles WHERE id = ?')->execute([$id]);
            Log::write('crm', 'article_deleted', 'article', $id, ['code' => $a['code'], 'by' => $userId]);
            return ['ok' => true, 'archived' => false];
        }
        Db::pdo()->prepare('UPDATE articles SET archived = 1, updated_by = ?, crm_edited_at = NOW() WHERE id = ?')
            ->execute([$userId ?: null, $id]);
        Log::write('crm', 'article_archived', 'article', $id, ['code' => $a['code'], 'by' => $userId]);
        return ['ok' => true, 'archived' => true];
    }

    /** Put an archived article back on the shelves. */
    public static function restore(int $id, ?int $userId = null): bool
    {
        $a = self::find($id);
        if (!$a) {
            return false;
        }
        Db::pdo()->prepare('UPDATE articles SET archived = 0, updated_by = ?, crm_edited_at = NOW() WHERE id = ?')
            ->execute([$userId ?: null, $id]);
        Log::write('crm', 'article_restored', 'article', $id, ['code' => $a['code'], 'by' => $userId]);
        return true;
    }

    /** The posted form, cleaned into columns. Stock is never taken from a form. */
    private static function fields(array $d): array
    {
        return [
            'barcode'           => trim((string)($d['barcode'] ?? '')) ?: null,
            'description'       => mb_substr(trim((string)($d['description'] ?? '')), 0, 190),
            'list_price'        => self::num((string)($d['list_price'] ?? '')),
            'cost_price'        => self::num((string)($d['cost_price'] ?? '')),
            'sale_price4'       => trim((string)($d['sale_price4'] ?? '')) !== ''
                                    ? self::num((string)$d['sale_price4']) : null,
            'sale_price4_gross' => trim((string)($d['sale_price4_gross'] ?? '')) !== ''
                                    ? self::num((string)$d['sale_price4_gross']) : null,
            'vat_rate'          => trim((string)($d['vat_rate'] ?? '')) !== ''
                                    ? self::num((string)$d['vat_rate']) : null,
            'location'          => trim((string)($d['location'] ?? '')) ?: null,
            'category'          => trim((string)($d['category'] ?? '')) ?: null,
            'subcategory'       => trim((string)($d['subcategory'] ?? '')) ?: null,
            'supplier'          => trim((string)($d['supplier'] ?? '')) ?: null,
            'supplier_code1'    => trim((string)($d['supplier_code1'] ?? '')) ?: null,
            'supplier_code2'    => trim((string)($d['supplier_code2'] ?? '')) ?: null,
            'has_serials'       => !empty($d['has_serials']) ? 1 : 0,
        ];
    }

    /** The last file the cron took, so the page can say how fresh it is. */
    public static function lastImport(): ?array
    {
        $r = Db::pdo()->query('SELECT * FROM article_imports ORDER BY id DESC LIMIT 1')->fetch();
        return $r ?: null;
    }
}
