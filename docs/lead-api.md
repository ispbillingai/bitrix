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

The only required data is a contact: **phone or email**. Everything else is
optional.

```json
{
  "name":    "Mario Rossi",
  "phone":   "+393331234567",
  "email":   "mario.rossi@example.com",
  "message": "Vorrei informazioni sul modello X"
}
```

| Field | Required | Notes |
|---|---|---|
| `phone` / `email` | **at least one** | `+39…` preferred; `00`-prefixed and spaced numbers are cleaned up. |
| `name` | no | Or `first_name` + `last_name`. Falls back to `company`, then `Unknown`. |
| `message` | no | What the customer wrote. |
| `external_id` | no | The sender's own id; if sent, resending it returns the same lead instead of a duplicate. See *Retries*. |
| `company`, `vat_number`, `zone`, `title`, `lang` | no | `lang` is `it` (default) or `en` — the language of our messages **to the customer**. |
| `source_url` | no | If sent, the site the request came from is shown on the lead as *Came from*. Not required. |

There is no `source` field to send: every lead here is filed under the CRM's
existing **`website`** category. (Internally the endpoint forces
`source = website` and ignores anything the sender puts there. To break one
partner out into their own row of the monthly report, derive `source` from the
optional `source_url` host in [`lead.php`](../public/webhooks/lead.php) — never
from a sender-supplied value, or a typo on their side silently becomes a new
category.)

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

Treat any 5xx or a timeout as *unknown* and retry (with the same `external_id`
if you send one).

## Retries and duplicates

Two guards, so a retry never creates a second lead and never double-messages the
customer:

1. **`external_id`** (optional) — if sent, always maps back to the first lead
   created for that value: a retry with the same id returns it instead of
   creating a duplicate. It's namespaced internally by the `source_url` host when
   one is present, so it only has to be unique within the sender's own system.
2. Even without `external_id`, the same phone or email arriving again within
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
  -d '{"name":"Mario Rossi","phone":"+393331234567","email":"mario@example.com","message":"Test"}'
```

The lead appears on the Leads page immediately under *Source → website*.

## PHP example

```php
$ch = curl_init('https://crm.upgradesrls.com/webhooks/lead.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    CURLOPT_POSTFIELDS     => json_encode([
        'name'        => $request->name,
        'phone'       => $request->phone,
        'email'       => $request->email,
        'message'     => $request->message,
        'external_id' => (string)$request->id,   // optional: retry safety
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
