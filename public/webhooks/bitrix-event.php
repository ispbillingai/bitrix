<?php
declare(strict_types=1);

/**
 * Receives Bitrix24 OUTBOUND webhook events and runs the matching automation
 * (requirements #3 agent profile, #4 silence inactivity, #6 signing reminders,
 * #7 thank-you + logistics).
 *
 * Configure in Bitrix24: Developer resources -> Outbound webhook. Handler URL =
 * this file with ?secret=<bitrix.outbound_secret>. Subscribe to:
 *   ONCRMLEADUPDATE, ONCRMDEALADD, ONCRMDEALUPDATE
 *
 * Bitrix posts form-encoded:  event, data[FIELDS][ID], auth[...]
 */
require __DIR__ . '/../../src/Bootstrap.php';

use Glue\Bitrix\EventHandler;
use Glue\Bootstrap;
use Glue\Config;
use Glue\Event\Log;

Bootstrap::init();
header('Content-Type: application/json');

// --- auth: shared secret in the URL ---
$secret = $_GET['secret'] ?? '';
if (!hash_equals((string)Config::get('bitrix.outbound_secret', ''), (string)$secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$event = strtoupper((string)($_POST['event'] ?? ''));
$id    = (int)($_POST['data']['FIELDS']['ID'] ?? 0);

if ($event === '' || $id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_event_or_id']);
    exit;
}

$entityType = str_contains($event, 'LEAD') ? 'lead'
    : (str_contains($event, 'DEAL') ? 'deal' : null);

if ($entityType === null) {
    // Acknowledge unknown events so Bitrix doesn't retry forever.
    echo json_encode(['ok' => true, 'ignored' => $event]);
    exit;
}

try {
    $result = (new EventHandler())->handle($entityType, $id, $event);
    echo json_encode(['ok' => true] + $result);
} catch (Throwable $e) {
    Log::write('bitrix_event', 'handler_error', $entityType, $id,
        ['event' => $event, 'error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'handler_failed', 'detail' => $e->getMessage()]);
}
