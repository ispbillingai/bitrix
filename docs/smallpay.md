# SmallPay payments

Charging a customer's card from the CRM: once, in instalments, or every month
for a support contract.

The business case this was built for: a deal closes (the customer buys a
machine), they then sign a support contract, and from that moment MichaelTech
charges them monthly. If the card stops paying, the CRM has to know — because
that is what decides whether the service keeps running.

**SmallPay holds the mandate and the money. This CRM holds the answer to "is
this customer still paying?"** Card details never reach this server: the
customer types them on SmallPay's own cashier page, which also offers PayPal and
whatever else SmallPay supports that day. Which button they press is SmallPay's
business, not ours.

- Spec: `20240304_SMALLPAY_Specifiche_API_integrazione_LYNX_v3.14.pdf` (repo root)
- Provider support: smallpay@lynxspa.com
- Portal: SmallPay Market (Anagrafica / Servizi pages hold the credentials)

## Setting it up

Four identifiers, all from the SmallPay Market portal, entered in
**Settings → SmallPay payments**:

| Setting | Where it comes from |
|---|---|
| Merchant id (`id_merchant`) | Registry (*Anagrafica*) → **Id** |
| Unique id (`unique_id`) | Registry → **Unique id**. **The shared secret**, see below |
| Service id (`service_id`) | Services (*Servizi*) → column **Crm service id** |
| Domain (`domain`) | **Ours to choose.** Not issued by SmallPay |

`domain` is caller-defined — SmallPay's own button snippet says so: *"domain -
Identificativo del sito (definito dal chiamante)"*. It is just the container our
paymentIds are unique within. Set once, then never changed.

### The MichaelTech account (verified 2026-07-31)

Merchant id `3050`, domain `michaeltech`. Both authenticate: a §3.2 read signed
with them gets past authentication and fails only on the payment id not
existing, which is the expected answer for a payment id that does not exist.

The associated service is type **Open**, duration 12, €24.90 + IVA, and its
gateway is **Nexi – SDD** — SEPA direct debit off a bank account, *not* a card.
That is why the customer-facing copy in `lang/*.php` never names the instrument:
a failed collection here is insufficient funds or a lapsed mandate, not an
expired card. If a card gateway is added later ("Manage gateways"), the copy
still reads correctly.

Then:

1. Leave **Environment** on *Test (staging)* and **Enable** off.
2. Press **Test SmallPay** in Settings, or run `php bin/pay-sync.php --check`.
   This calls `checkSellConfigs` (§3.3), which validates merchant + service +
   gateway and creates nothing. It is the only SmallPay call that is safe to
   fire at a live account out of curiosity. **But see the §3.3 caveat below —
   on this account it cannot currently pass.**
3. Switch **Enable** on and rehearse a full contract on staging.
4. Only then move Environment to *Live*.

**Never change `domain` once contracts exist.** Positions already filed live
under it; a new domain makes them unreachable and their `paymentId`s free to be
reused, which is how you end up charging the wrong person.

## How authentication works

There is no token. Every call carries `idMerchant` plus a `hashPass`:

```
sha1('paymentId=' + paymentId + 'domain=' + domain
     + 'serviceSmallpay=' + service + 'uniqueId=' + uniqueId)
```

The shape is fixed; the **content is per-endpoint**. A field the call does not
use appears in the hash as an empty value, not omitted:

| Call | paymentId | serviceSmallpay |
|---|---|---|
| Create position (§3.1) | the reference | the service id |
| Read position (§3.2) | the reference | *empty* |
| Check config (§3.3), retry/cash/delete/cancel (§3.5–3.9) | *empty* | the service id |

Getting this wrong returns `Unauthorized: Hash generation error`.
`SmallPay::signature()` therefore takes both fields explicitly rather than
defaulting to "whatever we have", and `scratchpad`-style checks of all four
formulas live in the class docblock.

Answers and callbacks are signed the other way round — `timestamp` in place of
`serviceSmallpay` — which is what `verifyResponse()` checks.

`uniqueId` is never transmitted, only hashed. Nothing in this codebase logs it
or puts it in an exception message. Treat it like a password.

## The model

One **contract** = one SmallPay position. Three kinds:

| Kind | SmallPay | Ends when |
|---|---|---|
| `subscription` | `flagFlexpay: true`, `totalRecurrences: 0` | someone cancels it |
| `installments` | `totalRecurrences: N` | the last rate is collected |
| `one_off` | whole amount as `firstPaymentAmount` | it is paid |

A support contract is the `subscription` kind. Cancelling it calls
`perpetualUnSubscribe` (§3.9): SmallPay stops charging from that moment.
Rates already taken are not refunded.

Each attempted charge is a row in `payment_charges`, keyed on SmallPay's
`installmentId`, so "he paid in March but not in April" is a query rather than
an API call.

### Statuses

SmallPay only ever says `IN ATTIVAZIONE` / `ATTIVO` / `NON ATTIVO` — and it is
talking about the **first** payment, not the months since. The two states the
business actually acts on are derived here from the rates:

- **`past_due`** — active, but a rate came back `INSOLUTO` and has not been
  recovered. This is the "stop the service" signal.
- **`completed`** — a fixed plan with nothing left to collect. A subscription
  never reaches it; there is always a next month.

## Staying in sync

Two mechanisms, and both are needed:

