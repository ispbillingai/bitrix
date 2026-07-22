<?php
declare(strict_types=1);

/**
 * Requirement #1: lead acquisition from external sources.
 *
 * The customer's website form (e.g. the Cashmatic "Ricevi la nostra offerta" form:
 * Nome, Cognome, Email, Telefono, Messaggio) POSTs here on submit; so can Jotform,
 * the trade-show app, or a partner-email parser. We normalise the fields and create
 * a lead in OUR CRM (first stage) with the welcome + inactivity automations.
 *
 * Deliberately permissive: it takes whatever the form sends and only refuses a
 * payload with no customer in it at all. A company integrating from their own
 * software should use `lead.php` instead — same result, but a documented
 * contract with real validation errors. Both share Crm\LeadIntake.
 *
 * Auth: ?secret=<app.intake_secret>.
 * Body: JSON or form-encoded. Recognised keys (any casing / common aliases):
 *   name | nome (+ cognome), phone | telefono, email, source, title, message | messaggio
 * Jotform: point a webhook here; raw rawRequest is also parsed as a fallback.
 */
require __DIR__ . '/../../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\LeadIntake;
use Glue\Event\Log;

Bootstrap::init();
header('Content-Type: application/json');

// --- auth ---
$secret = $_GET['secret'] ?? '';
if (!hash_equals((string)Config::get('app.intake_secret', ''), (string)$secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$data = LeadIntake::payload();
$lead = LeadIntake::normalize($data);
$lead['source'] = $lead['source'] ?: 'website';

if ($lead['name'] === '' && $lead['phone'] === '' && $lead['email'] === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'empty_lead', 'received_keys' => array_keys($data)]);
    exit;
}

try {
    $res = LeadIntake::submit($lead);
    echo json_encode(['ok' => true, 'lead_id' => $res['lead_id'], 'duplicate' => $res['duplicate']]);
} catch (Throwable $e) {
    Log::write('form_intake', 'intake_error', null, null, ['error' => $e->getMessage(), 'lead' => $lead]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'intake_failed', 'detail' => $e->getMessage()]);
}
