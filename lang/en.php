<?php
/**
 * English message copy. Keys mirror lang/it.php exactly.
 * Used by Glue\Reminder\Templates. {placeholders} are filled at send time:
 *   {company} {name} {customer_name} {customer_phone} {customer_email}
 *   {bitrix_id} {agent_name} {agent_phone} {agent_email} {when} {deadline}
 *
 * 'wa'    => plain WhatsApp text per rule_key
 * 'email' => ['subject' => ..., 'html' => ...] per rule_key
 */
return [
    'wa' => [
        'welcome' =>
            "Hello {name}! 👋 Thanks for reaching out to {company}. "
            . "We've received your request and a member of our team will contact you shortly.",

        'agent_assigned' =>
            "Hi {name}, your dedicated agent at {company} is *{agent_name}*. "
            . "You can reach {agent_name} directly on {agent_phone} or {agent_email}. "
            . "They'll be in touch soon!",

        'lead_inactivity' => // to the AGENT
            "⏰ Reminder: lead *{name}* (ID {bitrix_id}) has been waiting and hasn't "
            . "been moved. Please review it in Bitrix24.",

        'appointment_customer' =>
            "Hi {name}, this is a reminder of your appointment with {company} on {when}. "
            . "See you then!",

        'appointment_agent' =>
            "⏰ Appointment reminder: {customer_name} on {when} (deal {bitrix_id}).",

        'sign_due' =>
            "Hi {name}, your quote from {company} is ready to sign. "
            . "Please review and sign via Bitrix24 Sign before {deadline}. "
            . "Reply here if you need anything.",

        'sign_overdue' =>
            "Hi {name}, a friendly reminder that your quote from {company} is still "
            . "awaiting signature. You can sign anytime via Bitrix24 Sign. "
            . "Let us know if you'd like help.",

        'thank_you' =>
            "🎉 Thank you, {name}! We've received your signature. Your order with "
            . "{company} is confirmed and our logistics team is arranging delivery. "
            . "We'll keep you posted.",

        'logistics_notify' => // to the logistics team
            "📦 New signed deal {bitrix_id}. Customer: {name} ({customer_phone}). "
            . "Please arrange delivery.",
    ],

    'email' => [
        'welcome' => [
            'subject' => 'Welcome to {company}',
            'html'    => '<p>Hello {name},</p><p>Thanks for reaching out to {company}. '
                . 'We have received your request and a member of our team will contact you shortly.</p>',
        ],
        'agent_assigned' => [
            'subject' => 'Your {company} agent: {agent_name}',
            'html'    => '<p>Hi {name},</p><p>Your dedicated agent is <strong>{agent_name}</strong>.</p>'
                . '<p>Phone: {agent_phone}<br>Email: {agent_email}</p>'
                . '<p>They will be in touch soon.</p>',
        ],
        'lead_inactivity' => [
            'subject' => 'Action needed: lead {name} not processed',
            'html'    => '<p>Lead <strong>{name}</strong> (ID {bitrix_id}) has not been moved. '
                . 'Please review it in Bitrix24.</p>',
        ],
        'appointment_customer' => [
            'subject' => 'Reminder: your appointment with {company}',
            'html'    => '<p>Hi {name},</p><p>This is a reminder of your appointment on <strong>{when}</strong>.</p>',
        ],
        'appointment_agent' => [
            'subject' => 'Appointment reminder: {customer_name}',
            'html'    => '<p>Appointment with {customer_name} on <strong>{when}</strong> (deal {bitrix_id}).</p>',
        ],
        'sign_due' => [
            'subject' => 'Please sign your quote from {company}',
            'html'    => '<p>Hello {name},</p><p>Your quote is ready. Please review and sign via '
                . 'Bitrix24 Sign before <strong>{deadline}</strong>.</p>',
        ],
        'sign_overdue' => [
            'subject' => 'Reminder: your quote awaits signature',
            'html'    => '<p>Hello {name},</p><p>Your quote from {company} is still awaiting signature. '
                . 'You can sign anytime via Bitrix24 Sign.</p>',
        ],
        'thank_you' => [
            'subject' => 'Thank you — your order is confirmed',
            'html'    => '<p>Thank you, {name}!</p><p>We have received your signature and our logistics '
                . 'team is arranging delivery. We will keep you posted.</p>',
        ],
        'logistics_notify' => [
            'subject' => 'New signed deal {bitrix_id} — arrange delivery',
            'html'    => '<p>Deal <strong>{bitrix_id}</strong> has been signed.</p>'
                . '<p>Customer: {name}<br>Phone: {customer_phone}<br>Email: {customer_email}</p>'
                . '<p>Please arrange delivery.</p>',
        ],
    ],
];
