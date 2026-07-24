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
use Glue\Sibill\Customers as SibillCustomers;
use Glue\Sibill\Invoices;

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
    // Heartbeat first: it tells the dashboard's web dispatcher to stand down, so
    // no page load blocks on a WhatsApp send while this runner is alive. Stamped
    // before the work, not after, so a long batch never looks like an outage.
    Scheduler::markCronRun();

    $reminders = (new Scheduler())->runDue();
    $campaigns = (new Sender())->runBatch();
    // Refresh the Sibill invoice mirror on its own slower cadence. Self-throttling
    // and never throws, so a Sibill outage can't hold up the messages above.
    $sibill = Invoices::syncIfDue();
    // Then queue payment chases for whoever is overdue. Off unless switched on,
    // and it only queues — runDue() above delivers them on the next tick, at the
    // WhatsApp gateway's pace rather than in a sleeping loop here.
    $chase = SibillCustomers::runChaseIfDue();

    Log::write('scheduler', 'tick', null, null, [
        'reminders' => $reminders,
        'campaigns' => $campaigns,
    ] + ($sibill !== null ? ['sibill' => $sibill] : [])
      + ($chase !== null ? ['chase' => $chase] : []));
    fwrite(STDOUT, "[" . date('c') . "] reminders=" . json_encode($reminders)
        . " campaigns=" . json_encode($campaigns)
        . ($sibill !== null ? " sibill=" . json_encode($sibill) : "")
        . ($chase !== null ? " chase=" . json_encode($chase) : "") . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, "[" . date('c') . "] scheduler error: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
