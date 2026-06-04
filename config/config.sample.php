<?php
// Copy this file to config/config.php and edit for your environment.
// config.php is gitignored — it holds secrets.

return [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'bitrix_glue',
        'user'    => 'bitrix_glue',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // Bitrix24 INBOUND webhook — how WE call Bitrix (create leads, read entities,
    // send CRM activities). Create it in Bitrix24:
    //   Developer resources -> Other -> Inbound webhook.
    // The base_url is everything up to (and including) the token, with a trailing slash:
    //   https://yourportal.bitrix24.eu/rest/1/abcdef0123456789/
    // Grant scopes: crm, im (optional), user.
    'bitrix' => [
        'base_url' => 'https://yourportal.bitrix24.eu/rest/1/CHANGE_ME_TOKEN/',
        'verify_ssl' => true,

        // Secret shared with Bitrix OUTBOUND webhooks (stage/lead change -> us).
        // We require ?secret=... on /public/webhooks/bitrix-event.php and compare.
        'outbound_secret' => 'CHANGE_ME_OUTBOUND_SECRET',

        // Map of stage/status IDs in YOUR portal. Fill these from your pipelines.
        // Lead statuses (crm.status with ENTITY_ID=STATUS):
        'lead_status_new'      => 'NEW',        // "To Work" / first stage
        // Deal stages (crm.status with ENTITY_ID=DEAL_STAGE_<categoryId>):
        'deal_stage_signed'    => 'WON',        // signature done -> thank-you + logistics
        'deal_stage_quote'     => 'PREPARATION',// quote sent -> start signing reminders

        // Bitrix user/department to notify for logistics (optional, if you also
        // want an in-Bitrix task/notification beyond the email below).
        'logistics_user_id' => null,
    ],

    // TextMeBot WhatsApp gateway — reused from the parking app.
    'textmebot' => [
        'api_key'  => 'YOUR_TEXTMEBOT_API_KEY',
        'endpoint' => 'https://api.textmebot.com/send.php',
        // Seconds to wait between messages in a bulk campaign (TextMeBot rate limit).
        'campaign_throttle_seconds' => 8,
    ],

    // Outbound email. Uses PHP mail() by default; set 'smtp' to use SMTP instead.
    'mail' => [
        'from_email' => 'noreply@yourcompany.com',
        'from_name'  => 'Your Company',
        'smtp'       => null, // or ['host'=>'','port'=>587,'user'=>'','pass'=>'','secure'=>'tls']
    ],

    // Where signed-deal logistics notifications go.
    'logistics' => [
        'email' => 'logistics@yourcompany.com',
        'phone' => null, // E.164, e.g. +254700000000 — set to also WhatsApp logistics
    ],

    // Reminder cadences. Hours unless noted. Tune without touching code.
    'reminders' => [
        'lead_inactivity_hours'   => 2,   // req #4: agent notified if lead not moved
        'deal_inactivity_hours'   => 3,   // req (part 2 #1): "To Work" 3h timer
        // Appointment reminders: minutes-before-event to fire (both agent + customer).
        'appointment_offsets_min' => [1440, 120], // 24h and 2h before
        // Signing reminders: days-before-deadline to fire (req #6).
        'sign_offsets_days'       => [10, 5, 0],
        // After deadline, keep nudging every N days until signed/refused, up to max.
        'sign_overdue_every_days' => 3,
        'sign_overdue_max_days'   => 15,  // req (part 2 #2): 15-day window
    ],

    // Dashboard login (public/dashboard.php). Change this.
    'dashboard' => [
        'password' => 'change_this_password',
    ],

    'app' => [
        'timezone' => 'Africa/Nairobi',
        // Default message language when a lead doesn't specify one (en|it).
        // Staff (agent/logistics) notifications always use this language.
        'default_lang' => 'it',
        'base_url' => 'https://glue.yourcompany.com',
        // Shared secret required on /public/webhooks/form-intake.php (?secret=).
        'intake_secret' => 'CHANGE_ME_INTAKE_SECRET',
        // Secret to run migrate.php from the browser (?key=). Prefer CLI in prod.
        'migrate_key'   => 'CHANGE_ME_MIGRATE_KEY',
    ],
];
