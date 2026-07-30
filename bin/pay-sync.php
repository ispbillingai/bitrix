<?php
declare(strict_types=1);

/**
 * Manual SmallPay refresh. The scheduler already does this on its own cadence
 * (smallpay.sync_minutes); this is for setting the integration up and for
 * debugging, where you want the read to happen now and to print what came back.
 *
 *   php bin/pay-sync.php --check      # validate the credentials, create nothing
 *   php bin/pay-sync.php              # refresh every live contract
 *   php bin/pay-sync.php --id=12      # refresh one, and show its rates
 *
 * --check is the safe first move on a live merchant account: it calls
 * checkSellConfigs (§3.3), which validates merchant + service + gateway without
 * filing a position or touching anybody's card.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Pay\Contracts;
use Glue\Pay\SmallPay;
use Glue\Settings;

Bootstrap::init();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$opts = getopt('', ['id::', 'check']);

try {
    if (!SmallPay::configured()) {
        fwrite(STDERR, "SmallPay is not configured — set id_merchant, unique_id, service_id and domain (Settings → SmallPay)\n");
        exit(1);
    }

    $api = new SmallPay();
    if ($api->isStaging()) {
        fwrite(STDOUT, "environment: STAGING (no real card is charged)\n");
    }

    if (isset($opts['check'])) {
        $api->checkSellConfig();
        fwrite(STDOUT, "configuration accepted by SmallPay — merchant, service and gateway are set up\n");
        exit(0);
    }

    if (isset($opts['id'])) {
        $c = Contracts::sync((int)$opts['id']);
        printf("#%d %s — %s · %s · %s\n",
            (int)$c['id'], $c['reference'], $c['customer_name'] ?? '?', $c['description'], $c['status']);
        foreach (Contracts::charges((int)$c['id']) as $r) {
            printf("  %2d  %-10s  %10s  %-12s %s\n",
                (int)$r['seq'],
                $r['due_date'] ?? '—',
                number_format(((int)$r['amount_cents']) / 100, 2, ',', '.'),
                $r['status'],
                $r['paid_date'] ? 'paid ' . $r['paid_date'] . ((int)$r['paid_in_cash'] ? ' (cash)' : '') : ''
            );
        }
        exit(0);
    }

    // A human running this by hand wants an answer now, not "not due yet".
    Settings::set('smallpay.last_sync_at', null);
    $t0 = microtime(true);
    $s = Contracts::syncIfDue() ?? ['checked' => 0, 'changed' => 0, 'errors' => 0];
    printf("checked %d contracts in %.1fs — changed %d, errors %d\n",
        $s['checked'], microtime(true) - $t0, $s['changed'], $s['errors']);
    exit($s['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'smallpay error: ' . $e->getMessage() . "\n");
    exit(1);
}
