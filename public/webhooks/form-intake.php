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
 * Auth: ?secret=<app.intake_secret>.
 * Body: JSON or form-encoded. Recognised keys (any casing / common aliases):
 *   name | nome (+ cognome), phone | telefono, email, source, title, message | messaggio
 * Jotform: point a webhook here; raw rawRequest is also parsed as a fallback.
 */
require __DIR__ . '/../../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\Leads;
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

// --- read body (JSON first, then form-encoded) ---
$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST ?: [];
}

// Jotform posts a "rawRequest" JSON blob; merge it in if present.
if (isset($data['rawRequest']) && is_string($data['rawRequest'])) {
    $jf = json_decode($data['rawRequest'], true);
    if (is_array($jf)) {
        $data = array_merge($jf, $data);
    }
}

// --- normalise common field names (first match wins, case-insensitive) ---
$pick = static function (array $d, array $keys): string {
    foreach ($keys as $k) {
        foreach ($d as $dk => $dv) {
            if (strcasecmp($dk, $k) === 0 && is_scalar($dv) && trim((string)$dv) !== '') {
                return trim((string)$dv);
            }
        }
    }
    return '';
};

// Build the full name from name, or first+last (nome + cognome).
$name  = $pick($data, ['name', 'full_name', 'fullname', 'nome', 'q3_name']);
$first = $pick($data, ['first_name', 'firstname', 'nome']);
$last  = $pick($data, ['last_name', 'lastname', 'surname', 'cognome']);
if ($name === '') {
    $name = trim("$first $last");
}

$lead = [
    'name'     => $name,
    'phone'    => $pick($data, ['phone', 'telephone', 'mobile', 'tel', 'telefono', 'whatsapp', 'cellulare']),
    'email'    => $pick($data, ['email', 'e-mail', 'mail']),
    'company'  => $pick($data, ['company', 'azienda', 'ragione_sociale']),
    'source'   => $pick($data, ['source', 'origin']) ?: 'website',
    'title'    => $pick($data, ['title', 'subject', 'oggetto']),
    'comments' => $pick($data, ['comments', 'message', 'note', 'notes', 'messaggio']),
    'lang'     => $pick($data, ['lang', 'language', 'lingua', 'locale']), // en|it, blank = default
];

if ($lead['name'] === '' && $lead['phone'] === '' && $lead['email'] === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'empty_lead', 'received_keys' => array_keys($data)]);
    exit;
}

try {
    $leadId = Leads::create($lead);
    echo json_encode(['ok' => true, 'lead_id' => $leadId]);
} catch (Throwable $e) {
    Log::write('form_intake', 'intake_error', null, null, ['error' => $e->getMessage(), 'lead' => $lead]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'intake_failed', 'detail' => $e->getMessage()]);
}
