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

    // Dashboard master/fallback password. Real accounts live in the `users` table
    // (Agents page); this is only a recovery login so you're never locked out.
    'dashboard' => [
        'password' => 'admin',
    ],
];
