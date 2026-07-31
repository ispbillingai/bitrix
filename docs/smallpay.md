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

Merchant id `3050`, domain `michaeltech`, service id `00kSY00000DkYYg`. The
first two authenticate. The third is a real service but of a type the API may
not sell through — see **BLOCKED** below, which is the one thing still in the
way.

The associated service is type **Open**, duration 12, €24.90 + IVA, listed
against a gateway named *Nexi - SDD*. The account's gateway list holds three,
all ATTIVO: **NEXI**, **STRIPE** and **STRIPE_SDD** — so both card and SEPA
direct-debit channels exist, and which one collects depends on the service.

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
   fire at a live account out of curiosity. **On the MichaelTech account this
   cannot pass yet — see "BLOCKED" below; the service is of the wrong type.**
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

## BLOCKED: the account has no service the API may drive

Tested live on 2026-07-31 with the correct service id `00kSY00000DkYYg`:

| Call | SmallPay's answer |
|---|---|
| §3.3 checkSellConfigs | `400 — serviceSmallpay not accepted for merchant: 3050` |
| §3.1 create position, FlexPay | `400 — serviceSmallpay type not accepted` |
| §3.1 create position, 12 rates | `400 — serviceSmallpay type not accepted` |

The spec's own error table explains it: *"Il serviceSmallpay in request deve
essere uno dei seguenti tipi: **API, UComm, Ecommerce**"*. The service on this
account is type **Open** — the kind sold through the portal's own Sell screen,
not through an integration. So the credentials are right and the code reaches
SmallPay cleanly; there is simply nothing on the account the API is allowed to
sell through.

**Only SmallPay can unblock it.** Every self-service route in the portal was
checked on 2026-07-31 and none of them can create one:

- **Services → Available services** is *empty* ("The search did not return any
  results"), so there is nothing to buy or switch on.
- **SmallPay Integration** is only the JS button configurator plus the
  WooCommerce and PrestaShop plugins — no API toggle.
- **Manage gateways** already lists NEXI, STRIPE and STRIPE_SDD, all ATTIVO, so
  the gateway half of `checkSellConfigs` is satisfied. Gateways are not the
  problem.
- The API itself has no endpoint that creates a service — the spec's nine
  operations are all payment operations.

So: ask SmallPay (*Require support* in the portal, or smallpay@lynxspa.com) to
provision a service of type **API** for merchant 3050, mirroring the existing
one's terms (12 × €24.90 + IVA). Put its *Crm service id* into Settings →
SmallPay and the connection test should go green.

Everything else is already proven: merchant id, unique id and domain all
authenticate, and the two refusals above are business rejections from
SmallPay's application layer, not transport, signature or routing errors.

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
| `serviceSmallpay type not accepted` | the service is not type API / UComm / Ecommerce — see BLOCKED above |
| `serviceSmallpay not accepted for merchant: N` | same cause, as §3.3 words it |
| `500 For input string: "<service id>"` | the service id does not exist — check for `o` vs `0` |
| `Payment number … already exists` | that reference was filed before; the code adopts the existing position instead of opening a second |
| `serviceSmallpay type not accepted` | the service must be of type API, UComm or Ecommerce |
| `aliasGateway not accepted` | no gateway configured for the merchant in the Market portal |
| Contract stuck on *Awaiting payment* | the customer never completed the cashier page — re-send the link, or use **Have SmallPay email a new link** (§3.5) |
| Callbacks rejected in `payment_events` | `note` says why; `bad hashPass` usually means `domain` or `unique_id` does not match what SmallPay has |
