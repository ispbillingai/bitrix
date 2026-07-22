# Standalone CRM (Bitrix24-style)

A self-contained PHP/MySQL CRM for capturing customer requests, routing them to
salespeople, managing appointments, and driving the WhatsApp + email automations
from `Management software.txt` — **without depending on Bitrix24**. The CRM owns
its own data (leads, deals, contacts, appointments, tasks). Bitrix24 sync is an
**optional, off-by-default** add-on.

Built on the house conventions of the `parking`/`order`/`proxyserver` repos:
PHP 8.1, PDO singleton, PSR-4 `src/`, config array + DB-backed settings overlay,
versioned migrations, event-log audit table, `git pull` → `php migrate.php` deploy.

## What it does

```
Public form (request.php) ─┐
Website form (webhook) ─────┼─► Leads  ──assign──► Seller ──auto──► WhatsApp + email
Trade-show / partner email ─┘     │  (welcome, agent profile, inactivity timer)
                                  ▼
                               Deals (pipeline) ──quote stage──► signing reminders
                                  │                └─won──► thank-you + logistics
                                  ▼
                            Appointments ──confirm──► reminders to customer + seller
                                  ▼
                               Tasks (KPI scoring per seller)

cron: bin/scheduler.php (every minute) ──► sends all due reminders + campaign batches
optional: Sync\BitrixSync ──► mirror new leads/deals into a Bitrix24 portal
```

- **Public request form** (`public/request.php`): a branded, bilingual (EN/IT)
  form (Nome, Cognome, Email, Telefono, Messaggio + optional preferred time). On
  submit it creates a lead and, if a time was given, an appointment request.
- **Website intake** (`public/webhooks/form-intake.php`): your existing site form
  POSTs here on submit (same fields) and a lead is created — no rebuild needed.
- **Lead API** (`public/webhooks/lead.php`): a partner company POSTs leads from
  their own software. Documented contract, Bearer token, retry-safe via their
  `external_id`. Lands in the `website` source category; `source_url` records
  which site it came from. Spec to hand an integrator:
  [docs/lead-api.md](docs/lead-api.md) ([italiano](docs/lead-api.it.md)).
- **Leads / Pipeline**: kanban boards, drag a card to change stage. Assigning a
  lead to a seller messages the customer that seller's profile. Convert a lead to
  a deal; the deal pipeline runs the signing/closing automations.
- **Appointments**: requests come in → staff assign a seller and confirm a time →
  reminders fire to **both** parties before the event.
- **Tasks + KPI**: assign work to sellers, score on completion, leaderboard.
- **Campaigns / Messages / Reminders / Activity log**: mass WhatsApp/email, full
  delivery outbox, the reminder queue, and an audit trail.

## Requirements → where each lives

