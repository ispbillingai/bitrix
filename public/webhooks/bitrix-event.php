<?php
declare(strict_types=1);

/**
 * OPTIONAL inbound hook for the Bitrix24 sync (off by default).
 *
 * The CRM is standalone — this endpoint only matters if you turn on Bitrix sync in
 * Settings and want changes made *inside Bitrix* echoed back. When sync is disabled
 * it simply acknowledges and ignores, so a stray Bitrix webhook never errors.
 *
 * Configure in Bitrix24: Developer resources -> Outbound webhook. Handler URL =
 * this file with ?secret=<bitrix.outbound_secret>. Bitrix posts form-encoded:
 *   event, data[FIELDS][ID], auth[...]
 */
require __DIR__ . '/../../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Event\Log;
use Glue\Sync\BitrixSync;

Bootstrap::init();
header('Content-Type: application/json');

// --- auth: shared secret in the URL ---
$secret = $_GET['secret'] ?? '';
if (!hash_equals((string)Config::get('bitrix.outbound_secret', ''), (string)$secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

if (!BitrixSync::enabled()) {
    // Sync is off: acknowledge so Bitrix stops retrying, but do nothing.
    echo json_encode(['ok' => true, 'sync' => 'disabled']);
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

// Record the inbound event; reconciliation back into local records is handled by
// the sync module's pull pass (kept minimal — push is the primary direction).
Log::write('bitrix_event', 'inbound', $entityType, $id, ['event' => $event]);
echo json_encode(['ok' => true, 'received' => $event, 'entity' => $entityType, 'id' => $id]);
