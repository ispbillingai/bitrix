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
  their own software. Documented contract, Bearer token, retry-safe via an
  optional `external_id`. Only a contact (phone or email) is required; every lead
  lands in the `website` source category. Spec to hand an integrator:
  [docs/lead-api.md](docs/lead-api.md) ([italiano](docs/lead-api.it.md)).
- **Leads / Pipeline**: kanban boards, drag a card to change stage. Assigning a
  lead to a seller messages the customer that seller's profile. Convert a lead to
  a deal; the deal pipeline runs the signing/closing automations.
- **Appointments**: requests come in → staff assign a seller and confirm a time →
  reminders fire to **both** parties before the event.
- **Tasks + KPI**: assign work to sellers, score on completion, leaderboard.
- **Returning customers** (`Crm\LeadCustomers`): a customer who bought one
  product often comes back for another. That request is a lead like any other,
  opened on the customer's registry card — same partita IVA, or the phone/email
  of exactly one card — so it is in the customer's history ("Richieste (lead)"
  on their page) and on the board marked **Già cliente**, where an administrator
  assigns it an agent. Every administrator is alerted with the link to the lead:
  for a new lead, for a request added to the lead they already have open, and
  for a phone/email that sits on several cards (then the lead gets a contact of
  its own and says "forse già cliente"; one click links it). The office can
  still move a lead's request into the customer's chat and close it as
  `customer`; leads are never deleted.
- **Quotes** (`views/quotes.php`): a seller asks the back office to price
  something and the finished quote comes back down the same wire. Two doors, one
  queue: a **Request quote** button inside the lead record (the lead already
  carries the company and the legal details, so the seller writes only what the
  quote must contain), and a **from-scratch form** for a customer the CRM has
  never seen — matched on company/VAT or phone/name, and a new lead is created
  and the request filed on it when nothing matches. The office is notified
  and **builds** the quote on the request: items drawn from the warehouse
  (priced from the list, stock shown), services such as a support contract,
  a discount per line and on the whole quote. The CRM generates the PDF and
  the seller sends it through the signing flow, so it can be read *and signed*
  on the spot. **Stock is drawn when the customer signs** — once only, never
  when the quote is merely built or sent. Uploading a finished PDF remains an
  alternative. Before it goes out the seller can **open the PDF** and, if
  something is wrong, **send it back with a change request**: the quote turns
  *Change requested*, the office is told (WhatsApp + email, admins only) and
  finds the note above the lines in the builder, and regenerating puts it back
  to *Ready* and tells the seller. The version sent back cannot go to the
  customer.
- **Partner area** (`public/partner.php`): partners log into their own page —
  not the CRM — and do exactly three things. They **enter their own leads**
  (typed in, or brought in by sharing their `request.php?ref=CODE` link); they
  see the **status** of those leads and nothing else about them (new, contacted,
  qualified, closed, lost — no seller, no deal value, no internal stage codes);
  and they follow their commissions. The status climbs as the office works the
  lead, and a lead reaching the **converted** stage reads as **closed** — that
  is the finish line here. They are **messaged only when a lead ends** — closed
  or lost — and never on the rungs in between. Entering a partita IVA reserves
  that customer for them for 90 days; re-typing a customer who is already in the
  system is **refused** — the note is filed on the existing lead, but no new
  referral is created and ownership never transfers, and the partner is told
  exactly that rather than thanked for a lead that does not exist. Each lead
  shows its **zone** and when its status last moved.
- **Warehouse** (`views/articles.php`): the gestionale’s ARTICO catalogue —
  ~7,600 articles with prices, VAT, shelf, supplier and stock — searchable by
  code, barcode, description, supplier code or shelf, with filters for in
  stock / negative / on order / serial-tracked. Products can be **added, edited and removed**, and **stock can be loaded,
  unloaded or counted** - every change recorded as a movement. Ownership is
  split so the 15-minute import never undoes it: editing a product takes over
  its data, correcting a quantity takes over only its stock (prices keep
  following the gestionale), and a removed gestionale product is hidden rather
  than deleted. A **minimum stock** per product sends the office one restock
  alert when it is crossed. Cost price, margin and stock value are admin-only;
  sellers see list and sale prices and availability.
- **Price lists** (`views/pricelists.php`): sales price lists the office builds
  from the warehouse and the agents browse as a shop-style catalogue — photo,
  code, description, price, availability — with a **PDF** to download or print.
  A tick box per list on each product's record (or a bulk picker per list)
  decides what is in it; a list prices from LISTINO or price list 4, marked up
  or down, net or VAT included, with an optional own price per product. The
  product sheet (photos, documents, long text, and the link the photo opens) is
  kept in the CRM, so the import never clears it. Agents see only the lists the
  office has made visible.
