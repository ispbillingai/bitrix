<?php
// Copy this file to config/config.php and edit for your environment.
// config.php is gitignored — it holds secrets.
//
// Almost everything here can also be set from the dashboard (Settings), which
// overlays this file at runtime. Only the `db` block must live here.

return [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'bitrix_glue',   // the CRM database (kept name for upgrades)
        'user'    => 'bitrix_glue',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // Your company / CRM identity. company_name is shown to customers in every
    // message and is the brand on the dashboard + public request form.
    'app' => [
        'company_name'  => 'Your Company',
        'timezone'      => 'Europe/Rome',
        // Default message language when a record doesn't specify one (en|it).
        // Staff (agent/logistics) notifications always use this language.
        'default_lang'  => 'it',
        // Dial code prepended to local phone numbers that have no +/00 prefix,
        // so a customer can enter "3391234567" and WhatsApp gets "+393391234567".
        // Digits only, no +. Italy = 39. Numbers entered with + or 00 are kept as-is.
        'default_country_code' => '39',
        // Public base URL of this app (used to build the form/webhook URLs).
        'base_url'      => 'https://crm.yourcompany.com',
        // Shared secret required on the website + appointment intake webhooks.
        'intake_secret' => 'CHANGE_ME_INTAKE_SECRET',
        // Secret to run migrate.php from the browser (?key=). Prefer CLI in prod.
        'migrate_key'   => 'CHANGE_ME_MIGRATE_KEY',
    ],

    // CRM defaults. Stage codes come from the seeded pipelines (migration 008);
    // edit pipelines/stages from Settings, not here.
    'crm' => [
        'currency'         => 'EUR',
        'deal_quote_stage' => 'QUOTE', // entering this deal stage starts sign reminders
    ],

    // TextMeBot WhatsApp gateway.
    'textmebot' => [
        'api_key'  => 'YOUR_TEXTMEBOT_API_KEY',
        'endpoint' => 'https://api.textmebot.com/send.php',
        // TextMeBot rejects messages sent too close together. Minimum seconds
        // between ANY two WhatsApp sends, app-wide (reminders, alerts, tests).
        'min_gap_seconds' => 8,
        // Seconds to wait between messages in a bulk campaign (rate limit).
        'campaign_throttle_seconds' => 8,
    ],

    // Outbound email. Uses PHP mail() by default; set 'smtp' to use SMTP instead.
    'mail' => [
        'from_email' => 'noreply@yourcompany.com',
        'from_name'  => 'Your Company',
        'smtp'       => null, // or ['host'=>'','port'=>587,'user'=>'','pass'=>'','secure'=>'tls']
    ],

    // Where won-deal logistics notifications go.
    'logistics' => [
        'email' => 'logistics@yourcompany.com',
        'phone' => null, // E.164, e.g. +393331234567 — set to also WhatsApp logistics
    ],

    // Reminder cadences. Hours unless noted. Tune from Settings without code.
    'reminders' => [
        'lead_inactivity_hours'   => 3,   // req #4: seller notified if lead not worked
        // Uncontacted lead keeps nudging on this cadence until it leaves NEW:
        'lead_nudge_repeat_hours'   => 12,  // agent + customer nudge repeat (12h = twice a day)
        'lead_customer_after_hours' => 24,  // first customer "please contact us" after 1 day
        'deal_inactivity_hours'   => 3,   // "To Work" timer for deals
        // Appointment reminders: minutes-before-event to fire (customer + seller).
        'appointment_offsets_min' => [1440, 120], // 24h and 2h before
        // Signing cadence (Phase 4). The agent sets a signature due date per deal
        // when sending the quote; the cadence anchors on it:
        'sign_after_sent_days'    => 15,      // R1: nudge if unsigned N days after the quote went out
        'sign_before_due_days'    => [10, 5], // R2/R3: nudge this many days BEFORE the due date
        'sign_due_default_days'   => 30,      // fallback due window when no date is set on the deal
        // After the due date, keep nudging every N days until signed, up to max.
        'sign_overdue_every_days' => 3,
        'sign_overdue_max_days'   => 15,  // 15-day window
    ],

    // OPTIONAL Bitrix24 sync — OFF by default. The CRM is fully standalone; enable
    // this only to also mirror new leads/deals into a Bitrix24 portal. Toggle and
    // fill these from Settings → Bitrix24 sync.
    'bitrix' => [
        'sync_enabled'    => false,
        'base_url'        => 'https://yourportal.bitrix24.eu/rest/1/CHANGE_ME_TOKEN/',
        'verify_ssl'      => true,
        'outbound_secret' => 'CHANGE_ME_OUTBOUND_SECRET',
        // Lead status used when pushing a new lead into Bitrix.
        'lead_status_new' => 'NEW',
    ],

    // OPTIONAL Sibill sync — OFF by default. Sibill holds the accounting: it
    // knows which invoices have been paid, and this pulls that in so the CRM can
    // show it next to the customer. Read-only: the app never issues a document.
    // Fill these from Settings → Sibill; the connection test lists the companies
    // your token can see and fills company_id in for you.
    'sibill' => [
        'enabled'      => false,
        'api_key'      => '',   // bearer token issued by Sibill
        'company_id'   => '',   // uuid of the company whose invoices to mirror
        'base_url'     => 'https://integration.sibill.com',
        'sync_minutes' => 30,   // how often the cron refreshes the mirror
        'sync_months'  => 0,    // 0 = every invoice; N = only the last N months
        'timeout'      => 30,

        // Automatic payment chasing. OFF by default and deliberately so: turning
        // it on starts messaging real customers about money. Switch it on only
        // once the debtor list has been looked over and contact details filled
        // in — Sibill has no phone/email, so those are typed into the CRM.
        'chase_enabled'      => false,
        'chase_every_days'   => 7,    // don't chase the same customer more often
        'chase_min_days_late'=> 7,    // grace period after the due date
        'chase_min_amount'   => 20,   // don't chase trivial balances
        'chase_max_per_run'  => 15,   // queued per hourly pass
        'chase_channel'      => 'both', // both | whatsapp | email
        'chase_hour_from'    => 9,    // local time window; nobody wants a 3am
        'chase_hour_to'      => 18,   // debt-collection message
    ],

    // Electronic signature (Documents page). Everything stays on this server;
    // only a hash ever leaves it, and only if you switch time stamping on.
    //
    // With nothing set here the app generates its own signing certificate on
    // first use and keeps it in storage/sign/keys. That is cryptographically
    // sound — a sealed PDF cannot be altered without breaking — but it is not
    // accredited by anyone. To get external accreditation, buy a qualified
    // (eIDAS) certificate and point pkcs12_path at the .p12/.pfx file; nothing
    // else in the app changes. See docs/signing.md.
    'sign' => [
        // A PKCS#12 bundle (certificate + private key + any chain).
        'pkcs12_path' => null,           // e.g. '/etc/crm/signing.p12'
        'pkcs12_pass' => '',
        // ...or separate PEM files, if that is what your CA gave you.
        'cert_path'   => null,
        'key_path'    => null,
        'key_pass'    => '',
        'chain_path'  => null,           // intermediate certificates, PEM
        // Country used only when generating the fallback self-signed certificate.
        'country'     => 'IT',
        // RFC 3161 time stamp authority. Empty = off; the sealing time is then
        // this server's own clock. A TSA signs the hash of our signature with
        // its own key and time, which is the cheapest independence you can buy.
        // Free option: https://freetsa.org/tsr
        'tsa_url'     => '',
        'tsa_user'    => '',
        'tsa_pass'    => '',
        'tsa_timeout' => 15,
        // Originals larger than this are not embedded in the sealed PDF; the
        // certificate still records their hash, and the file is kept alongside.
        'embed_max_bytes' => 8388608,    // 8 MB
        // Where originals, sealed PDFs and the key pair live. Empty = storage/sign
        // above the web root, falling back to public/uploads/sign (blocked by
        // .htaccess). Neither is ever served directly.
        'storage_dir' => null,
    ],

    // Dashboard master/fallback password. Real accounts live in the `users` table
    // (Agents page); this is only a recovery login so you're never locked out.
    'dashboard' => [
        'password' => 'admin',
    ],
];
