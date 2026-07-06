<?php
declare(strict_types=1);

/**
 * CLI poller — pings the shop devices via each network area's MikroTik RouterOS
 * API and records up/down status (and disconnection events). Run from cron:
 *
 *   * * * * * php /var/www/html/crm/bin/poll-devices.php >/dev/null 2>&1
 *
 * Prints a short summary so it's easy to run by hand. Exits non-zero if a router
 * was unreachable.
 */

require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Devices\Monitor;

Bootstrap::init();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$res = Monitor::poll();

echo 'Device poll @ ' . date('Y-m-d H:i:s') . "\n";
foreach ($res['results'] as $r) {
    printf(
        "  %-16s %-16s %-6s %s\n",
        $r['name'],
        $r['ip'],
        $r['up'] ? 'UP' : 'DOWN',
        $r['up'] && $r['latency_ms'] !== null ? number_format((float)$r['latency_ms'], 1) . ' ms' : '-'
    );
}
printf("%d checked, %d up, %d down\n", $res['checked'], $res['up'], $res['down']);
if (!$res['ok']) {
    fwrite(STDERR, 'WARN: ' . ($res['error'] ?? 'router issue') . "\n");
}

exit(($res['ok'] && $res['down'] === 0) ? 0 : 1);
