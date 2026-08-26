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

### The MichaelTech account (live since 2026-08-26)

Merchant id `3050`, domain `michaeltech`, service id **`00kSY00000HeG5t`**.
`checkSellConfigs` passes: merchant, service and gateway are all accepted.

**Use the API service, not the Open one.** *Associated services* lists two, and
only the first is drivable from here:

| Crm service id | Type | Duration | Installment | Name |
|---|---|---|---|---|
| `00kSY00000HeG5t` | **API** | 12 | €5.00 + IVA | Chiavi API |
| `00kSY00000DkYYg` | Open | 24 | €24.90 + IVA | Nexi - SDD |

The Open one is the kind sold through the portal's own Sell screen; the API is
refused for it (this is what blocked the integration from 2026-07-30 until
SmallPay provisioned the API service on 2026-08-26 — see *How it was unblocked*
below). Its €5.00 × 12 is SmallPay's own charge for API access, **not** a
template for what customers pay: `createPosition` carries its own
`totalAmount` / `firstPaymentAmount` / `totalRecurrences`, so a €24.90 support
contract is filed as €24.90 against this service.

The account's gateway list holds three, all ATTIVO: **NEXI**, **STRIPE** and
**STRIPE_SDD** — so both card and SEPA direct-debit channels exist, and which
one collects depends on the service.

That is why the customer-facing copy in `lang/*.php` never names the payment
instrument. "Your card has expired" is wrong for an SDD collection and "the
mandate lapsed" is wrong for a card, so the copy says neither: a refusal reads
as insufficient funds or a lapsed mandate, which is true of both and survives
the gateway being changed.

Then:

1. Leave **Environment** on *Test (staging)* and **Enable** off.
2. Press **Test SmallPay** in Settings, or run `php bin/pay-sync.php --check`.
   This calls `checkSellConfigs` (§3.3), which validates merchant + service +
   gateway and creates nothing. It is the only SmallPay call that is safe to
   fire at a live account out of curiosity.
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
quota for as long as the mandate lives (`Contracts::file()`). **Still unanswered
as of 2026-08-26** — it could not be tested while the account had no API service,
and no position has been filed since. So: file the first real contract, then
`php bin/pay-sync.php --id=N` and read the planned schedule back before letting
a customer near it. If FlexPay turns out not to be enabled on the account at
all, sell the support contract as `installments` with 12 / 24 / 36 rates
instead — that path is unambiguous in the spec and needs no code change.

The same first position answers a second unknown: the API service carries
**duration 12**, and it is not documented whether that caps the rate count of
positions filed against it. A 24- or 36-month plan may be refused. Nothing to
do about it until a position proves it either way.

## How it was unblocked (2026-07-30 → 2026-08-26)

For four weeks every sale call bounced. The credentials were right the whole
time; the account simply had nothing the API was allowed to sell through.
Tested live on 2026-07-31 against the only service then associated,
`00kSY00000DkYYg`:

| Call | SmallPay's answer |
|---|---|
| §3.3 checkSellConfigs | `400 — serviceSmallpay not accepted for merchant: 3050` |
| §3.1 create position, FlexPay | `400 — serviceSmallpay type not accepted` |
| §3.1 create position, 12 rates | `400 — serviceSmallpay type not accepted` |

The spec's own error table explains it: *"Il serviceSmallpay in request deve
essere uno dei seguenti tipi: **API, UComm, Ecommerce**"*. That service is type
**Open** — the kind sold through the portal's own Sell screen, not through an
integration.

Nothing in the portal could fix it: *Services → Available services* was empty,
*SmallPay Integration* is only the JS button configurator plus the WooCommerce
and PrestaShop plugins, and the API's nine operations are all payment
operations — none creates a service. Only SmallPay could, and did: asked
through *Require support*, they provisioned **Chiavi API** (type API, 12 ×
€5.00 + IVA) on **2026-08-26**, announced in the portal as *"The API service has
been activated. The invoice will be visible within the next 48 hours."*

The lesson, if this recurs on another merchant: the activation creates a
**second, separate row** in *Associated services* with its own *Crm service id*.
It does not convert the existing service, and nothing changes on our side until
that new id is pasted into Settings → SmallPay. `checkSellConfigs` went green
the moment it was.

### An earlier wrong conclusion, for the record

Before the exact service id was to hand, a mistyped one (`o` for `0`) made §3.3
answer `500 — For input string: …`, a Java `NumberFormatException`, and numeric
probes answered `MerchantId non compatibile` and `Service smallpay da recuperare
con crmServiceId!`. That was read here as a provider bug in §3.3. It is not:
with the correct id the same endpoint answers a clean 400. An unknown service id
crashes it, which is ugly, but it validates fine. Do not go looking for that bug.

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
| `serviceSmallpay type not accepted` | the service is not type API / UComm / Ecommerce — you are pointed at the Open service, not the API one |
| `serviceSmallpay not accepted for merchant: N` | same cause, as §3.3 words it |
| `500 For input string: "<service id>"` | the service id does not exist — check for `o` vs `0` |
| `Payment number … already exists` | that reference was filed before; the code adopts the existing position instead of opening a second |
| `serviceSmallpay type not accepted` | the service must be of type API, UComm or Ecommerce |
| `aliasGateway not accepted` | no gateway configured for the merchant in the Market portal |
| Contract stuck on *Awaiting payment* | the customer never completed the cashier page — re-send the link, or use **Have SmallPay email a new link** (§3.5) |
| Callbacks rejected in `payment_events` | `note` says why; `bad hashPass` usually means `domain` or `unique_id` does not match what SmallPay has |
