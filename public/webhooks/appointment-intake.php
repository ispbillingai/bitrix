<?php
declare(strict_types=1);

/**
 * Requirement #5: appointment intake. Registers a customer's request for an
 * appointment with a salesperson. It lands as status 'requested' with a preferred
 * time; staff then assign a seller and confirm the real slot from the dashboard,
 * which schedules the reminders to both parties.
 *
 * Auth: ?secret=<app.intake_secret>.
 * Body (JSON or form): {
 *   name, phone, email,            // the customer
 *   preferred_at: "2026-06-10 15:00", // their preferred time (also accepts "when")
 *   title, notes, lead_id, lang
 * }
 */
require __DIR__ . '/../../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\Appointments;
use Glue\Event\Log;

Bootstrap::init();
header('Content-Type: application/json');

$secret = $_GET['secret'] ?? '';
if (!hash_equals((string)Config::get('app.intake_secret', ''), (string)$secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) {
    $data = $_POST ?: [];
}

$preferred = (string)($data['preferred_at'] ?? $data['when'] ?? '');
$name  = trim((string)($data['name'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$email = trim((string)($data['email'] ?? ''));

if ($name === '' && $phone === '' && $email === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'need_customer']);
    exit;
}

try {
    $apptId = Appointments::request([
        'name'         => $name,
        'phone'        => $phone,
        'email'        => $email,
        'preferred_at' => $preferred,
        'title'        => $data['title'] ?? null,
        'notes'        => $data['notes'] ?? null,
        'lead_id'      => isset($data['lead_id']) ? (int)$data['lead_id'] : null,
        'lang'         => $data['lang'] ?? null,
    ]);
    echo json_encode(['ok' => true, 'appointment_id' => $apptId]);
} catch (Throwable $e) {
    Log::write('appointment', 'intake_error', null, null, ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'intake_failed', 'detail' => $e->getMessage()]);
}
