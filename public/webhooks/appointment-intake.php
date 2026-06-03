<?php
declare(strict_types=1);

/**
 * Requirement #5: appointment management. Register a scheduled appointment and
 * the system schedules reminders to BOTH the customer and the agent at the
 * configured offsets before the event (reminders.appointment_offsets_min).
 *
 * Call this from a Bitrix24 robot/automation on the calendar/activity, or from
 * any booking flow. Auth: ?secret=<app.intake_secret>.
 *
 * Body (JSON): {
 *   entity_type: "deal"|"lead", bitrix_id: 123,
 *   when: "2026-06-10 15:00",         // appointment start, app timezone
 *   when_label: "Tue 10 Jun, 3:00 PM",// optional human text for the message
 *   customer_phone, customer_email,    // optional overrides
 *   agent_phone, agent_email           // optional; agent reminder skipped if absent
 * }
 */
require __DIR__ . '/../../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;

Bootstrap::init();
header('Content-Type: application/json');

$secret = $_GET['secret'] ?? '';
if (!hash_equals((string)Config::get('app.intake_secret', ''), (string)$secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
$entityType = ($data['entity_type'] ?? 'deal') === 'lead' ? 'lead' : 'deal';
$bitrixId   = (int)($data['bitrix_id'] ?? 0);
$whenTs     = strtotime((string)($data['when'] ?? ''));

if ($bitrixId <= 0 || !$whenTs) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'need_bitrix_id_and_when']);
    exit;
}

$whenLabel = (string)($data['when_label'] ?? date('D j M, g:i A', $whenTs));
$offsets   = (array)Config::get('reminders.appointment_offsets_min', [1440, 120]);
$sched     = new Scheduler();
$scheduled = 0;

foreach ($offsets as $minBefore) {
    $dueTs = $whenTs - (int)$minBefore * 60;
    if ($dueTs < time()) {
        continue; // offset already in the past for this appointment
    }
    $due = date('Y-m-d H:i:s', $dueTs);

    // Customer reminder
    $sched->enqueue([
        'entity_type'    => $entityType,
        'bitrix_id'      => $bitrixId,
        'rule_key'       => 'appointment_customer',
        'recipient_type' => 'customer',
        'channel'        => 'both',
        'due_at'         => $due,
        'payload'        => array_filter([
            'when'           => $whenLabel,
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
        ]),
        'dedupe_key'     => "appt_cust:$entityType:$bitrixId:$whenTs:$minBefore",
    ]);
    $scheduled++;

    // Agent reminder (only if we were given agent contact details)
    if (!empty($data['agent_phone']) || !empty($data['agent_email'])) {
        $sched->enqueue([
            'entity_type'    => $entityType,
            'bitrix_id'      => $bitrixId,
            'rule_key'       => 'appointment_agent',
            'recipient_type' => 'agent',
            'channel'        => 'both',
            'due_at'         => $due,
            'payload'        => array_filter([
                'when'        => $whenLabel,
                'agent_phone' => $data['agent_phone'] ?? null,
                'agent_email' => $data['agent_email'] ?? null,
            ]),
            'dedupe_key'     => "appt_agent:$entityType:$bitrixId:$whenTs:$minBefore",
        ]);
        $scheduled++;
    }
}

Log::write('appointment', 'appointment_scheduled', $entityType, $bitrixId,
    ['when' => date('Y-m-d H:i:s', $whenTs), 'reminders' => $scheduled]);

echo json_encode(['ok' => true, 'reminders_scheduled' => $scheduled]);
