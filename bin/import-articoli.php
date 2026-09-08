<?php
declare(strict_types=1);

/**
 * Import the gestionale's article export (…-ARTICO.xlsx) into the CRM.
 *
 *   php bin/import-articoli.php file.xlsx            # import this file
 *   php bin/import-articoli.php file.xlsx --dry-run  # parse + report, write nothing
 *   php bin/import-articoli.php file.xlsx --force    # re-import a file already taken
 *   php bin/import-articoli.php file.xlsx --prune    # ...and delete articles absent from it
 *   php bin/import-articoli.php                      # scan the FTP drop directory
 *
 * With no path it scans customers.import_dir (default <root>/storage/import) —
 * the same drop directory the CLIENTI export lands in — for *-ARTICO*.xlsx
 * files and imports every one whose content hash is not yet in article_imports.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\ArticleImport;

Bootstrap::init();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

// Not getopt(): it stops parsing at the first non-option, so a flag placed
// after the file path (the documented usage) would be silently ignored.
$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$force  = in_array('--force', $args, true);
// --prune: the file is the whole truth — articles whose code is no longer in
// it are deleted. Opt-in per run, never the cron default: a partial file must
// not mass-delete the catalog.
$prune  = in_array('--prune', $args, true);
$rest   = array_values(array_filter($args, static fn($a) => !str_starts_with($a, '--')));
$path   = $rest[0] ?? null;

$files = [];
if ($path !== null) {
    $files = [$path];
} else {
    $dir = (string)Config::get('customers.import_dir', dirname(__DIR__) . '/storage/import');
    if (!is_dir($dir)) {
        fwrite(STDERR, "Import directory does not exist: $dir\n");
        fwrite(STDERR, "Create it (the gestionale's FTP drop target) or pass a file path.\n");
        exit(1);
    }
    $files = glob($dir . '/*-ARTICO*.xlsx') ?: [];
    usort($files, static fn($a, $b) => filemtime($a) <=> filemtime($b)); // oldest first: replay in order
    if (!$files) {
        fwrite(STDOUT, "Nothing to import in $dir\n");
        exit(0);
    }
}

foreach ($files as $f) {
    try {
        $r = ArticleImport::run($f, null, $dryRun, $force, $prune);
    } catch (Throwable $e) {
        fwrite(STDERR, basename($f) . ": " . $e->getMessage() . "\n");
        exit(1);
    }
    if ($r['already']) {
        fwrite(STDOUT, "{$r['file']}: already imported (same content), skipped\n");
        continue;
    }
    $mode = $dryRun ? ' [DRY RUN — nothing written]' : '';
    fwrite(STDOUT, "{$r['file']}: {$r['total']} rows -> {$r['created']} created, {$r['updated']} updated, {$r['skipped']} unusable$mode\n");
    if ($prune) {
        fwrite(STDOUT, "prune: {$r['pruned']} articles no longer in the file deleted$mode\n");
    }
}
