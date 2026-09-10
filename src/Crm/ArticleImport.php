<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;
use RuntimeException;

/**
 * Ingests the gestionale's article export (…-ARTICO.xlsx) into articles.
 *
 * The management software drops a full snapshot of the catalog — about 7,600
 * rows — named like 20260904T175015.827-ARTICO.xlsx. This class streams the
 * file through Xlsx::rows and upserts each row keyed on its "Codice".
 *
 * Why Codice and not the obvious columns: the export's "ID" column is a
 * literal "+" on every single row (a gestionale rendering artifact, not an
 * id), and barcodes are shared between articles — 9 values appear on more
 * than one row, because compatible consumables carry the maker's barcode.
 * Codice is unique across the whole file, so it is the identity.
 *
 * The gestionale owns every column of every row it ships, the file is the whole
 * truth for those, and a re-import converges the entire row.
 *
 * The exception is ownership, added in migration 048 when the warehouse became
 * editable: a row with origin='crm' was created or edited in the CRM, and the
 * import must leave it completely alone — not converge its columns, not prune
 * it. Without that, a product typed into the CRM would be reverted or deleted
 * by the next snapshot within fifteen minutes, without a word to anybody.
 *
 * Known faults in the export, handled rather than fixed: barcodes arrive with
 * trailing spaces (trimmed); 8 rows have no description (kept — the code is
 * the identity); one list price is a fat-fingered ~1.7 billion (stored as
 * exported; the registry mirrors the file); 465 rows carry negative stock
 * (the gestionale's own bookkeeping, stored as-is).
 *
 * Every file is hashed into article_imports; a hash already seen is skipped,
 * so the FTP drop directory can be rescanned by cron forever.
 */
final class ArticleImport
{
    /**
     * Header spellings in the export, mapped to our column names. "ID" is
     * deliberately absent (see above). The empty label is the export's
     * unnamed numeric column — the gestionale's internal id, kept for
     * reference only.
     */
    private const HEADERS = [
        ''                  => 'gest_num',
        'Codice'            => 'code',
        'Barcode'           => 'barcode',
        'Descrizione'       => 'description',
        'LISTINO'           => 'list_price',
        'Prezzo Acq.'       => 'cost_price',
        'Prezzo Ven. 4'     => 'sale_price4',
        'Prezzo Ivato 4'    => 'sale_price4_gross',
        'IVA'               => 'vat_rate',
        'Ubicazione'        => 'location',
        'Classe Merc.'      => 'category',
        'Sotto classe'      => 'subcategory',
        'Codice Gruppo'     => 'group_code',
        'ID Fornitore'      => 'supplier_gest_id',
        'Fornitore'         => 'supplier',
        'Cod. Fornitore 1'  => 'supplier_code1',
        'Cod. Fornitore 2'  => 'supplier_code2',
        'Giacenza Iniziale' => 'stock_initial',
        'Esistenza'         => 'stock',
        'Ordinato'          => 'stock_ordered',
        'Disponibile'       => 'stock_available',
        'Matricole'         => 'has_serials',
        'Immagine'          => 'has_image',
        'Ult. Data Mov.'    => 'last_movement',
    ];

