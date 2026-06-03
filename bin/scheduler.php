<?php
declare(strict_types=1);

/**
 * Cron runner. Dispatches due reminders and sends one campaign batch.
 * Run every minute:
 *   * * * * * php /var/www/html/bitrix-glue/bin/scheduler.php >> /var/log/glue.log 2>&1
 *
 * Single-instance guard via flock so a slow WhatsApp batch never overlaps the
 * next minute's run.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Campaign\Sender;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;

Bootstrap::init();

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$lock = fopen(sys_get_temp_dir() . '/bitrix_glue_scheduler.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[" . date('c') . "] already running, skipping\n");
    exit(0);
}

try {
    $reminders = (new Scheduler())->runDue();
    $campaigns = (new Sender())->runBatch();

    Log::write('scheduler', 'tick', null, null, [
        'reminders' => $reminders,
        'campaigns' => $campaigns,
    ]);
    fwrite(STDOUT, "[" . date('c') . "] reminders=" . json_encode($reminders)
        . " campaigns=" . json_encode($campaigns) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, "[" . date('c') . "] scheduler error: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
