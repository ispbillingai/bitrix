<?php
declare(strict_types=1);

/**
 * Skebby's delivery report: what became of an SMS after the gateway took it
 * (migration 077).
 *
 * Skebby calls this address once per message, exactly as its documentation
 * shows:
 *
 *   GET …/webhooks/skebby-dlr.php?secret=…
 *       &delivery_date=20210204211100&order_id=…&recipient=%2B39…&status=DLVRD
 *
 * Until now "sent" meant only that the gateway accepted the message. For a
 * verification code that is not the question anyone is asking — the question is
 * whether it reached the phone — and this is the only thing that can answer it.
 *
 * Matched on the gateway's own id (messages.provider_ref, Skebby's order_id)
 * and, when a report carries none, on the most recent SMS to that number. The
 * status word is stored as the operator said it, because the set is theirs to
 * change and a report we do not recognise is still worth keeping.
 *
 * Always answers 200 once it has authenticated: a delivery report is a fact
 * being told to us, not a request that can fail, and a gateway that gets errors
 * back starts retrying or stops reporting.
 *
 * Auth: ?secret=<app.intake_secret>, the same one the other webhooks use.
 */
require __DIR__ . '/../../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;

Bootstrap::init();
header('Content-Type: application/json');

$secret = $_GET['secret'] ?? $_POST['secret'] ?? '';
if (!hash_equals((string)Config::get('app.intake_secret', ''), (string)$secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$in        = $_GET + $_POST;
$orderId   = trim((string)($in['order_id'] ?? ''));
$orderId   = ($orderId === '' || strtolower($orderId) === 'null') ? '' : $orderId;
$recipient = Notifier::normalizePhone((string)($in['recipient'] ?? ''));
$status    = strtoupper(preg_replace('/[^A-Za-z_]/', '', (string)($in['status'] ?? '')) ?? '');
$status    = substr($status, 0, 24);

// "20210204211100" — the operator's own clock. Unreadable or absent: now.
$when = (string)($in['delivery_date'] ?? '');
$at   = preg_match('/^\d{14}$/', $when)
    ? date('Y-m-d H:i:s', (int)mktime(
        (int)substr($when, 8, 2), (int)substr($when, 10, 2), (int)substr($when, 12, 2),
        (int)substr($when, 4, 2), (int)substr($when, 6, 2), (int)substr($when, 0, 4)))
    : date('Y-m-d H:i:s');

if ($status === '') {
    echo json_encode(['ok' => true, 'matched' => 0, 'note' => 'no status']);
    exit;
}

$pdo = Db::pdo();
$id  = 0;
if ($orderId !== '') {
    $s = $pdo->prepare("SELECT id FROM messages WHERE channel = 'sms' AND provider_ref = ? ORDER BY id DESC LIMIT 1");
    $s->execute([$orderId]);
    $id = (int)($s->fetchColumn() ?: 0);
}
if ($id === 0 && $recipient !== '') {
    // No id to go on: the newest SMS to that number that has not been reported
    // on yet. A report always follows its own message, so the newest unanswered
    // one is the one being spoken about.
    $s = $pdo->prepare(
        "SELECT id FROM messages
          WHERE channel = 'sms' AND recipient = ? AND delivery_status IS NULL
          ORDER BY id DESC LIMIT 1"
    );
    $s->execute([$recipient]);
    $id = (int)($s->fetchColumn() ?: 0);
}

if ($id > 0) {
    $pdo->prepare('UPDATE messages SET delivery_status = ?, delivered_at = ? WHERE id = ?')
        ->execute([$status, $at, $id]);
}
// Logged either way: a report we could not place is worth seeing, not swallowing.
Log::write('notify', 'sms_delivery_report', 'message', $id ?: null,
    ['status' => $status, 'order_id' => $orderId, 'recipient' => $recipient, 'at' => $at]);

echo json_encode(['ok' => true, 'matched' => $id > 0 ? 1 : 0]);
