# Bitrix24 Glue

A thin PHP/MySQL middleware around **Bitrix24 Standard**. Bitrix24 stays the CRM
and source of truth; this app fills the gaps the Standard plan can't cover on its
own — multi-source lead intake, **WhatsApp via TextMeBot**, custom timers, and the
signing-reminder cadences — driven by Bitrix **inbound + outbound webhooks**.

Built to match the house conventions of the `parking`, `order`, and `proxyserver`
repos: PHP 8.1, PDO singleton, PSR-4 `src/`, config array, versioned migrations,
event-log audit table, GitHub → `git pull` → `php migrate.php` deploy.

## How it fits together

```
External forms ─┐                         ┌─► TextMeBot (WhatsApp)
Website         ─┼─► form-intake.php ──┐   │
Trade-show app  ─┘                     ├─► Bitrix24 REST (create lead)
Partner email   ─► (parser) ───────────┘
                                            ┌─► reminders queue (MySQL)
Bitrix24 ──(outbound webhook)──► bitrix-event.php ─┤
  stage/agent change                       └─► tracked_entities (timers)

cron: bin/scheduler.php ──► due reminders + campaign batches ──► WhatsApp + email
```

- **Inbound webhook** (`config.bitrix.base_url`): how *we* call Bitrix (create
  leads, read deals/users, …).
- **Outbound webhook** → `public/webhooks/bitrix-event.php`: how *Bitrix* tells us
  a lead/deal changed so we can react.
- **cron** (`bin/scheduler.php`, every minute): the only thing that actually sends
  — reminders and campaign batches. Endpoints just *enqueue*, so nothing blocks.

## Requirements → where each lives

