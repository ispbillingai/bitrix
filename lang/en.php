<?php
/**
 * English message copy. Keys mirror lang/it.php exactly.
 * Used by Glue\Reminder\Templates. {placeholders} are filled at send time:
 *   {company} {name} {customer_name} {customer_phone} {customer_email}
 *   {id} {agent_name} {agent_phone} {agent_email} {when} {deadline}
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
            "Hi {name}, your dedicated consultant at {company} is *{agent_name}*. "
            . "You can reach {agent_name} directly on {agent_phone} or {agent_email}. "
            . "They'll be in touch soon!",

        'agent_new_assignment' => // to the AGENT: a new customer was assigned to them
            "🔔 Hi {agent_name}, a new customer has been assigned to you: "
            . "*{customer_name}* ({customer_phone}). Open the CRM to follow up.",

        'lead_inactivity' => // to the SELLER
            "⏰ Reminder: lead *{name}* (#{id}) has been waiting and hasn't "
            . "been worked yet. Please review it in the CRM.",

        'lead_uncontacted_customer' => // to the CUSTOMER after a day with no contact
            "Hi {name}, this is {company}. 👋 We received your request but haven't "
            . "managed to reach you yet. Feel free to contact us directly at "
            . "{office_phone}, or just reply to this message — we'd be glad to help!",

        'appointment_confirmed' =>
            "Hi {name}, your appointment with {company} is confirmed for {when}. "
            . "We look forward to seeing you!",

        'appointment_customer' =>
            "Hi {name}, this is a reminder of your appointment with {company} on {when}. "
            . "See you then!",

        'appointment_agent' =>
            "⏰ Appointment reminder: {customer_name} on {when} (#{id}).",

        'sign_request' => // sent when the deal enters the signature stage
            "Hi {name}, your contract from {company} is ready to sign. "
            . "Open your customer area to review and sign it: {link}",

        'sign_due' =>
            "Hi {name}, your quote from {company} is ready. "
            . "Please review and sign it before {deadline}. "
            . "Reply here if you need anything.",

        'sign_overdue' =>
            "Hi {name}, a friendly reminder that your quote from {company} is still "
            . "awaiting signature. You can sign it anytime. "
            . "Let us know if you'd like help.",

        'thank_you' =>
            "🎉 Thank you, {name}! We've received your signature. Your order with "
            . "{company} is confirmed and our logistics team is arranging delivery. "
            . "We'll keep you posted.",

        'logistics_notify' => // to the logistics team
            "📦 New signed deal #{id}. Customer: {name} ({customer_phone}). "
            . "Please arrange delivery.",

        'portal_invite' =>
            "Hi {name}! 👋 Your {company} customer area is ready. "
            . "Here you can follow your order and sign your contract: {link}",

        'sign_otp' =>
            "{company}: your signing code is *{code}*. "
            . "It is valid for {minutes} minutes. Do not share it with anyone.",

        'doc_sign_request' => // to the customer: a document is waiting for signature
            "Hi {name}! ✍️ {company} has sent you \"{title}\" to sign. "
            . "Open the link, read the document and confirm with the one-time code "
            . "we will send you: {link}",

        'doc_sign_otp' => // to the customer: the code for THIS document
            "{company}: your code to sign \"{title}\" is *{code}*. "
            . "It is valid for {minutes} minutes. Do not share it with anyone.",

        'doc_signed_copy' => // to the customer: it is done, here is the proof
            "✅ Thank you {name}! \"{title}\" has been signed. Your signed copy "
            . "contains the original document and a signature certificate. "
            . "You can verify it any time here: {link}",

        'doc_signed_staff' => // to the agent who sent it
            "✅ {customer_name} signed \"{title}\". The sealed copy is on the "
            . "Documents page in the CRM.",

        'doc_declined_staff' => // to the agent who sent it
            "⚠️ {customer_name} declined to sign \"{title}\". Reason: {reason}",

        'ticket_staff' => // to the assigned agent
            "💬 New customer message from {customer_name} — \"{subject}\" (ticket #{id}). "
            . "Reply from the CRM.",

        'ticket_reply' => // to the customer
            "{company}: you have a new reply to \"{subject}\". "
            . "Open your area to read and reply: {link}",

        'offer_read' => // to the customer: the offer file is waiting
            "Hi {name}, we sent you our offer \"{subject}\" in your {company} "
            . "customer area. Please open it, read it and download the file: {link}",

        'offer_accepted' => // to the assigned agent: send the contract!
            "✅ {customer_name} ACCEPTED the offer \"{subject}\" (conversation #{id}). "
            . "Please send them the contract for signature now.",

        'agent_welcome' => // to a newly created staff user: their login details
            "Hi {name}! 👋 Your {company} account is ready.\n"
            . "Sign in: {link}\n"
            . "Username: {username}\n"
            . "Password: {password}\n"
            . "Please change your password after the first login.",

        'vat_thanks' => // to the agent/partner who entered a lead with a new VAT number
            "Thank you {enterer_name} for entering a new lead! 🎉 "
            . "VAT number {vat} ({customer_name}) is reserved for you for {lock_days} days, until {until}. — {company}",

        'vat_taken' => // to the blocked agent/partner: VAT already entered by someone else
            "Hi {enterer_name}, VAT number {vat} has already been entered by another associate. "
            . "As per the rules it will become available for processing again after the {lock_days}-day period, "
            . "on {available_date}. — {company}",
    ],

    'email' => [
        'welcome' => [
            'subject' => 'Welcome to {company}',
            'html'    => '<p>Hello {name},</p><p>Thanks for reaching out to {company}. '
                . 'We have received your request and a member of our team will contact you shortly.</p>',
        ],
        'agent_assigned' => [
            'subject' => 'Your {company} consultant: {agent_name}',
            'html'    => '<p>Hi {name},</p><p>Your dedicated consultant is <strong>{agent_name}</strong>.</p>'
                . '<p>Phone: {agent_phone}<br>Email: {agent_email}</p>'
                . '<p>They will be in touch soon.</p>',
        ],
        'agent_new_assignment' => [
            'subject' => 'New customer assigned to you: {customer_name}',
            'html'    => '<p>Hi {agent_name},</p><p>A new customer has been assigned to you:</p>'
                . '<p><strong>{customer_name}</strong><br>Phone: {customer_phone}<br>Email: {customer_email}</p>'
                . '<p>Open the CRM to follow up.</p>',
        ],
        'lead_inactivity' => [
            'subject' => 'Action needed: lead {name} not processed',
            'html'    => '<p>Lead <strong>{name}</strong> (#{id}) has not been worked yet. '
                . 'Please review it in the CRM.</p>',
        ],
        'lead_uncontacted_customer' => [
            'subject' => '{company}: can we help?',
            'html'    => '<p>Hi {name},</p><p>this is {company}. We received your request but haven\'t '
                . 'managed to reach you yet. You can contact us at <strong>{office_phone}</strong> '
                . 'or simply reply to this email — we\'d be glad to help.</p>',
        ],
        'appointment_confirmed' => [
            'subject' => 'Your appointment with {company} is confirmed',
            'html'    => '<p>Hi {name},</p><p>Your appointment is confirmed for <strong>{when}</strong>. '
                . 'We look forward to seeing you.</p>',
        ],
        'appointment_customer' => [
            'subject' => 'Reminder: your appointment with {company}',
            'html'    => '<p>Hi {name},</p><p>This is a reminder of your appointment on <strong>{when}</strong>.</p>',
        ],
        'appointment_agent' => [
            'subject' => 'Appointment reminder: {customer_name}',
            'html'    => '<p>Appointment with {customer_name} on <strong>{when}</strong> (#{id}).</p>',
        ],
        'sign_request' => [
            'subject' => 'Please sign your contract — {company}',
            'html'    => '<p>Hi {name},</p><p>Your contract from {company} is ready to sign.</p>'
                . '<p><a href="{link}">Open my area &amp; sign</a></p>'
                . '<p>Or paste this link into your browser:<br>{link}</p>',
        ],
        'sign_due' => [
            'subject' => 'Please sign your quote from {company}',
            'html'    => '<p>Hello {name},</p><p>Your quote is ready. Please review and sign it '
                . 'before <strong>{deadline}</strong>.</p>',
        ],
        'sign_overdue' => [
            'subject' => 'Reminder: your quote awaits signature',
            'html'    => '<p>Hello {name},</p><p>Your quote from {company} is still awaiting signature. '
                . 'You can sign it anytime.</p>',
        ],
        'thank_you' => [
            'subject' => 'Thank you — your order is confirmed',
            'html'    => '<p>Thank you, {name}!</p><p>We have received your signature and our logistics '
                . 'team is arranging delivery. We will keep you posted.</p>',
        ],
        'logistics_notify' => [
            'subject' => 'New signed deal #{id} — arrange delivery',
            'html'    => '<p>Deal <strong>#{id}</strong> has been signed.</p>'
                . '<p>Customer: {name}<br>Phone: {customer_phone}<br>Email: {customer_email}</p>'
                . '<p>Please arrange delivery.</p>',
        ],
        'portal_invite' => [
            'subject' => 'Your {company} customer area',
            'html'    => '<p>Hi {name},</p><p>Your {company} customer area is ready. There you can '
                . 'follow your order and sign your contract.</p>'
                . '<p><a href="{link}">Open my customer area</a></p>'
                . '<p>Or paste this link into your browser:<br>{link}</p>',
        ],
        'sign_otp' => [
            'subject' => 'Your {company} signing code: {code}',
            'html'    => '<p>Hello {name},</p><p>Your one-time code to sign your contract is:</p>'
                . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px">{code}</p>'
                . '<p>It is valid for {minutes} minutes. Do not share it with anyone.</p>',
        ],
        'doc_sign_request' => [
            'subject' => '{company}: please sign "{title}"',
            'html'    => '<p>Hello {name},</p><p>{company} has sent you <strong>"{title}"</strong> to sign.</p>'
                . '<p>Open the link below, read the document, and confirm with the one-time code we will '
                . 'send you. It takes about a minute.</p>'
                . '<p><a href="{link}">Read and sign the document</a></p>'
                . '<p>Or paste this link into your browser:<br>{link}</p>',
        ],
        'doc_sign_otp' => [
            'subject' => 'Your code to sign "{title}": {code}',
            'html'    => '<p>Hello {name},</p><p>Your one-time code to sign <strong>"{title}"</strong> is:</p>'
                . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px">{code}</p>'
                . '<p>It is valid for {minutes} minutes. Do not share it with anyone.</p>',
        ],
        'doc_signed_copy' => [
            'subject' => 'Signed: "{title}"',
            'html'    => '<p>Hello {name},</p><p><strong>"{title}"</strong> has been signed. Thank you.</p>'
                . '<p>Your signed copy contains the original document and a signature certificate '
                . 'recording who signed, how they were identified, and when.</p>'
                . '<p><a href="{link}">Verify this signature</a></p>',
        ],
        'doc_signed_staff' => [
            'subject' => 'Signed: "{title}" — {customer_name}',
            'html'    => '<p><strong>{customer_name}</strong> signed "{title}".</p>'
                . '<p>The sealed copy and its operation log are on the Documents page in the CRM.</p>',
        ],
        'doc_declined_staff' => [
            'subject' => 'Declined: "{title}" — {customer_name}',
            'html'    => '<p><strong>{customer_name}</strong> declined to sign "{title}".</p>'
                . '<p>Reason given: {reason}</p>',
        ],
        'ticket_staff' => [
            'subject' => 'New customer message — {subject} (#{id})',
            'html'    => '<p>{customer_name} sent a new message on ticket <strong>#{id}</strong> — "{subject}".</p>'
                . '<p>Open the CRM to reply.</p>',
        ],
        'ticket_reply' => [
            'subject' => 'New reply to your request — {subject}',
            'html'    => '<p>Hello {name},</p><p>You have a new reply to your request "{subject}".</p>'
                . '<p><a href="{link}">Open my customer area</a> to read and reply.</p>',
        ],
        'offer_read' => [
            'subject' => 'Your {company} offer is waiting — {subject}',
            'html'    => '<p>Hello {name},</p><p>We sent you our offer "{subject}" in your {company} '
                . 'customer area. Please open it, read it and download the file.</p>'
                . '<p><a href="{link}">Open my customer area</a></p>',
        ],
        'offer_accepted' => [
            'subject' => 'Offer accepted by {customer_name} — send the contract',
            'html'    => '<p><strong>{customer_name}</strong> accepted the offer "{subject}" '
                . '(conversation #{id}).</p><p>Please send them the contract for signature now.</p>',
        ],
        'agent_welcome' => [
            'subject' => 'Your {company} account',
            'html'    => '<p>Hi {name},</p><p>Your {company} account has been created.</p>'
                . '<p><a href="{link}">Sign in to the dashboard</a></p>'
                . '<p>Username: <strong>{username}</strong><br>Password: <strong>{password}</strong></p>'
                . '<p>Please change your password after the first login.</p>',
        ],
        'vat_thanks' => [
            'subject' => 'New lead registered — VAT {vat} reserved for you',
            'html'    => '<p>Thank you {enterer_name} for entering a new lead!</p>'
                . '<p>VAT number <strong>{vat}</strong> ({customer_name}) is reserved for you for '
                . '<strong>{lock_days} days</strong>, until <strong>{until}</strong>.</p><p>{company}</p>',
        ],
        'vat_taken' => [
            'subject' => 'VAT {vat} already entered by another associate',
            'html'    => '<p>Hi {enterer_name},</p><p>VAT number <strong>{vat}</strong> has already been entered '
                . 'by another associate. As per the rules it will become available for processing again after the '
                . '{lock_days}-day period, on <strong>{available_date}</strong>.</p><p>{company}</p>',
        ],
    ],
];
