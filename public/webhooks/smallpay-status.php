<?php
declare(strict_types=1);

/**
 * SmallPay §3.4 "Controaggiornamento posizioni debitorie" — the callback
 * SmallPay POSTs whenever a position changes: the first payment lands, a
 * monthly rate is collected, a card is refused.
 *
 * This URL is handed to SmallPay as statusUpdateCallbackUrl when the position
 * is created, so it is public and unguessable only by accident. It is therefore
 * treated as hostile input: anyone who learns it could otherwise POST "this
 * customer stopped paying" and have the CRM cut off a paying client.
 *
 * What makes it safe is hashPass. SmallPay signs every callback with
 *
 *   sha1('paymentId=' + paymentId + 'domain=' + domain
 *        + 'timestamp=' + timestamp + 'uniqueId=' + uniqueId)
 *
 * and uniqueId is a secret only we and SmallPay hold. An unsigned or wrongly
 * signed body is recorded and dropped, never applied.
 *
 * Everything that arrives — accepted or not — is written to payment_events
 * first, keyed uniquely, so a replay is visible and a rejected call can be
 * looked at afterwards. That log is also the idempotency guard: SmallPay
 * retries until it gets a 200, and a repeated "rate unpaid" must not chase the
 * customer twice.
 *
 * Always answers 200 once a body has been stored. A 500 here just makes
 * SmallPay redeliver something we already have.
 */
require __DIR__ . '/../../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Db;
use Glue\Event\Log;
use Glue\Pay\Contracts;
use Glue\Pay\SmallPay;

Bootstrap::init();
header('Content-Type: application/json');

$raw  = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    // Not JSON at all: log the shape, not the content, and refuse.
    Log::write('smallpay', 'callback_unparseable', null, null, ['bytes' => strlen($raw)]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'expected_json']);
    exit;
}

$reference = trim((string)($body['paymentId'] ?? ''));
$timestamp = trim((string)($body['timestamp'] ?? ''));
$status    = trim((string)($body['status'] ?? ''));
$ip        = (string)($_SERVER['REMOTE_ADDR'] ?? '');

// The event key has to be stable for a redelivery of the SAME event and
// different for a genuinely new one. paymentId+timestamp is SmallPay's own
// identity for a status change; the body hash covers the case of two changes
// sharing a timestamp, which would otherwise silently swallow the second.
$eventKey = 'sp:' . $reference . ':' . $timestamp . ':' . substr(sha1($raw), 0, 12);

$store = Db::pdo()->prepare(
    'INSERT INTO payment_events (event_key, contract_id, reference, status, verified, payload, remote_ip, note)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

/** Record the callback and say whether this is the first time we have seen it. */
$record = static function (?int $contractId, bool $verified, ?string $note) use ($store, $eventKey, $reference, $status, $raw, $ip): bool {
    try {
        $store->execute([$eventKey, $contractId, $reference ?: null, $status ?: null, $verified ? 1 : 0, $raw, $ip ?: null, $note]);
        return true;
    } catch (Throwable) {
        return false; // unique key: already stored, so this is a redelivery
    }
};

if (!SmallPay::configured()) {
    $record(null, false, 'smallpay not configured');
    Log::write('smallpay', 'callback_unconfigured', null, null, ['reference' => $reference]);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_configured']);
    exit;
}

$contract = $reference !== '' ? Contracts::findByReference($reference) : null;

// --- authenticate ---
try {
    $verified = (new SmallPay())->verifyResponse($body);
} catch (Throwable $e) {
    $record($contract ? (int)$contract['id'] : null, false, 'verify error');
    Log::write('smallpay', 'callback_verify_error', null, null, ['error' => $e->getMessage()]);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'verify_failed']);
    exit;
}

if (!$verified) {
    $record($contract ? (int)$contract['id'] : null, false, 'bad hashPass');
    Log::write('smallpay', 'callback_rejected', 'payment_contract',
        $contract ? (int)$contract['id'] : null, ['reference' => $reference, 'ip' => $ip]);
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'bad_signature']);
    exit;
}

if ($contract === null) {
    // Signed by SmallPay, so it is genuine — but for a position this CRM has no
    // row for. Worth keeping and worth someone looking at; not worth a retry.
    $record(null, true, 'no matching contract');
    Log::write('smallpay', 'callback_unknown_position', null, null, ['reference' => $reference]);
    echo json_encode(['ok' => true, 'applied' => false, 'reason' => 'unknown_reference']);
    exit;
}

$fresh = $record((int)$contract['id'], true, null);
if (!$fresh) {
    // Already processed. Answer 200 so SmallPay stops redelivering.
    echo json_encode(['ok' => true, 'applied' => false, 'reason' => 'duplicate']);
    exit;
}

try {
    $res = Contracts::applyPayload($contract, $body, 'callback');
    Log::write('smallpay', 'callback_applied', 'payment_contract', (int)$contract['id'], $res);
    echo json_encode(['ok' => true, 'applied' => true] + $res);
} catch (Throwable $e) {
    // The body is stored and the event key is spent, so a redelivery would be
    // dropped as a duplicate. Record why it did not apply; the periodic sync
    // will pick this contract up regardless, which is exactly why it exists.
    Db::pdo()->prepare('UPDATE payment_events SET note = ? WHERE event_key = ?')
        ->execute([substr('apply failed: ' . $e->getMessage(), 0, 190), $eventKey]);
    Log::write('smallpay', 'callback_apply_failed', 'payment_contract', (int)$contract['id'],
        ['error' => $e->getMessage()]);
    echo json_encode(['ok' => true, 'applied' => false, 'reason' => 'apply_failed']);
}
