<?php
declare(strict_types=1);

/**
 * Create a mass WhatsApp / email campaign (requirement part2 #2).
 * Auth: ?secret=<app.intake_secret>.
 *
 * Body (JSON): {
 *   name: "June promo",
 *   channel: "whatsapp" | "email",
 *   subject: "...",                 // email only
 *   body: "Hi {name}, ...",          // {name}/{company} placeholders supported
 *   recipients: [ "+254700000000", {recipient:"+2547...", name:"Jane"} ]
 * }
 *
 * The cron runner (bin/scheduler.php) sends them in throttled batches.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Campaign\Sender;
use Glue\Config;

Bootstrap::init();
header('Content-Type: application/json');

$secret = $_GET['secret'] ?? '';
if (!hash_equals((string)Config::get('app.intake_secret', ''), (string)$secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data) || empty($data['body']) || empty($data['recipients']) || !is_array($data['recipients'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'need_body_and_recipients']);
    exit;
}

try {
    $id = (new Sender())->create(
        (string)($data['name'] ?? 'Campaign'),
        (string)($data['channel'] ?? 'whatsapp'),
        (string)$data['body'],
        $data['subject'] ?? null,
        $data['recipients'],
        (string)($data['lang'] ?? 'it')
    );
    echo json_encode(['ok' => true, 'campaign_id' => $id, 'queued' => count($data['recipients'])]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'campaign_failed', 'detail' => $e->getMessage()]);
}
