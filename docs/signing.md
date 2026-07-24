# Electronic signature — running your own certification body

This CRM signs customer documents itself. No SaaS, no per-document fee, no third
party holding your contracts. What it costs you: an annual certificate if you
want external accreditation, the WhatsApp/SMS the codes go out on, and optionally
a few cents' worth of time stamps.

This document explains what it actually proves, what it does not, and how to run
it.

---

## What happens when a document is signed

```
agent uploads a PDF ──► SHA-256 recorded ──► link + one-time code to the customer
                                                        │
        customer reads it, ticks consent, enters the code│
                                                        ▼
                                        signature evidence written
                                                        │
                        audit chain head taken ──────────┤
                                                        ▼
                    certificate PDF built  ──►  original embedded inside it
                                                        │
                                                        ▼
                              sealed with a CAdES signature (+ optional RFC 3161)
```

The sealed PDF you end up with contains, in one file:

- a **certificate page** stating what was signed, by whom, how they were
  identified, from which IP, and when;
- the **original document**, byte for byte, as a PDF attachment;
- the **operation log**, printed, with its chain hash;
- a **CAdES-BES signature** (`/ETSI.CAdES.detached`) covering all of the above.

Change one byte of any of it and the signature stops verifying. That check needs
nothing from us: the signing certificate travels inside the file.

---

## What this proves, and what it does not

**It does prove** the document has not changed since it was sealed, that a
one-time code sent to a specific phone/email was entered correctly from a
specific IP at a specific moment, and that the operation log has not been
rewritten since.

**On its own it does not prove** *who* the person holding that phone was, or
*when* the sealing happened in any sense a stranger has to accept — because we
own the key and we own the clock. That is the honest limit of doing this
in-house, and it is worth being precise about, because it is exactly what an
opposing lawyer will go after.

Two things close that gap, and both keep everything else on your server:

| Gap | What closes it | What leaves your server |
|---|---|---|
| "You could have signed this yourself, later" | A **qualified (eIDAS) certificate** from a trust service provider | Nothing — you install their certificate here |
| "You could have back-dated it" | An **RFC 3161 time stamp** | Only a SHA-256 hash of the signature |

Under eIDAS this is an **advanced electronic signature** when it runs on a
qualified certificate. It is *not* a qualified electronic signature (QES) — that
needs the signing key held in a certified device by the signer themselves, not by
you. For most commercial contracts an advanced signature plus a solid audit trail
is what is actually used; for anything that must be QES by law (certain notarial
and public-administration acts in Italy), this is not a substitute.

---

## Setting it up

### 1. Run the migration

```bash
git pull && php migrate.php
```

Migration `025_sign_documents.sql` creates the three tables and, importantly,
two triggers that make `sign_audit` reject `UPDATE` and `DELETE` at the database
level. If the migration fails on the triggers, your MySQL user lacks the
`TRIGGER` privilege:

```sql
GRANT TRIGGER ON <database>.* TO '<user>'@'<host>';
```

Grant it and re-run. Do not skip the triggers — "the log must not be editable" is
the point of the whole feature.

### 2. Check the storage folder

Originals, sealed PDFs and the key pair go in `storage/sign/` above the web root.
It must be writable by the web user:

```bash
mkdir -p storage/sign && chown -R www-data:www-data storage/sign && chmod 750 storage/sign
```

If `storage/` is not writable the app falls back to `public/uploads/sign/`, which
`.htaccess` blocks — it works, but above the web root is better.

### 3. The certificate

With nothing configured, the first signature generates a 3072-bit key and a
self-signed certificate in `storage/sign/keys/`, valid for 10 years. The
Documents page shows a **Self-issued** badge, and every certificate PDF carries a
notice saying so. Signatures are real and tamper-evident from day one.

To switch to a qualified certificate, buy one and put it in `config/config.php`:

```php
'sign' => [
    'pkcs12_path' => '/etc/crm/signing.p12',
    'pkcs12_pass' => '…',
],
```

or as PEM:

```php
'sign' => [
    'cert_path'  => '/etc/crm/signing.crt',
    'key_path'   => '/etc/crm/signing.key',
    'chain_path' => '/etc/crm/chain.pem',
],
```

Nothing else changes. Documents signed before the switch keep verifying against
the certificate embedded in them; each document records which certificate sealed
it (subject, serial, SHA-256 fingerprint).

**Guard the private key.** It is written `0600` and gitignored. Anyone who copies
it can forge every signature you have ever issued and every one you will. Back it
up somewhere you would be comfortable backing up your company stamp.

