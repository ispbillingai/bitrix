<?php
declare(strict_types=1);

/**
 * Manual Sibill invoice sync. The scheduler already does this on its own cadence
 * (sibill.sync_minutes); this is for the first import and for debugging, where
 * you want the walk to run now and print what it found.
 *
 *   php bin/sibill-sync.php              # full walk
 *   php bin/sibill-sync.php --months=12  # only invoices dated in the last year
 *   php bin/sibill-sync.php --relink     # just re-match invoices to CRM records
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Sibill\Client;
use Glue\Sibill\Invoices;

Bootstrap::init();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$opts   = getopt('', ['months::', 'relink']);
$months = isset($opts['months']) ? (int)$opts['months'] : 0;

try {
    if (isset($opts['relink'])) {
        $n = Invoices::relink();
        fwrite(STDOUT, "relinked: $n invoices now point at a CRM contact\n");
        exit(0);
    }

    if (!Client::configured()) {
        fwrite(STDERR, "Sibill is not configured — set sibill.api_key and sibill.company_id (Settings → Sibill)\n");
        exit(1);
    }

    $t0 = microtime(true);
    $s  = Invoices::sync($months > 0 ? ['months' => $months] : []);
    printf(
        "synced %d invoices (%d flows) in %.1fs — paid %d, partial %d, unpaid %d, pruned %d\n",
        $s['invoices'], $s['flows'], microtime(true) - $t0,
        $s['paid'], $s['partial'], $s['unpaid'], $s['pruned']
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'sibill sync error: ' . $e->getMessage() . "\n");
    exit(1);
}