| Requirement | This CRM |
|---|---|
| Lead acquisition (form, website, partner software, trade-show, partner email) | `request.php` + `webhooks/form-intake.php` + `webhooks/lead.php` → `Crm\LeadIntake` → `Crm\Leads::create` |
| Auto welcome (email + WhatsApp) | `welcome` reminder enqueued on lead create |
| Assign lead to seller → send seller profile | `Crm\Leads::assign` → `agent_assigned` |
| Activity reminder if lead not worked in N hours | `lead_inactivity`, silenced when the lead leaves the first stage |
| Appointment reminders (customer + seller) | `Crm\Appointments::schedule` at `appointment_offsets_min` |
| Signing reminders (15 days after sent, then 10/5 days before the deal's signature due date, + overdue to 15) | `Crm\Deals::moveStage` into the quote stage → `sign_due`/`sign_overdue` |
| Closing: thank-you + notify logistics | won stage → `thank_you` + `logistics_notify` |
| KPI / score evaluation | `Crm\Tasks` (kpi_score/weight) + leaderboard |
| Manual interrupt / silence any automation | move the record's stage; pending reminders auto-cancel |
| Mass WhatsApp/email marketing | `campaign.php` + `Campaign\Sender` (throttled) |
| Bitrix24 sync | **optional** `Sync\BitrixSync`, off by default |

## Layout

```
config/config.sample.php   copy to config.php (gitignored); only `db` is required here
db/schema.sql              full reference schema (+ seed pipelines)
migrations/                versioned changes applied by migrate.php (005–011 add the CRM + portal + tickets)
migrate.php                migration runner (CLI or ?key=)
bin/scheduler.php          cron: dispatch due reminders + campaign batches
lang/  en.php it.php        customer message copy (WhatsApp + email)
lang/  ui.en.php ui.it.php  dashboard UI strings
public/
  index.php                health check
  request.php              public customer request form
  portal.php               customer portal (magic-link/password login; view
                           estimate + order status; sign the contract via OTP)
  dashboard.php            CRM control panel (controller; renders /views)
  campaign.php             create a mass campaign
  webhooks/
    lead.php               partner lead API (documented, Bearer, retry-safe)
    form-intake.php        website/Jotform lead → Crm\Leads
    appointment-intake.php appointment request → Crm\Appointments
    bitrix-event.php       optional inbound (guarded by sync flag)
views/                     dashboard page partials (overview, leads, deals, …)
src/
  Bootstrap, Config, Db, Settings, Auth, Event/Log
  Crm/   Pipelines, Contacts, Leads, LeadIntake, Deals, Appointments, Tasks,
         Tickets, Automation, Activities, EntityResolver   — the CRM domain
  Portal/  Account (customer login + magic link), Otp (signing codes)
  Reminder/  Scheduler (queue), Templates (copy)
  Notify/    Notifier, TextMeBot (WhatsApp), Mailer
  Campaign/  Sender (mass send)
  Bitrix/    Client (REST)   ── used only by:
  Sync/      BitrixSync (optional push)
```

## Setup

```bash
git clone <repo> && cd <repo>

cp config/config.sample.php config/config.php
#   edit: db credentials, app.company_name/base_url/intake_secret,
#         textmebot.api_key, mail.*  (everything else is editable in Settings)

mysql -u root -p < db/schema.sql      # or: create the DB then `php migrate.php`
php migrate.php

# cron (every minute) — the only thing that actually sends
* * * * * php /var/www/html/crm/bin/scheduler.php >> /var/log/crm.log 2>&1
```

Point the web root at `public/`. The dashboard seeds a default **admin / admin**
on first load — change it on the Agents page. `views/` and `src/` live outside the
web root and are never served directly.

### Quick test

```bash
# health
curl https://<host>/index.php

# public form: open https://<host>/request.php and submit a request

# simulate the website webhook
curl -X POST "https://<host>/webhooks/form-intake.php?secret=INTAKE_SECRET" \
  -H 'Content-Type: application/json' \
  -d '{"nome":"Mario","cognome":"Rossi","telefono":"+393331234567","email":"mario@example.com","messaggio":"Info","lang":"it"}'

# the partner lead API (docs/lead-api.md)
curl -X POST "https://<host>/webhooks/lead.php" -H 'Authorization: Bearer INTAKE_SECRET' \
  -H 'Content-Type: application/json' \
  -d '{"source_url":"https://partner.it/contatti","external_id":"1","name":"Mario Rossi","phone":"+393331234567"}'

# flush the queue (welcome message, etc.)
php bin/scheduler.php
```

## Optional: Bitrix24 sync

The CRM is fully standalone. To **also** mirror new leads/deals into a Bitrix24
portal, go to **Settings → Bitrix24 sync**, tick *Enable*, and paste the inbound
webhook URL. `Sync\BitrixSync` then pushes on create/stage-change; the
`bitrix-event.php` endpoint receives inbound events. With sync off, none of this
runs and Bitrix is never contacted.

## Notes

- Every send is logged to `messages`; every decision to `events`; per-record
  history to `activities`. "Why did this go out?" is always answerable.
- Reminders are de-duplicated by `dedupe_key`, so retries/double-submits never
  double-send.
- Stages, cadences, offsets and copy are all config / DB / `lang/*` — tuning them
  needs no code change.
- **MySQL only** (no MariaDB-only SQL); column migrations guard against
  `information_schema` instead of `ADD COLUMN IF NOT EXISTS`.