### 4. Time stamping (recommended)

```php
'sign' => [
    'tsa_url' => 'https://freetsa.org/tsr',
],
```

Only a hash of the signature is sent. If the TSA is unreachable the document is
still signed — it just records our own clock, and the audit log says the TSA
failed. A commercial TSA with an SLA costs a few euro a year and is worth it if
you expect to rely on these in a dispute.

---

## Using it

**Send a document.** Documents → New document → title, PDF, customer, language.
Leave "Send it to the customer straight away" ticked and they get a WhatsApp and
an email with a link.

**The customer** opens the link (no account needed), reads the PDF, ticks the
consent box, types their name, and gets a 6-digit code by WhatsApp and email.
Enter the code, done. They can also decline, with a reason — which is recorded
just as carefully as a signature.

**Afterwards** the Documents page gives you the sealed copy, the original, the
full operation log, and a live check of whether the log's hash chain is intact.

**Anyone** can verify a signature at `/verify.php` — by reference number, or by
uploading the signed PDF. The upload path checks the file against the certificate
inside it and never consults the database, so its verdict does not depend on
trusting us.

---

## The operation log

Every step writes one row to `sign_audit`. Each row carries the SHA-256 of the
row before it:

```
hash = SHA256( document_id ∥ seq ∥ event ∥ actor ∥ ip ∥ user_agent ∥ data ∥ time ∥ prev_hash )
```

Three layers of protection, each covering the previous one's weakness:

1. **Database triggers** reject `UPDATE` and `DELETE` outright — even from this
   application, even from a DBA session.
2. **The hash chain** means someone who bypasses the triggers (root on the box,
   editing table files directly) cannot do it quietly: any changed, inserted or
   removed row breaks the chain and `Sign\Audit::verify()` reports the exact
   sequence number where.
3. **The chain head is sealed inside the signed PDF.** Rebuilding the whole chain
   to be internally consistent no longer helps, because the rebuilt head will not
   match the value inside a document signed with a key the rebuilder does not
   have.

Deleting test data therefore means dropping the two triggers first:

```sql
DROP TRIGGER sign_audit_no_update;
DROP TRIGGER sign_audit_no_delete;
-- … clean up …
-- then re-create them from migrations/025_sign_documents.sql
```

---

## Technical notes

- **Signature format**: CMS `SignedData`, detached, with the CAdES signed
  attributes `contentType`, `signingTime`, `messageDigest` and
  `signingCertificateV2` (which pins the signature to one certificate). The PDF
  declares `/SubFilter /ETSI.CAdES.detached`. With a TSA configured, the token is
  attached as the `signatureTimeStampToken` unsigned attribute — CAdES-T /
  PAdES-B-T.
- **Why a container instead of stamping the customer's own PDF**: signing an
  arbitrary incoming PDF means rewriting its cross-reference table, and a rewrite
  that goes subtly wrong produces a file that *looks* signed. Writing our own
  container means every byte is ours; the original travels inside it untouched,
  and its SHA-256 is printed on the certificate so the two can always be checked
  against each other.
- **No dependencies.** No composer packages, no external PDF or crypto library —
  `src/Sign/` builds the DER, the CMS and the PDF itself, and uses PHP's openssl
  only for the raw signature. The app still runs on a server where
  `composer install` has never been run.
- **Not implemented**: LTV (a DSS with revocation data for very long-term
  validation), multiple signers on one document, and visible signature placement
  chosen by the sender. The first is the one to add if these documents need to
  verify decades out.

## Where the code lives

| File | What it does |
|---|---|
| `src/Sign/Documents.php` | The lifecycle: create, send, view, one-time code, seal, decline, withdraw |
| `src/Sign/Audit.php` | The hash-chained append-only log, and its verifier |
| `src/Sign/Signer.php` | Lays out the certificate PDF and fills the `/ByteRange` gap |
| `src/Sign/Pdf.php` | The PDF writer (text, rules, attachment, signature placeholder) |
| `src/Sign/Cms.php` | CAdES-BES `SignedData`, built by hand |
| `src/Sign/Asn1.php` | The DER encoder/decoder underneath it |
| `src/Sign/Certificate.php` | Loading the key pair, or generating the fallback one |
| `src/Sign/Timestamp.php` | RFC 3161 client |
| `src/Sign/Verify.php` | Independent verification, file-first |
| `public/sign.php` | What the customer sees |
| `public/verify.php` | What everyone else sees |
| `views/documents.php` | The staff side |
