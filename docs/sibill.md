# Sibill — has the customer paid?

The accounting lives in Sibill. The CRM does not duplicate it and does not touch
it: it reads the issued invoices and their payment schedule, keeps a local copy,
and shows the answer next to the customer.

**Read-only, deliberately.** The token Sibill issues can also create documents,
and `POST /documents/invoice` files a real electronic invoice with the SDI. There
is no sandbox — the development host rejects production tokens — so nothing in
this app ever writes to Sibill. Keep it that way unless someone asks for issuing
and accepts that the first test is a real invoice.

---

## What "paid" means here

Sibill does not store a paid flag on an invoice. It stores **flows** (*scadenze*)
— one per instalment, each with an amount, a due date, a method and a status of
`PAID` or `TO_PAY`. A single invoice can be one flow or five.

So payment state is derived:

| all flows `PAID` | **paid** |
|---|---|
| some flows `PAID` | **part-paid** — `open_amount` is what is left |
| no flows `PAID` | **unpaid** |
| no flows at all | **no schedule** — Sibill holds no payment plan; the full total is counted as still owed, because claiming it was paid would be a guess |

*Overdue* is not a Sibill state. It is the earliest unpaid instalment being in the
past, computed locally so it stays true as days go by without a re-sync.

One wrinkle worth knowing, because the field names invite the opposite reading:

```
payment_date           →  when the instalment falls DUE
expected_payment_date  →  when it actually SETTLED (only once the flow is PAID)
```

The API reference describes both as the due date. Live data settles it: for
`TO_PAY` flows the two are identical, and for `PAID` flows `expected_payment_date`
moves to either side of the due date — which is what a real settlement does. The
mirror stores them as `due_date` and `settled_date` so the code reads correctly.
If Sibill's consultants say otherwise, `Invoices::rollUp()` is the one place to
change it.

---

## How the sync works

```
scheduler.php (every minute)
        │  every sibill.sync_minutes, at most
        ▼
GET /companies/{id}/documents?filter[direction]=ISSUED&expand=flows,counterpart
        │  100 per page, cursor-paged, newest first
        ▼
sibill_invoices  +  sibill_flows        (rewritten, not merged)
        │
        ▼
matched to CRM contacts/deals by VAT number
```

It re-reads **everything** each time rather than only what changed. That is not
laziness: the API cannot filter by payment status and has no date-range operator,
and the interesting event — an eighteen-month-old invoice finally being paid —
would be invisible to an incremental sync. At a few hundred invoices this is
about seven requests, so a full walk every 30 minutes costs nothing. Set
`sibill.sync_months` if the history ever grows enough to matter.

A row is deleted only after a **complete** walk that did not see it upstream. A
run cut short by the history horizon never prunes.

### Matching to the CRM

By VAT number, against `leads.vat_number` (stored normalised — uppercase,
alphanumeric only). Sibill holds Italian VATs without the `IT` prefix and agents
type them both ways, so both forms are tried. Failing that, an *unambiguous*
company-name hit on `contacts.company` — one match, or none at all.

Most invoices will not match anything, and that is fine: a company invoices
plenty of customers that never went through the sales pipeline. The page shows
them regardless; the CRM link is a bonus, not a filter.

Run `php bin/sibill-sync.php --relink` after a bulk import of leads to re-match
without re-reading Sibill.

---

## Setting it up

1. **Settings → Sibill**: paste the API token, leave the company id blank.
2. Hit **Test Sibill**. It lists the companies the token can see and, when there
   is exactly one, saves its id for you.
3. Tick **Keep invoices in sync**, save.
4. First import: `php bin/sibill-sync.php` (or **Refresh now** on the Invoices
   page). A few hundred invoices take a few seconds.

The scheduler takes it from there. Nothing else needs a cron entry — it rides on
the existing per-minute `bin/scheduler.php`.

### Config

| key | default | what it does |
|---|---|---|
| `sibill.enabled` | `false` | lets the scheduler sync; manual refresh works regardless |
| `sibill.api_key` | — | bearer token |
| `sibill.company_id` | — | uuid; the connection test fills it in |
| `sibill.base_url` | `https://integration.sibill.com` | production |
| `sibill.sync_minutes` | `30` | scheduler cadence |
| `sibill.sync_months` | `0` | `0` = full history |

The token is a credential: it belongs in `config/config.php` (gitignored) or the
`settings` table, never in `config.sample.php`. It reads the client's real
invoices, counterparts and **bank balances**, so treat a leaked one as urgent —
ask Sibill to rotate it.

---

## Files

| | |
|---|---|
| `src/Sibill/Client.php` | HTTP: bearer auth, cursor paging, error text |
| `src/Sibill/Invoices.php` | sync, payment-state derivation, CRM matching, reads |
| `views/invoices.php` | the staff page — overdue first |
| `bin/sibill-sync.php` | manual/first import, `--months`, `--relink` |
| `migrations/027_sibill_invoices.sql` | `sibill_invoices`, `sibill_flows` |

---

## What is not built

- **Overdue reminders.** The reminder engine could chase unpaid invoices on a
  cadence the way it chases unsigned quotes — the data is all here (`due_date`,
  `open_amount`, the resolved contact). It is a rule and a template away, but no
  one has asked for it yet, and automatically messaging customers about money is
  not a thing to switch on unasked.
- **Issuing invoices.** See the top of this document.
- **Webhooks.** Sibill mentions document/flow webhooks in its use-case notes but
  the reference publishes no endpoint for registering one. If their consultants
  can wire one up, it would replace the polling above; until then the walk is
  cheap enough that it does not matter.
- **Received invoices** (supplier bills). The sync filters to `ISSUED` — money
  owed *to* the company. `direction` is already a column if that changes.