- **Documents (electronic signature)**: upload a PDF, send it for signature, the
  customer confirms with a one-time code, and the CRM seals the result itself —
  a CAdES-signed PDF holding a signature certificate plus the original document,
  backed by an append-only, hash-chained operation log. No SaaS, no per-document
  fee. Runs on a self-issued certificate out of the box; drop in a qualified
  (eIDAS) one to add external accreditation. Anyone can check a signature at
  `/verify.php`. Full detail: [docs/signing.md](docs/signing.md).
- **Payments (SmallPay)**: charge a customer's card from the CRM — once, in
  instalments, or every month for a support contract. SmallPay holds the mandate
  and does the collecting on its own cashier page (card, PayPal, …), so card
  details never touch this server; the CRM keeps the answer to *"is this customer
  still paying?"* and says so the moment a charge is refused. Off until
  configured. Full detail: [docs/smallpay.md](docs/smallpay.md).
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
| Partner enters their own leads, sees only their status, hears only about closed/lost | `partner.php` → `Partner\Partners::submitLead` / `::outcome` / `::notifyOutcome` → `partner_lead_won`/`partner_lead_lost` |
| Seller asks the back office for a quote; office builds it from the warehouse (PDF generated); seller sends it; signing draws the stock | `views/leads.php` (in-lead button) + `views/quotes.php` (from scratch) → `Crm\QuoteRequests` → `Sign\Documents` |
| Manual interrupt / silence any automation | move the record's stage; pending reminders auto-cancel |
| Mass WhatsApp/email marketing | `campaign.php` + `Campaign\Sender` (throttled) |
| Sign a document with an OTP, in-house | `views/documents.php` → `Sign\Documents` → `Sign\Signer` (CAdES) → `public/sign.php` / `public/verify.php` |
| Charge a card / monthly support contract | `views/payments.php` → `Pay\Contracts` → `Pay\SmallPay` → `webhooks/smallpay-status.php` |
| Warehouse: the gestionale catalogue plus CRM products, editable stock, restock alerts | `bin/import-articoli.php` (cron) → `Crm\ArticleImport` → `Crm\Articles` → `views/articles.php` |
| Sales price lists for agents: per-list flag on each product, shop-style catalogue, photo links to more information, PDF export / print | `Crm\PriceLists` + `Crm\ArticleMedia` → `views/pricelists.php` (+ the product's record in `views/articles.php`) → `Crm\PriceListPdf` (`?plpdf=`) |
| Bitrix24 sync | **optional** `Sync\BitrixSync`, off by default |

## Layout

```
config/config.sample.php   copy to config.php (gitignored); only `db` is required here
db/schema.sql              full reference schema (+ seed pipelines)
migrations/                versioned changes applied by migrate.php (005–011 add the CRM + portal + tickets;
                           025 adds document signing)
storage/sign/              originals, sealed PDFs and the signing key (gitignored, above the web root)
migrate.php                migration runner (CLI or ?key=)
bin/scheduler.php          cron: dispatch due reminders + campaign batches
bin/pay-sync.php           SmallPay: --check the credentials, or refresh contracts now
lang/  en.php it.php        customer message copy (WhatsApp + email)
lang/  ui.en.php ui.it.php  dashboard UI strings
public/
  index.php                health check
  request.php              public customer request form
  portal.php               customer portal (magic-link/password login; view
                           estimate + order status; sign the contract via OTP)
  pay-return.php           where SmallPay sends the customer after the cashier page
  sign.php                 tokenised signing page (read → OTP → sealed PDF)
  verify.php               public signature check (by reference or by file)
  partner.php              partner area (enter leads, their status, commissions)
  dashboard.php            CRM control panel (controller; renders /views)
  campaign.php             create a mass campaign
  webhooks/
    lead.php               partner lead API (documented, Bearer, retry-safe)
    form-intake.php        website/Jotform lead → Crm\Leads
    appointment-intake.php appointment request → Crm\Appointments
    bitrix-event.php       optional inbound (guarded by sync flag)
    smallpay-status.php    SmallPay payment status callback (hashPass-verified)
views/                     dashboard page partials (overview, leads, deals, …)
src/
  Bootstrap, Config, Db, Settings, Auth, Event/Log
  Crm/   Pipelines, Contacts, Leads, LeadIntake, Deals, Appointments, Tasks,
         Tickets, QuoteRequests, Automation, Activities, EntityResolver
         — the CRM domain
  Partner/ Partners (accounts, lead entry, referrals, commissions, outcome notice)
  Pay/     SmallPay (REST client), Contracts (lifecycle + reconciliation)
  Portal/  Account (customer login + magic link), Otp (signing codes)
  Sign/    Documents (lifecycle), Audit (hash-chained log), Signer + Pdf (sealed
           certificate), Cms + Asn1 (CAdES), Certificate, Timestamp, Verify
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
  -d '{"name":"Mario Rossi","phone":"+393331234567","email":"mario@example.com"}'

# flush the queue (welcome message, etc.)
php bin/scheduler.php
```

## Team chat + AI assistant

**Chat team** (sidebar, every role): staff talk to each other inside the CRM —
one-to-one or in named groups — with the same bubbles as the customer chat:
text, a file, a voice note (🎤) or a video (🎥, the phone's camera). Files live
in `storage/uploads/team` and are served only through `?tdl=` to members of the
chat. Unread counts sit on the sidebar entry. Tables: migration 055
(`team_chats`, `team_chat_members`, `team_messages`); code in `src/Team/Chat.php`
and `views/team.php`.

**Assistente AI** is pinned at the top of that list. It runs on Claude through
the official PHP SDK (`anthropic-ai/sdk`, `vendor/` is committed so a plain
`git pull` deploys it) and answers from the CRM through `src/Ai/Tools.php`:
customers, leads, deals, tickets, tasks, appointments, pipeline and invoice
reports — always inside the asker's own scope (an agent's assistant sees the
agent's records, a technician's its customers and tickets, an admin's all).
Anything that changes the CRM or reaches a customer (task, note, message,
WhatsApp, ticket status, stage move, lead edit/create, appointment) is only
*proposed*: it shows as a card with **Conferma / Annulla** and runs when the
person confirms. Set the Anthropic API key in Settings → Assistente AI and use
"Prova assistente" to check it. Usage is billed to that key.

## Commission statements (Provvigioni)

**Provvigioni** (sidebar, admins) is where the office pays partners and agents.
The secretary picks the payee, types the amount (for example 900,00), a title
and period, and attaches the calculation (PDF or spreadsheet). For a partner the
statement can also take in the commissions the CRM accrued on their won deals.
The payee gets a WhatsApp message and an email. They find the statement in
their own area: **Provvigioni** in the partner area (`partner.php`), **Le mie
provvigioni** for an agent. There they download the calculation and upload
their invoice (PDF, e-invoice XML/.p7m or a photo, with number, date and amount).

The office is told when an invoice comes in. It then records the payment (date,
amount, bank reference) or sends the invoice back with a reason, and the payee
is told either way. An invoice that arrived by email can be recorded by the
office for the payee. Both sides keep the full history, paid and unpaid.

Statuses run `sent` → `invoiced` → `paid`, or `cancelled` before payment. Files
live in `storage/uploads/commissions` and are served only through `?cmf=`, to
the office and to the payee. Tables: migration 056 (`commission_statements`,
plus `partner_accruals.statement_id`). Code: `src/Commission/Statements.php`,
`views/commissions.php`, `views/my_commissions.php`. The four notices are
editable under Templates (`commission_*`).

## Lead documents and financing applications

Inside a lead, **Documenti e finanziamento** does two things. **Carica documenti
cliente** files the customer's general paperwork on the lead, and the office is
told that it arrived, with a link to the customer's folder. **Apri pratica di
finanziamento** opens the application: it gets its own link, and that link is
the checklist. Every required document has its own row and its own upload
button, so the seller uploads what they have, and returns to the same link when
the rest arrives. The link works on a phone and can be forwarded to the customer.

The required documents are a list in **Settings → Finanziamenti**, one per line:
`code|Label|1 if required|per_lender`. The privacy form is marked `per_lender`,
because it is the one document that differs between lenders: choosing the
lenders an application is aimed at turns that single row into one row per
lender.

**Finanziamenti** (sidebar, admins) is the office side. Applications waiting to
be checked come first, and the number on the sidebar counts them. Opening one
shows the folder: the checklist with every file, the customer's general
documents, the amount and purpose, and the lenders. The office marks it checked,
then generates a link for each lender. Each lender's page shows the same
dossier, that lender's privacy form and no other's, with a zip of everything
where the server has ext-zip. Opens are counted, and a link can be switched off.

Files live in `storage/uploads/lead-docs`, never in the web root: the dashboard
serves them through `?ldl=` (the office all of them, a seller only their own
leads, a technician none), the application's link through `pratica.php`, and a
lender through `finanziaria.php`. Tables: migration 058 (`lead_files`,
`finance_apps`, `finance_lenders`, `finance_shares`). Code:
`src/Finance/Docs.php`, `views/finance.php`, `public/pratica.php`,
`public/finanziaria.php`. The office alerts are queued like every other staff
alert (`src/Notify/StaffAlert.php`).

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
