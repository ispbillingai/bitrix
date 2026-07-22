<?php
declare(strict_types=1);

/**
 * Partner lead API — another company POSTs a lead here from their own software
 * or website and it lands in our pipeline exactly like a lead typed in by hand:
 * first stage, welcome message to the customer, inactivity timer for the seller.
 *
 *   POST https://<host>/webhooks/lead.php
 *   Authorization: Bearer <app.intake_secret>      (or ?secret=, or X-Api-Key)
 *   Content-Type: application/json
 *
 *   {
 *     "source":      "michaeltech",              // required — who is sending
 *     "source_url":  "https://michaeltech.it/…", // the page the request came from
 *     "external_id": "4711",                     // their id — makes retries safe
 *     "name":        "Mario Rossi",
 *     "phone":       "+393331234567",            // phone or email required
 *     "email":       "mario@example.com",
 *     "company": "…", "vat_number": "…", "zone": "…",
 *     "message": "…", "lang": "it"
 *   }
 *
 *   201 {"ok":true,"lead_id":42,"status":"created"}
 *   200 {"ok":true,"lead_id":42,"status":"duplicate"}   already had this one
 *
 * A GET with a valid secret returns the field list, so an integrator can check
 * their credentials and read the contract without asking us.
 *
 * Field names are forgiving (telefono/phone, messaggio/message, …) — see
 * Crm\LeadIntake. The full spec lives in docs/lead-webhook.md.
 */
require __DIR__ . '/../../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\LeadIntake;
use Glue\Event\Log;

Bootstrap::init();
header('Content-Type: application/json; charset=utf-8');

/** Reply and stop. */
$reply = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

// ---- auth: Bearer token, X-Api-Key, or ?secret= (all the same shared secret) ----
$expected = (string)Config::get('app.intake_secret', '');
$auth     = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$given    = (string)($_GET['secret'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '');
if ($given === '' && preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
    $given = trim($m[1]);
}
if ($expected === '' || !hash_equals($expected, $given)) {
    $reply(401, ['ok' => false, 'error' => 'unauthorized',
        'detail' => 'Send the token as "Authorization: Bearer <token>", "X-Api-Key: <token>" or ?secret=<token>.']);
}

// ---- GET: the contract, for the integrator to read ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $reply(200, [
        'ok'       => true,
        'endpoint' => Config::appBaseUrl() . '/webhooks/lead.php',
        'method'   => 'POST',
        'content_type' => 'application/json',
        'auth'     => 'Authorization: Bearer <token>  (or X-Api-Key, or ?secret=)',
        'required' => [
            'source' => 'short name of the sender, always the same value (e.g. "michaeltech")',
            'contact' => 'at least one of phone / email',
        ],
        'fields' => [
            'source'      => 'string, required',
            'source_url'  => 'string — the website/page the request was submitted on',
            'external_id' => 'string — your own id for this request; resending it returns the same lead',
            'name'        => 'string (or first_name + last_name)',
            'phone'       => 'string, E.164 preferred (+39…)',
            'email'       => 'string',
            'company'     => 'string',
            'vat_number'  => 'string',
            'zone'        => 'string — area/region, for routing',
            'message'     => 'string — what the customer wrote',
            'title'       => 'string — subject of the request',
            'lang'        => '"it" or "en" (default it) — language of our messages to the customer',
        ],
        'responses' => [
            '201' => '{"ok":true,"lead_id":42,"status":"created"}',
            '200' => '{"ok":true,"lead_id":42,"status":"duplicate"} — already received',
            '401' => 'bad or missing token',
            '422' => '{"ok":false,"error":"validation_failed","fields":{...}}',
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    $reply(405, ['ok' => false, 'error' => 'method_not_allowed', 'detail' => 'Use POST to send a lead.']);
}

// ---- body ----
$data = LeadIntake::payload();
if (!$data) {
    $reply(400, ['ok' => false, 'error' => 'empty_body',
        'detail' => 'Send a JSON object (Content-Type: application/json) or form-encoded fields.']);
}

$lead = LeadIntake::normalize($data);

// ---- validate ----
$errors = [];
if ($lead['source'] === '') {
    $errors['source'] = 'required — the short name of your system/site, always the same value';
}
if ($lead['phone'] === '' && $lead['email'] === '') {
    $errors['phone'] = 'phone or email is required';
    $errors['email'] = 'phone or email is required';
}
if ($lead['email'] !== '' && !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'not a valid email address';
}
if ($lead['phone'] !== '' && !preg_match('/^\+?[0-9]{6,15}$/', $lead['phone'])) {
    $errors['phone'] = 'not a valid phone number — digits only, international format preferred (+39…)';
}
if ($errors) {
    $reply(422, ['ok' => false, 'error' => 'validation_failed', 'fields' => $errors,
        'received_keys' => array_keys($data)]);
}

// A lead with no name still gets worked — don't reject it, just label it.
if ($lead['name'] === '') {
    $lead['name'] = $lead['company'] !== '' ? $lead['company'] : 'Unknown';
}
if ($lead['title'] === '') {
    $lead['title'] = 'Request from ' . $lead['source'];
}

// ---- create ----
try {
    $res = LeadIntake::submit($lead);
} catch (Throwable $e) {
    Log::write('lead_api', 'intake_error', null, null, ['error' => $e->getMessage(), 'lead' => $lead]);
    $reply(500, ['ok' => false, 'error' => 'intake_failed', 'detail' => $e->getMessage()]);
}

Log::write('lead_api', $res['duplicate'] ? 'lead_duplicate' : 'lead_received', 'lead', $res['lead_id'],
    ['source' => $lead['source'], 'source_url' => $lead['source_url'], 'external_id' => $lead['external_id']]);

$reply($res['duplicate'] ? 200 : 201, [
    'ok'      => true,
    'lead_id' => $res['lead_id'],
    'status'  => $res['duplicate'] ? 'duplicate' : 'created',
]);