| # | Requirement | Bitrix24 native | This app |
|---|-------------|-----------------|----------|
| 1 | Lead acquisition (forms, website, trade-show, partner email) | CRM lead store, native forms | `form-intake.php` → `Lead\Intake` creates the lead in the first status |
| 2 | Auto welcome (first pipeline + email/WhatsApp) | Robot can set stage / send email | `welcome` reminder (email **+ WhatsApp via TextMeBot**) enqueued on intake |
| 3 | Agent assignment → send agent profile (email/WhatsApp) | Manual assignment | `bitrix-event.php` detects `ASSIGNED_BY_ID` change → `agent_assigned` message with the agent's name/phone/email pulled from `user.get` |
| 4 | Activity reminder if lead not moved in 2h | — (Standard timers are weak) | `lead_inactivity` reminder to the **agent**; auto-silenced when the lead leaves the first status |
| 5 | Appointment reminders (agent + customer) | Calendar/activities | `appointment-intake.php` schedules both at `appointment_offsets_min` (24h, 2h) |
| 6 | Signing reminders (10/5/0 days, Bitrix24 Sign) | Bitrix24 Sign sends/tracks the document | `sign_due` cadence + overdue nudges to **15 days**; silenced when the deal moves |
| 7 | Closing: thank-you + notify logistics | Manual stage move to "won" | `thank_you` to customer + `logistics_notify` email/WhatsApp to logistics |
| P1 | "To Work" 3h routing timer | — | `deal_inactivity_hours` (same mechanism as #4) |
| P1 | KPI / score evaluation | **Native** (Bitrix tasks + CRM analytics/reports) | — *(no code; configure in Bitrix)* |
| P1 | External forms (Jotform) or native forms | Native forms | `form-intake.php` accepts Jotform webhooks (incl. `rawRequest`) |
| P2 | Send/track signed quotes, contracts, invoices | **Native** Bitrix24 Sign | — |
| P2 | Recurring reminders on no-sign within 15 days | — | `sign_overdue` recurring (email + WhatsApp) |
| P2 | WhatsApp mass marketing | limited | `campaign.php` + `Campaign\Sender` (throttled) — **see caveat below** |
| P2 | WhatsApp support bot | **Native** Bitrix24 Open Channel bot | — |
| P2 | Manual interrupt / silence any automation | change the deal status | every timed reminder carries `skip_if_stage_changed_from`; moving the deal cancels/skips it |
| P3 | Quotes from mobile app | **Native** Bitrix24 app | — |
| P3 | Trade-fair app + business-card OCR | **Native** Bitrix24 (CRM scanner) | leads it produces can also flow through `form-intake.php` |

### Answers to the open questions in the brief

- **WhatsApp mass marketing to unlimited contacts:** technically the campaign
  runner will send to any list, but **TextMeBot drives one ordinary WhatsApp
  number** — high-volume marketing risks that number being banned by WhatsApp.
  For compliant, unlimited bulk you need the **official WhatsApp Business API**
  with pre-approved message templates (swap the gateway in `Notify\TextMeBot`).
  The throttle (`campaign_throttle_seconds`) reduces but does not remove the risk.
- **WhatsApp support bot:** that's a **Bitrix24 Open Channel** feature (native),
  not this middleware. Connect WhatsApp as an Open Channel in Bitrix and build the
  bot there.
- **KPI, mobile quotes, Bitrix24 Sign, trade-fair OCR:** all **native Standard**
  features — no custom code; they're configured inside Bitrix24.

## Layout

```
config/config.sample.php   copy to config.php (gitignored) and fill in secrets
db/schema.sql              full reference schema
migrations/                versioned changes applied by migrate.php
migrate.php                migration runner (CLI or ?key=)
bin/scheduler.php          cron: dispatch reminders + campaign batches
public/
  index.php                health check
  campaign.php             create a mass campaign
  webhooks/
    form-intake.php        external lead → Bitrix (req #1)
    bitrix-event.php       Bitrix stage/agent change → automations (#3,#4,#6,#7)
    appointment-intake.php schedule appointment reminders (#5)
src/
  Bootstrap, Config, Db, Event/Log
  Bitrix/Client            REST over inbound webhook
  Bitrix/EventHandler      orchestration on stage/agent change
  Lead/Intake              normalise + create lead + schedule welcome/inactivity
  Tracking/Repo            local mirror for timers
  Reminder/Scheduler       enqueue / cancel / dispatch due reminders
  Reminder/Templates       message copy (WhatsApp + email)
  Notify/TextMeBot         WhatsApp gateway (reused from parking)
  Notify/Mailer            mail() or SMTP
  Notify/Notifier          single send point + messages outbox
  Campaign/Sender          mass WhatsApp/email
```

## Setup

```bash
# 1. clone on the server
git clone https://github.com/ispbillingai/bitrix-glue.git
cd bitrix-glue

# 2. config
cp config/config.sample.php config/config.php
#   edit: db, bitrix.base_url (inbound webhook), secrets, textmebot.api_key, mail

# 3. database
mysql -u root -p < db/schema.sql      # or: create DB then `php migrate.php`
php migrate.php

# 4. cron (every minute)
* * * * * php /var/www/html/bitrix-glue/bin/scheduler.php >> /var/log/glue.log 2>&1
```

Point the web root at `public/` (see `nginx.conf.example`). If you must serve the
project root instead, the root `.htaccess` blocks `config/`, `src/`, etc.

### Bitrix24 wiring

1. **Inbound webhook** — Developer resources → Inbound webhook, scopes `crm`,
   `user`. Put its URL in `config.bitrix.base_url`.
2. **Outbound webhook** — Developer resources → Outbound webhook, handler URL =
   `https://<host>/webhooks/bitrix-event.php?secret=<outbound_secret>`,
   events: `ONCRMLEADUPDATE`, `ONCRMDEALADD`, `ONCRMDEALUPDATE`.
3. Fill the stage/status IDs in `config.bitrix.*` from your pipelines
   (`crm.status.list` lists them).

### Quick test

```bash
# health
curl https://<host>/index.php

# simulate an external lead
curl -X POST "https://<host>/webhooks/form-intake.php?secret=INTAKE_SECRET" \
  -H 'Content-Type: application/json' \
  -d '{"name":"Jane Doe","phone":"+254700000000","email":"jane@example.com","source":"jotform"}'

# run the scheduler once to flush the welcome message
php bin/scheduler.php
```

## Notes

- Every send is logged to `messages`; every decision to `events`. When someone
  asks "why did this go out?", the audit trail answers it.
- Reminders are de-duplicated by `dedupe_key`, so webhook retries never double-send.
- Stage IDs, cadences, offsets and copy are all config/`Templates` — tuning them
  needs no code change.