1. **The status callback** (§3.4) — `public/webhooks/smallpay-status.php`.
   SmallPay POSTs it on every change. Fast, but a callback that never arrives
   would leave a defaulting customer looking paid.
2. **The periodic pull** — `Contracts::syncIfDue()`, run by `bin/scheduler.php`
   every `smallpay.sync_minutes`. Late, but it cannot silently not-happen.

Both land in `Contracts::applyPayload()`, which is idempotent by construction:
rates are upserted on their `installmentId` and every roll-up is recomputed from
the rate rows rather than incremented. Replay the same payload ten times and the
contract lands in the same place.

The callback URL is public — SmallPay has to be able to reach it — so it is
treated as hostile input. An unsigned or wrongly signed body is recorded in
`payment_events` and dropped. Every inbound body is stored under a UNIQUE
`event_key` before anything acts on it; that is both the audit trail and the
idempotency guard, so a redelivered "rate unpaid" cannot chase the customer
twice.

## What happens to the customer

| Moment | Who hears about it | Rule key |
|---|---|---|
| Contract opened | customer gets the cashier link | `pay_link` |
| First payment succeeds | customer gets a confirmation | `pay_active` |
| A charge is refused | customer (optional) **and always the seller** | `pay_failed`, `pay_failed_agent` |

These go through the normal reminders queue, so they are paced by the WhatsApp
rate limit, logged in the outbox, and cancelled along with everything else when
the contract is cancelled. Copy lives in `lang/it.php` / `lang/en.php` and is
editable from **Settings → Templates** like any other message.

Messaging the customer about a failure can be switched off
(`smallpay.notify_customer_on_failure`); the seller is told either way, because
that is the signal the business acts on.

## Open question for SmallPay

**The `totalAmount` of an open-ended FlexPay contract.** The spec defines
`totalAmount` as "the total amount of the sale" and `firstPaymentAmount` as the
first transaction — which is unambiguous for a fixed plan (`first + amount × N`)
but says nothing about the perpetual case, where there is no N.

This code sends `first + one monthly quota` and expects SmallPay to repeat that
quota for as long as the mandate lives (`Contracts::file()`). **Confirm this
against a real merchant account before going live**, and check the first
month's collected amount against what was intended. If FlexPay turns out not to
be enabled on the account at all, sell the support contract as `installments`
with 12 / 24 / 36 rates instead — that path is unambiguous in the spec and needs
no code change.

## §3.3 checkSellConfigs looks broken for string service ids

Established against the live account on 2026-07-31, with three calls that create
nothing:

| `serviceSmallpay` sent | SmallPay's answer |
|---|---|
| `ookSYooooo0DkYYg` (the Crm service id) | `500 — For input string: "ookSYooooo0DkYYg"` |
| `1` | `500 — MerchantId non compatibile per Service smallpay richiesto` |
| `3050` | `500 — Service smallpay da recuperare con crmServiceId!` |

The first is a Java `NumberFormatException`: the endpoint parses the field as an
integer. The other two show it then resolving a *numeric internal* service id,
and refusing because this service must be fetched by its `crmServiceId` — the
very string that crashes it. So §3.3 int-parses before it ever reaches the
crmServiceId branch, and no correct value can get through.

That is a provider-side bug, not a configuration problem, and it means **the
Settings connection test cannot go green on this account**. It does not block
anything else: `serviceSmallpay` is only used for real in §3.1, which is a
different endpoint. Validate by creating one small position instead (below), or
ask smallpay@lynxspa.com to fix §3.3.

**Validating without the connection test.** Create one position for a token
amount against a phone/email you control, check the CRM records the cashier URL,
and then **do not complete the payment** — an uncompleted position never takes
money. Cancel it afterwards with §3.9 (the Cancel button) or delete its rates
with §3.8.

A second, smaller ambiguity: the spec prints §3.1–3.3 under `public/api/sites/…`
and §3.5–3.9 under a bare `sites/…`. That reads like a documentation slip, so
`SmallPay::post()` retries a 404 on the alternate prefix. If one prefix turns
out to be right for everything, delete the retry.

## Files

```
migrations/032_payments.sql        payment_contracts, payment_charges, payment_events
src/Pay/SmallPay.php               REST client + the hashPass formulas
src/Pay/Contracts.php              lifecycle, reconciliation, notifications
public/webhooks/smallpay-status.php  §3.4 status callback (signature-checked)
public/pay-return.php              where the customer lands after the cashier page
views/payments.php                 the dashboard page
bin/pay-sync.php                   --check / --id=N / full refresh
```

## Troubleshooting

| Symptom | Cause |
|---|---|
| `Unauthorized: Hash generation error` | `unique_id` wrong, or the wrong per-endpoint hash fields |
| `500 For input string: "<service id>"` | §3.3 only — the provider bug above, not your configuration |
| `Payment number … already exists` | that reference was filed before; the code adopts the existing position instead of opening a second |
| `serviceSmallpay type not accepted` | the service must be of type API, UComm or Ecommerce |
| `aliasGateway not accepted` | no gateway configured for the merchant in the Market portal |
| Contract stuck on *Awaiting payment* | the customer never completed the cashier page — re-send the link, or use **Have SmallPay email a new link** (§3.5) |
| Callbacks rejected in `payment_events` | `note` says why; `bad hashPass` usually means `domain` or `unique_id` does not match what SmallPay has |
