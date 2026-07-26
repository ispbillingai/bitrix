# Mail intake — leads that arrive by email

The website form emails its notifications to the company mailbox
(`Info@michaeltech.it` on Aruba). The CRM polls that mailbox and turns each new
form email into a lead through the same [`LeadIntake`](../src/Crm/LeadIntake.php)
as the webhooks — identical de-duplication, welcome automation and pipeline
placement. Importer: [`src/Mail/LeadMailImporter.php`](../src/Mail/LeadMailImporter.php),
run by [`bin/scheduler.php`](../bin/scheduler.php) on the `leads_mailbox.poll_minutes`
cadence (default 5).

## Why POP3, not IMAP

The Aruba plan for this mailbox includes webmail, SMTP and POP3 — **IMAP is not
provisioned** (it always answers `AUTHENTICATIONFAILED`, even with the correct
password; SMTP and POP3 accept the same credentials). POP3 has no server-side
read flags and the inbox must stay untouched for staff, so processed messages
are remembered in the `mail_intake` table (migration 029), keyed on a hash of
each message's Message-ID. Nothing is ever deleted or marked in the mailbox.

## Behavior

- **First poll = baseline.** With an empty `mail_intake` table, everything
  already in the mailbox is recorded as `baseline` and nothing is imported —
  old correspondence must not become leads.
- **Parsing.** `Label: value` lines in the body (plain-text part preferred,
  HTML de-tagged otherwise) are fed through `LeadIntake::normalize()`, which
  already accepts Italian form field names (`nome`, `telefono`, `messaggio`…).
  The subject becomes the title when the body names none. If no phone/email is
  found in the body, the sender's own address is used
  (`fallback_sender_email`) — a real person writing in directly *is* the lead.
- **Filters.** `blocked_from` (substring) drops system/notification senders;
  `allowed_from` (addresses or domains), once set, drops everything else. An
  explicit allow beats the blocks — Cashmatic's summaries come from a
  `noreply@` address that the block list would otherwise eat. A message with
  no extractable contact is skipped as `no_contact`.
- **Cashmatic template.** The real leads are Cashmatic CRM "Riepilogo Lead"
  summaries (usually forwarded by the partner from
  `berkelincampania@gmail.com`). Their fields arrive as a table — "Label
  value" with no separator — so a dedicated pass in
  `LeadMailImporter::cashmaticPairs()` splits on the template's known label
  set: Nome Contatto → name, Azienda → company, Indirizzo → zone,
  Email/Telefono → contact; Fonte, Sistema di cassa, Fornitore and Note are
  kept together in the lead's comments.
- **Retry safety.** The message hash is the lead's `external_id`
  (`mail:<sha1>`), so re-polls and re-deliveries map back to the lead already
  created instead of duplicating it.
- Every decision lands in `mail_intake.status` (`imported` / `skipped` /
  `error` / `baseline`) with the rule that fired in `reason` — check there
  first when a mail "didn't become a lead".

## Tuning when the real form goes live

1. Send one lead through the form, then check `mail_intake` and the created
   lead's fields.
2. If the form's field labels don't map cleanly, extend the alias table in
   `LeadIntake::ALIASES` (shared with the webhooks) or adjust
   `LeadMailImporter::parse()` — it is public and takes plain strings, so a
   saved sample can be re-tested without a mailbox.
3. Lock `allowed_from` to the form's sender address so stray mail can't create
   leads.

## Requirements

`php-imap` extension (`apt-get install php-imap`) — already installed on the
live server. Credentials live in `config/config.php` → `leads_mailbox` (or the
dashboard Settings overlay), never in git.
