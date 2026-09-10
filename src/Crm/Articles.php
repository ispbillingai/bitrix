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
 * STOCK has an owner of its own (migration 049). Correcting a quantity here sets
 * stock_owner='crm' for that row alone: the import keeps refreshing its prices
 * and stops overwriting its stock. Every change goes into article_movements.
 * A reorder threshold can be set on any product and never takes it over.
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
            'low'      => 'a.reorder_threshold IS NOT NULL AND a.stock <= a.reorder_threshold',
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
                    SUM(origin = \'crm\') AS crm,
                    SUM(reorder_threshold IS NOT NULL AND stock <= reorder_threshold) AS low
               FROM articles WHERE archived = 0'
        )->fetch();
        $out = array_map('intval', $r ?: ['total' => 0, 'in_stock' => 0, 'negative' => 0,
                                          'ordered' => 0, 'serials' => 0, 'crm' => 0, 'low' => 0]);
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

        // "Adding a product must take current stock levels into account": the
        // quantity on hand is part of creating it, recorded as the first
        // movement so the ledger starts where the shelf does.
        if (trim((string)($d['reorder_threshold'] ?? '')) !== '') {
            self::setThreshold($id, (string)$d['reorder_threshold'], $userId);
        }
        if (trim((string)($d['stock'] ?? '')) !== '') {
            self::moveStock($id, 'set', (string)$d['stock'], 'Giacenza iniziale', $userId, 'initial');
        }
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

    // ---- stock ----------------------------------------------------------------

    /**
     * Change what is on the shelf. $mode is 'set' (the quantity counted),
     * 'load' (add) or 'unload' (take away); $qty is what was typed.
     *
     * Every change is written to article_movements with the resulting figure,
     * and takes the STOCK over for the CRM (stock_owner='crm') without touching
     * who owns the prices — the import keeps refreshing those and stops
     * overwriting the quantity. stock_available moves by the same amount, so
     * whatever the gestionale had reserved against it stays reserved.
     *
     * @return array{ok:bool, stock?:float, error?:string}
     */
    public static function moveStock(int $id, string $mode, string $qty, string $note = '',
                                     ?int $userId = null, ?string $reason = null): array
    {
        $a = self::find($id);
        if (!$a) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (trim($qty) === '') {
            return ['ok' => false, 'error' => 'no_qty'];
        }
        $n   = self::num($qty);
        $cur = (float)$a['stock'];
        [$new, $why] = match ($mode) {
            'load'   => [$cur + abs($n), 'load'],
            'unload' => [$cur - abs($n), 'unload'],
            default  => [$n, 'correction'],
        };
        $delta = round($new - $cur, 2);
        if ($delta == 0.0 && $reason !== 'initial') {
            return ['ok' => true, 'stock' => $cur];   // nothing moved, nothing to record
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE articles SET stock = ?, stock_available = stock_available + ?,
                        stock_owner = 'crm', updated_by = ?, crm_edited_at = NOW() WHERE id = ?"
            )->execute([$new, $delta, $userId ?: null, $id]);
            $pdo->prepare(
                'INSERT INTO article_movements (article_id, delta, stock_after, reason, note, user_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$id, $delta, $new, $reason ?? $why, mb_substr(trim($note), 0, 255) ?: null, $userId ?: null]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Log::write('crm', 'article_stock_moved', 'article', $id,
            ['delta' => $delta, 'stock' => $new, 'reason' => $reason ?? $why, 'by' => $userId]);
        self::checkLowStock([$id]);
        return ['ok' => true, 'stock' => $new];
    }

    /**
     * Hand a product's stock back to the gestionale. The next import converges
     * the quantity from the file again; the CRM's movements stay on record.
     */
    public static function releaseStock(int $id, ?int $userId = null): bool
    {
        $a = self::find($id);
        if (!$a || (string)$a['origin'] === 'crm') {
            return false;   // a CRM product has no gestionale stock to go back to
        }
        Db::pdo()->prepare("UPDATE articles SET stock_owner = 'gestionale', updated_by = ? WHERE id = ?")
            ->execute([$userId ?: null, $id]);
        Log::write('crm', 'article_stock_released', 'article', $id, ['by' => $userId]);
        return true;
    }

    /**
     * The minimum level below which the office wants to be told to reorder.
     * Blank clears it. Works on EVERY product without taking it over: the
     * threshold is our reordering policy, not the export's data, and it is not
     * in the importer's column list, so no snapshot can clear it.
     */
    public static function setThreshold(int $id, string $raw, ?int $userId = null): bool
    {
        if (!self::find($id)) {
            return false;
        }
        $v = trim($raw) === '' ? null : max(0.0, self::num($raw));
        // A new threshold earns a fresh alert, so the old "already told" stamp goes.
        Db::pdo()->prepare('UPDATE articles SET reorder_threshold = ?, low_alert_at = NULL WHERE id = ?')
            ->execute([$v, $id]);
        Log::write('crm', 'article_threshold_set', 'article', $id, ['threshold' => $v, 'by' => $userId]);
        self::checkLowStock([$id]);
        return true;
    }

    /** The CRM's stock changes on one product, newest first. */
    public static function movements(int $id, int $limit = 20): array
    {
        $s = Db::pdo()->prepare(
            'SELECT m.*, COALESCE(NULLIF(u.full_name, ""), u.username) AS user_name
               FROM article_movements m LEFT JOIN users u ON u.id = m.user_id
              WHERE m.article_id = ? ORDER BY m.id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $s->execute([$id]);
        return $s->fetchAll() ?: [];
    }

    /**
     * Tell the office what needs reordering.
     *
     * Alerts on CROSSING the threshold, not on being under it. Stock is
     * refreshed every fifteen minutes, so "below threshold" stays true for as
     * long as nobody reorders — alerting on the state would page the office 96
     * times a day for every such article. low_alert_at marks "already told";
     * an article that climbs back above its threshold is unmarked, so the next
     * dip alerts again.
     *
     * One DIGEST per run rather than one message per article: an import can
     * push dozens across at once, and thirty WhatsApps in a row is noise.
     *
     * Goes to admin users only — reordering is the office's job.
     *
     * @param int[]|null $ids limit the check to these articles (a CRM movement);
     *                        null checks the whole catalogue (after an import)
     * @return int how many articles were newly alerted
     */
    public static function checkLowStock(?array $ids = null): int
    {
        $pdo   = Db::pdo();
        $scope = $ids ? ' AND id IN (' . implode(',', array_map('intval', $ids)) . ')' : '';

        // Recovered: above the threshold again, or the threshold was cleared.
        $pdo->exec(
            "UPDATE articles SET low_alert_at = NULL
              WHERE low_alert_at IS NOT NULL
                AND (reorder_threshold IS NULL OR stock > reorder_threshold)$scope"
        );

        $rows = $pdo->query(
            "SELECT id, code, description, stock, reorder_threshold, supplier
               FROM articles
              WHERE archived = 0 AND reorder_threshold IS NOT NULL
                AND stock <= reorder_threshold AND low_alert_at IS NULL$scope
              ORDER BY (stock - reorder_threshold) ASC, code ASC"
        )->fetchAll() ?: [];
        if (!$rows) {
            return 0;
        }

        $fmt = static fn($n): string => rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',');
        $brand = (string)\Glue\Config::get('app.company_name', 'CRM');
        $link  = \Glue\Config::appBaseUrl() . '/dashboard.php?tab=articles&state=low';

        $lines = [];
        $html  = [];
        foreach (array_slice($rows, 0, 25) as $r) {
            $lines[] = '• ' . $r['code'] . ' — ' . mb_substr((string)$r['description'], 0, 48)
                     . ': ' . $fmt($r['stock']) . ' (min ' . $fmt($r['reorder_threshold']) . ')';
            $html[]  = '<li><strong>' . htmlspecialchars((string)$r['code'], ENT_QUOTES) . '</strong> — '
                     . htmlspecialchars((string)$r['description'], ENT_QUOTES)
                     . ': <strong>' . $fmt($r['stock']) . '</strong> (min ' . $fmt($r['reorder_threshold']) . ')'
                     . ($r['supplier'] ? ' · ' . htmlspecialchars((string)$r['supplier'], ENT_QUOTES) : '')
                     . '</li>';
        }
        $more = count($rows) > 25 ? "\n…e altri " . (count($rows) - 25) : '';

        $text = "📦 $brand — da riordinare (" . count($rows) . ")\n" . implode("\n", $lines) . $more
              . "\n\nMagazzino: $link";
        $body = '<p>📦 <strong>Prodotti sotto la scorta minima</strong> (' . count($rows) . ')</p><ul>'
              . implode('', $html) . '</ul>'
              . (count($rows) > 25 ? '<p>…e altri ' . (count($rows) - 25) . '.</p>' : '')
              . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Apri il magazzino</a></p>';

        $stmt = $pdo->prepare("SELECT phone, email FROM users WHERE role = 'admin' AND active = 1");
        $stmt->execute();
        $n = new \Glue\Notify\Notifier();
        foreach ($stmt->fetchAll() as $u) {
            if (trim((string)($u['phone'] ?? '')) !== '') {
                $n->whatsapp((string)$u['phone'], $text);
            }
            if (trim((string)($u['email'] ?? '')) !== '') {
                $n->email((string)$u['email'], "Da riordinare — " . count($rows) . ' prodotti', $body);
            }
        }

        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        $pdo->exec('UPDATE articles SET low_alert_at = NOW() WHERE id IN (' . implode(',', $ids) . ')');
        Log::write('crm', 'low_stock_alerted', null, null, ['count' => count($rows), 'ids' => $ids]);
        return count($rows);
    }

    /** The last file the cron took, so the page can say how fresh it is. */
    public static function lastImport(): ?array
    {
        $r = Db::pdo()->query('SELECT * FROM article_imports ORDER BY id DESC LIMIT 1')->fetch();
        return $r ?: null;
    }
}