    /**
     * Import one export file.
     *
     * @return array{file:string, sha256:string, total:int, created:int, updated:int,
     *               skipped:int, pruned:int, already:bool, dry_run:bool}
     */
    public static function run(string $path, ?int $userId = null, bool $dryRun = false, bool $force = false, bool $prune = false): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("No such file: $path");
        }
        $sha = hash_file('sha256', $path);
        $out = [
            'file' => basename($path), 'sha256' => $sha, 'total' => 0,
            'created' => 0, 'updated' => 0, 'skipped' => 0, 'pruned' => 0,
            'already' => false, 'dry_run' => $dryRun,
        ];

        $pdo = Db::pdo();
        if (!$force) {
            $q = $pdo->prepare('SELECT id FROM article_imports WHERE sha256 = ?');
            $q->execute([$sha]);
            if ($q->fetchColumn()) {
                $out['already'] = true;
                return $out;
            }
        }

        // The whole existing key set fits comfortably in memory (7,600 codes)
        // and lets a dry run report created vs updated without touching rows.
        $existing = array_flip($pdo->query('SELECT code FROM articles')->fetchAll(\PDO::FETCH_COLUMN));

        $rows = Xlsx::rows($path); // a generator: rows stream, nothing is held
        $map  = null;

        // One transaction for the whole file, same reasoning as CustomerImport:
        // thousands of autocommitted upserts are thousands of disk flushes, and
        // half an import helps nobody.
        if (!$dryRun) {
            $pdo->beginTransaction();
        }

        $cols = [
            'code', 'gest_num', 'barcode', 'description',
            'list_price', 'cost_price', 'sale_price4', 'sale_price4_gross', 'vat_rate',
            'location', 'category', 'subcategory', 'group_code',
            'supplier_gest_id', 'supplier', 'supplier_code1', 'supplier_code2',
            'stock_initial', 'stock', 'stock_ordered', 'stock_available',
            'has_serials', 'has_image', 'last_movement',
        ];
        // Every column converges from the file — UNLESS the CRM owns the row.
        // A product added or edited here carries origin='crm', and the whole
        // point of that flag is that the next snapshot must not quietly undo
        // somebody's work. IF(origin='crm', <keep>, VALUES(<take>)) does it in
        // one statement, so the upsert stays a single round trip per row.
        // ...and the stock columns have an owner of their own (migration 049):
        // a quantity corrected in the CRM must survive even on a product whose
        // prices and description still come from the gestionale.
        $stockCols = ['stock_initial', 'stock', 'stock_ordered', 'stock_available'];
        $update = implode(', ', array_map(
            static fn(string $c) => in_array($c, $stockCols, true)
                ? "$c = IF(articles.origin = 'crm' OR articles.stock_owner = 'crm', articles.$c, VALUES($c))"
                : "$c = IF(articles.origin = 'crm', articles.$c, VALUES($c))",
            array_slice($cols, 1) // everything but the key
        ));
        $upsert = $pdo->prepare(
            'INSERT INTO articles (' . implode(', ', $cols) . ')
             VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')
             ON DUPLICATE KEY UPDATE ' . $update
        );

        $seenCodes = [];
        try {
            foreach ($rows as $r) {
                if ($map === null) { // first row is the header
                    $map = self::mapHeaders($r);
                    if (!isset($map['code'])) {
                        throw new RuntimeException('Column "Codice" not found — is this really the ARTICO export?');
                    }
                    continue;
                }
                $a = self::rowToFields($r, $map);
                if ($a === null) {
                    $out['skipped']++;
                    continue;
                }
                $out['total']++;
                $seenCodes[$a['code']] = true;
                $isNew = !isset($existing[$a['code']]);
                $isNew ? $out['created']++ : $out['updated']++;

                if (!$dryRun) {
                    $upsert->execute(array_map(static fn(string $c) => $a[$c], $cols));
                }
            }

            if ($map === null) {
                throw new RuntimeException('The file has no rows at all.');
            }
            if ($out['total'] === 0) {
                // An empty snapshot is a broken export, not "the catalog is
                // gone" — refuse before prune can act on it.
                throw new RuntimeException('The file has a header but no usable data rows.');
            }
            if ($prune) {
                // The file is a full snapshot: a code it no longer carries is an
                // article the gestionale deleted. CRM-owned products are not in
                // the file BY DEFINITION — they were never in it — so prune must
                // never touch them, or adding a product here would be a way of
                // scheduling its own deletion. (When deal lines or report lines
                // start pointing at articles, add a kept-because-linked guard
                // here too, like CustomerImport::prune has.)
                $gone = array_diff_key($existing, $seenCodes);
                $out['pruned'] = 0;
                if ($gone) {
                    $del = $pdo->prepare("DELETE FROM articles WHERE code = ? AND origin = 'gestionale'");
                    foreach (array_keys($gone) as $code) {
                        if ($dryRun) {
                            $chk = $pdo->prepare("SELECT 1 FROM articles WHERE code = ? AND origin = 'gestionale'");
                            $chk->execute([$code]);
                            $out['pruned'] += $chk->fetchColumn() ? 1 : 0;
                            continue;
                        }
                        $del->execute([$code]);
                        $out['pruned'] += $del->rowCount();
                    }
                }
            }
        } catch (\Throwable $e) {
            if (!$dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if (!$dryRun) {
            // Upsert, not insert: a --force re-run of a file already in the
            // ledger refreshes its row instead of dying on the unique hash.
            $pdo->prepare(
                'INSERT INTO article_imports (filename, sha256, rows_total, created_n, updated_n, skipped_n, imported_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE rows_total = VALUES(rows_total), created_n = VALUES(created_n),
                     updated_n = VALUES(updated_n), skipped_n = VALUES(skipped_n),
                     imported_by = VALUES(imported_by), imported_at = NOW()'
            )->execute([basename($path), $sha, $out['total'], $out['created'], $out['updated'], $out['skipped'], $userId]);
            $pdo->commit();
            Log::write('crm', 'articles_imported', null, null, $out);
            // Stock just moved for up to every article in the catalogue, so this
            // is the moment something may have crossed its restock threshold.
            // Never allowed to fail the import that has already committed.
            try {
                $out['low_alerted'] = Articles::checkLowStock();
            } catch (\Throwable $e) {
                Log::write('crm', 'low_stock_check_failed', null, null, ['error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /** Normalise one sheet row into the columns we store; null = not importable. */
    private static function rowToFields(array $r, array $map): ?array
    {
        $get = static fn(string $k): string => trim((string)($r[$map[$k] ?? -1] ?? ''));

        $code = $get('code');
        if ($code === '') {
            return null; // no identity, nothing to hang the row on
        }

        $num = static fn(string $k): float => (float)str_replace(',', '.', $get($k) ?: '0');
        $str = static function (string $k, int $max) use ($get): ?string {
            $v = $get($k);
            return $v !== '' ? mb_substr($v, 0, $max) : null;
        };

        $supplierId = (int)$num('supplier_gest_id');

        return [
            'code'              => mb_substr($code, 0, 64),
            'gest_num'          => $get('gest_num') !== '' ? (int)$num('gest_num') : null,
            'barcode'           => $str('barcode', 64),
            'description'       => $str('description', 190),
            'list_price'        => $num('list_price'),
            'cost_price'        => $num('cost_price'),
            'sale_price4'       => $get('sale_price4') !== '' ? $num('sale_price4') : null,
            'sale_price4_gross' => $get('sale_price4_gross') !== '' ? $num('sale_price4_gross') : null,
            'vat_rate'          => $get('vat_rate') !== '' ? $num('vat_rate') : null,
            'location'          => $str('location', 64),
            'category'          => $str('category', 120),
            'subcategory'       => $str('subcategory', 120),
            'group_code'        => $str('group_code', 32),
            'supplier_gest_id'  => $supplierId > 0 ? $supplierId : null, // 0 = none in the export
            'supplier'          => $str('supplier', 190),
            'supplier_code1'    => $str('supplier_code1', 64),
            'supplier_code2'    => $str('supplier_code2', 64),
            'stock_initial'     => $num('stock_initial'),
            'stock'             => $num('stock'),
            'stock_ordered'     => $num('stock_ordered'),
            'stock_available'   => $num('stock_available'),
            'has_serials'       => in_array(strtolower($get('has_serials')), ['vero', 'true', '1'], true) ? 1 : 0,
            'has_image'         => $get('has_image') !== '' ? 1 : 0, // the column says "BLOB" when an image exists
            'last_movement'     => Xlsx::date($get('last_movement')),
        ];
    }

    /** @return array<string,int> our field name -> column index */
    private static function mapHeaders(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $idx => $label) {
            $label = trim((string)$label);
            if (isset(self::HEADERS[$label])) {
                $map[self::HEADERS[$label]] = (int)$idx;
            }
        }
        return $map;
    }
}
