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
use Glue\Crm\DayPlanner;
use Glue\Crm\Maintenance;
use Glue\Event\Log;
use Glue\Mail\LeadMailImporter;
use Glue\Pay\Contracts as PayContracts;
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

    // Two minutes of reminders per tick. With the gap the office has set — a
    // minute between messages, so the number keeps looking human — a queue of
    // twenty would otherwise hold this runner, and its lock, for twenty minutes,
    // long enough for the heartbeat below to look like an outage and for the
    // dashboard's own dispatcher to wake up behind it.
    $reminders = (new Scheduler())->runDue(200, 120);
    // A campaign sends at its own pace, two minutes by default, and that pace is
    // now kept between runs as well as inside one — so a minute of budget is
    // plenty: the tick sends the message that is due and leaves immediately
    // instead of sleeping with the lock held, and the next tick carries on.
    $campaigns = (new Sender())->runBatch(30, 60);
    // Refresh the Sibill invoice mirror on its own slower cadence. Self-throttling
    // and never throws, so a Sibill outage can't hold up the messages above.
    $sibill = Invoices::syncIfDue();
    // Then queue payment chases for whoever is overdue. Off unless switched on,
    // and it only queues — runDue() above delivers them on the next tick, at the
    // WhatsApp gateway's pace rather than in a sleeping loop here.
    $chase = SibillCustomers::runChaseIfDue();
    // Pull lead emails from the company mailbox (POP3) on its own cadence.
    // Self-throttling and never throws; a mailbox outage can't stall the rest.
    $mail = LeadMailImporter::pollIfDue();
    // Re-read live SmallPay contracts. The status callback is what keeps the CRM
    // current; this is the net under it, so a callback SmallPay never managed to
    // deliver can't leave a customer who stopped paying looking paid. Anything
    // it finds is queued as a message and goes out on the next tick, above.
    $pay = PayContracts::syncIfDue();
    // The evening planning prompt, and the chasing that follows it. Both are
    // no-ops until their configured hour, and the one-row-per-technician-per-day
    // key means a late or doubled cron pass cannot ask the same person twice.
    // They only QUEUE: runDue() above delivers on the next tick, at the
    // gateway's pace rather than in a sleeping loop here.
    $plan       = DayPlanner::runPrompt();
    $planChased = DayPlanner::runEscalation();
    // Customers with no periodic contract, three months after their last
    // visit: ask them to book the next one. Off until switched on, capped per
    // pass, and it only QUEUES — runDue() above delivers on the next tick.
    $maint = Maintenance::runFollowUps();
    // …and the other end of the same question: the customers who DO have a
    // contract, warned before it runs out. No-op until its configured hour.
    $maintExp = Maintenance::runExpiryNotices();

    // Stamp the heartbeat again on the way out. It was stamped before the work so
    // a long tick never reads as an outage; stamping it after keeps that true
    // when the work itself took minutes of waiting between messages.
    Scheduler::markCronRun();

    Log::write('scheduler', 'tick', null, null, [
        'reminders' => $reminders,
        'campaigns' => $campaigns,
    ] + ($sibill !== null ? ['sibill' => $sibill] : [])
      + ($chase !== null ? ['chase' => $chase] : [])
      + ($mail !== null ? ['mail' => $mail] : [])
      + ($pay !== null ? ['pay' => $pay] : [])
      + ($plan || $planChased ? ['planning' => ['asked' => $plan, 'chased' => $planChased]] : [])
      + ($maint ? ['maintenance' => $maint] : [])
      + ($maintExp ? ['maint_expiry' => $maintExp] : []));
    fwrite(STDOUT, "[" . date('c') . "] reminders=" . json_encode($reminders)
        . " campaigns=" . json_encode($campaigns)
        . ($sibill !== null ? " sibill=" . json_encode($sibill) : "")
        . ($chase !== null ? " chase=" . json_encode($chase) : "")
        . ($mail !== null ? " mail=" . json_encode($mail) : "")
        . ($pay !== null ? " pay=" . json_encode($pay) : "")
        . ($plan || $planChased ? " planning=" . json_encode(['asked' => $plan, 'chased' => $planChased]) : "")
        . ($maint ? " maintenance=" . $maint : "")
        . ($maintExp ? " maint_expiry=" . $maintExp : "") . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, "[" . date('c') . "] scheduler error: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
