# Lead API — sending us a lead

For a company that wants to create a lead in our CRM from their own software or
website. One HTTP request per lead; it lands in the pipeline exactly like a lead
typed in by our staff, and the customer gets the usual welcome message.

Handler: [`public/webhooks/lead.php`](../public/webhooks/lead.php).
Italian version of this page (the one to send to an integrator):
[`lead-api.it.md`](lead-api.it.md).

## Endpoint

```
POST https://crm.upgradesrls.com/webhooks/lead.php
Authorization: Bearer <TOKEN>
Content-Type: application/json
```

`<TOKEN>` is the **intake secret** — dashboard → Settings → *Intake secret*. The
same value already guards the website/appointment webhooks, so an integrator who
has it can post leads and nothing else. The token may also travel as
`X-Api-Key: <TOKEN>` or `?secret=<TOKEN>` for clients that can't set headers.

Server-to-server only: never put the token in browser JavaScript.

## Body

```json
{
  "source_url":  "https://www.michaeltech.it/contatti",
  "external_id": "4711",
  "name":        "Mario Rossi",
  "phone":       "+393331234567",
  "email":       "mario.rossi@example.com",
  "company":     "Rossi SRL",
  "vat_number":  "01234567890",
  "zone":        "Lombardia",
  "message":     "Vorrei informazioni sul modello X",
  "lang":        "it"
}
```

| Field | Required | Notes |
|---|---|---|
| `phone` / `email` | **at least one** | `+39…` preferred; `00`-prefixed and spaced numbers are cleaned up. |
| `source_url` | strongly recommended | The site/page the request was submitted on. Shown on the lead as *Came from*, and it's what tells one sender from another. |
| `external_id` | recommended | The sender's own id for the request. Only has to be unique within their own system. See *Retries*. |
| `name` | no | Or `first_name` + `last_name`. Falls back to `company`, then `Unknown`. |
| `company`, `vat_number`, `zone`, `title`, `message`, `lang` | no | `lang` is `it` (default) or `en` — the language of our messages **to the customer**. |

There is no `source` field to send: every lead here is filed under the CRM's
existing **`website`** category, and `source_url` records which site it came
from. (Internally the endpoint forces `source = website` and ignores anything
the sender puts there. To break one partner out into their own row of the
monthly report, derive `source` from the `source_url` host in
[`lead.php`](../public/webhooks/lead.php) — never from a sender-supplied value,
or a typo on their side silently becomes a new category.)

Field names are matched case-insensitively and common aliases are accepted, so a
form that already posts `nome`, `telefono`, `messaggio`, `azienda`, `sito` works
without renaming anything. The map lives in
[`src/Crm/LeadIntake.php`](../src/Crm/LeadIntake.php). Form-encoded bodies are
accepted as well as JSON.

## Responses

| Status | Body | Meaning |
|---|---|---|
| `201` | `{"ok":true,"lead_id":42,"status":"created"}` | Lead created. |
| `200` | `{"ok":true,"lead_id":42,"status":"duplicate"}` | Already had it — nothing new was created. |
| `401` | `{"ok":false,"error":"unauthorized"}` | Bad or missing token. |
| `422` | `{"ok":false,"error":"validation_failed","fields":{…}}` | `fields` names what's wrong. |
| `500` | `{"ok":false,"error":"intake_failed"}` | Our side. Safe to retry. |

Treat any 5xx or a timeout as *unknown* and retry with the same `external_id`.

## Retries and duplicates

Two guards, so a retry never creates a second lead and never double-messages the
customer:

1. **`external_id`** — always maps back to the first lead created for it. This is
   the reliable one; send it. It's namespaced by the host in `source_url`, so it
   only has to be unique within the sender's own system: two senders both
   numbering their requests from 1 never collide.
2. Without `external_id`, the same phone or email arriving again within
   **15 minutes** is treated as the same lead (catches double submits).

## Checking the integration

A `GET` with a valid token returns the field list — useful for the integrator to
confirm their credentials work before writing the POST:

```bash
curl "https://crm.upgradesrls.com/webhooks/lead.php" -H "Authorization: Bearer <TOKEN>"
```

Send one:

```bash
curl -i -X POST "https://crm.upgradesrls.com/webhooks/lead.php" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"source_url":"https://www.michaeltech.it/contatti","external_id":"test-1","name":"Mario Rossi","phone":"+393331234567","email":"mario@example.com","message":"Test"}'
```

The lead appears on the Leads page immediately under *Source → website*, with
*Came from* linking back to `source_url`.

## PHP example

```php
$ch = curl_init('https://crm.upgradesrls.com/webhooks/lead.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    CURLOPT_POSTFIELDS     => json_encode([
        'source_url'  => 'https://www.michaeltech.it/contatti',
        'external_id' => (string)$request->id,
        'name'        => $request->name,
        'phone'       => $request->phone,
        'email'       => $request->email,
        'message'     => $request->message,
    ]),
    CURLOPT_TIMEOUT        => 15,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);   // 201 created, 200 duplicate
```

## If a partner needs their own token

Today every integrator shares the intake secret. If one has to be revoked
without breaking the others, give each a row in a `api_tokens` table
(`token`, `label`, `source`, `active`) and check that instead of the single
`app.intake_secret` in `lead.php` — the rest of the contract is unchanged.
