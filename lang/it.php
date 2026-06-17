<?php
/**
 * Copy dei messaggi in italiano. Le chiavi rispecchiano esattamente lang/en.php.
 * Usato da Glue\Reminder\Templates. I {segnaposto} vengono riempiti all'invio:
 *   {company} {name} {customer_name} {customer_phone} {customer_email}
 *   {id} {agent_name} {agent_phone} {agent_email} {when} {deadline}
 *
 * 'wa'    => testo WhatsApp per rule_key
 * 'email' => ['subject' => ..., 'html' => ...] per rule_key
 */
return [
    'wa' => [
        'welcome' =>
            "Ciao {name}! 👋 Grazie per aver contattato {company}. "
            . "Abbiamo ricevuto la tua richiesta e un membro del nostro team ti contatterà a breve.",

        'agent_assigned' =>
            "Ciao {name}, il tuo consulente dedicato presso {company} è *{agent_name}*. "
            . "Puoi contattare {agent_name} direttamente al {agent_phone} oppure a {agent_email}. "
            . "Ti contatterà al più presto!",

        'agent_new_assignment' => // all'AGENTE: gli è stato assegnato un nuovo cliente
            "🔔 Ciao {agent_name}, ti è stato assegnato un nuovo cliente: "
            . "*{customer_name}* ({customer_phone}). Apri il CRM per gestirlo.",

        'lead_inactivity' => // al VENDITORE
            "⏰ Promemoria: il lead *{name}* (#{id}) è in attesa e non è "
            . "ancora stato lavorato. Verificalo nel CRM.",

        'appointment_confirmed' =>
            "Ciao {name}, il tuo appuntamento con {company} è confermato per il {when}. "
            . "Ti aspettiamo!",

        'appointment_customer' =>
            "Ciao {name}, ti ricordiamo il tuo appuntamento con {company} il {when}. "
            . "A presto!",

        'appointment_agent' =>
            "⏰ Promemoria appuntamento: {customer_name} il {when} (#{id}).",

        'sign_request' => // inviato quando la trattativa entra nella fase di firma
            "Ciao {name}, il tuo contratto di {company} è pronto per la firma. "
            . "Apri la tua area clienti per verificarlo e firmarlo: {link}",

        'sign_due' =>
            "Ciao {name}, il preventivo di {company} è pronto. "
            . "Ti invitiamo a verificarlo e firmarlo entro il {deadline}. "
            . "Rispondi qui se hai bisogno di assistenza.",

        'sign_overdue' =>
            "Ciao {name}, ti ricordiamo che il preventivo di {company} è ancora "
            . "in attesa di firma. Puoi firmarlo in qualsiasi momento. "
            . "Facci sapere se ti serve aiuto.",

        'thank_you' =>
            "🎉 Grazie, {name}! Abbiamo ricevuto la tua firma. Il tuo ordine con "
            . "{company} è confermato e il nostro reparto logistica sta organizzando la consegna. "
            . "Ti terremo aggiornato.",

        'logistics_notify' => // al reparto logistica
            "📦 Nuova trattativa firmata #{id}. Cliente: {name} ({customer_phone}). "
            . "Si prega di organizzare la consegna.",

        'portal_invite' =>
            "Ciao {name}! 👋 La tua area clienti {company} è pronta. "
            . "Qui puoi seguire il tuo ordine e firmare il contratto: {link}",

        'sign_otp' =>
            "{company}: il tuo codice di firma è *{code}*. "
            . "È valido per {minutes} minuti. Non condividerlo con nessuno.",

        'ticket_staff' => // all'agente assegnato
            "💬 Nuovo messaggio dal cliente {customer_name} — \"{subject}\" (ticket #{id}). "
            . "Rispondi dal CRM.",

        'ticket_reply' => // al cliente
            "{company}: hai una nuova risposta a \"{subject}\". "
            . "Apri la tua area per leggere e rispondere: {link}",

        'offer_read' => // al cliente: l'offerta lo aspetta
            "Ciao {name}, ti abbiamo inviato la nostra offerta \"{subject}\" nella tua "
            . "area clienti {company}. Aprila, leggila e scarica il file: {link}",

        'offer_accepted' => // all'agente assegnato: invia il contratto!
            "✅ {customer_name} ha ACCETTATO l'offerta \"{subject}\" (conversazione #{id}). "
            . "Inviagli subito il contratto da firmare.",

        'agent_welcome' => // a un nuovo utente staff: le sue credenziali
            "Ciao {name}! 👋 Il tuo account {company} è pronto.\n"
            . "Accedi: {link}\n"
            . "Utente: {username}\n"
            . "Password: {password}\n"
            . "Cambia la password dopo il primo accesso.",
    ],

    'email' => [
        'welcome' => [
            'subject' => 'Benvenuto in {company}',
            'html'    => '<p>Ciao {name},</p><p>Grazie per aver contattato {company}. '
                . 'Abbiamo ricevuto la tua richiesta e un membro del nostro team ti contatterà a breve.</p>',
        ],
        'agent_assigned' => [
            'subject' => 'Il tuo consulente {company}: {agent_name}',
            'html'    => '<p>Ciao {name},</p><p>Il tuo consulente dedicato è <strong>{agent_name}</strong>.</p>'
                . '<p>Telefono: {agent_phone}<br>Email: {agent_email}</p>'
                . '<p>Ti contatterà al più presto.</p>',
        ],
        'agent_new_assignment' => [
            'subject' => 'Nuovo cliente assegnato a te: {customer_name}',
            'html'    => '<p>Ciao {agent_name},</p><p>Ti è stato assegnato un nuovo cliente:</p>'
                . '<p><strong>{customer_name}</strong><br>Telefono: {customer_phone}<br>Email: {customer_email}</p>'
                . '<p>Apri il CRM per gestirlo.</p>',
        ],
        'lead_inactivity' => [
            'subject' => 'Azione richiesta: lead {name} non lavorato',
            'html'    => '<p>Il lead <strong>{name}</strong> (#{id}) non è ancora stato lavorato. '
                . 'Verificalo nel CRM.</p>',
        ],
        'appointment_confirmed' => [
            'subject' => 'Il tuo appuntamento con {company} è confermato',
            'html'    => '<p>Ciao {name},</p><p>Il tuo appuntamento è confermato per il <strong>{when}</strong>. '
                . 'Ti aspettiamo.</p>',
        ],
        'appointment_customer' => [
            'subject' => 'Promemoria: il tuo appuntamento con {company}',
            'html'    => '<p>Ciao {name},</p><p>Ti ricordiamo il tuo appuntamento del <strong>{when}</strong>.</p>',
        ],
        'appointment_agent' => [
            'subject' => 'Promemoria appuntamento: {customer_name}',
            'html'    => '<p>Appuntamento con {customer_name} il <strong>{when}</strong> (#{id}).</p>',
        ],
        'sign_request' => [
            'subject' => 'Firma il tuo contratto — {company}',
            'html'    => '<p>Ciao {name},</p><p>Il tuo contratto di {company} è pronto per la firma.</p>'
                . '<p><a href="{link}">Apri la mia area e firma</a></p>'
                . '<p>Oppure incolla questo link nel browser:<br>{link}</p>',
        ],
        'sign_due' => [
            'subject' => 'Firma il tuo preventivo di {company}',
            'html'    => '<p>Ciao {name},</p><p>Il tuo preventivo è pronto. Verificalo e firmalo '
                . 'entro il <strong>{deadline}</strong>.</p>',
        ],
        'sign_overdue' => [
            'subject' => 'Promemoria: il tuo preventivo è in attesa di firma',
            'html'    => '<p>Ciao {name},</p><p>Il preventivo di {company} è ancora in attesa di firma. '
                . 'Puoi firmarlo in qualsiasi momento.</p>',
        ],
        'thank_you' => [
            'subject' => 'Grazie — il tuo ordine è confermato',
            'html'    => '<p>Grazie, {name}!</p><p>Abbiamo ricevuto la tua firma e il nostro reparto '
                . 'logistica sta organizzando la consegna. Ti terremo aggiornato.</p>',
        ],
        'logistics_notify' => [
            'subject' => 'Nuova trattativa firmata #{id} — organizzare consegna',
            'html'    => '<p>La trattativa <strong>#{id}</strong> è stata firmata.</p>'
                . '<p>Cliente: {name}<br>Telefono: {customer_phone}<br>Email: {customer_email}</p>'
                . '<p>Si prega di organizzare la consegna.</p>',
        ],
        'portal_invite' => [
            'subject' => 'La tua area clienti {company}',
            'html'    => '<p>Ciao {name},</p><p>La tua area clienti {company} è pronta. Qui puoi '
                . 'seguire il tuo ordine e firmare il contratto.</p>'
                . '<p><a href="{link}">Apri la mia area clienti</a></p>'
                . '<p>Oppure incolla questo link nel browser:<br>{link}</p>',
        ],
        'sign_otp' => [
            'subject' => 'Il tuo codice di firma {company}: {code}',
            'html'    => '<p>Ciao {name},</p><p>Il tuo codice monouso per firmare il contratto è:</p>'
                . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px">{code}</p>'
                . '<p>È valido per {minutes} minuti. Non condividerlo con nessuno.</p>',
        ],
        'ticket_staff' => [
            'subject' => 'Nuovo messaggio dal cliente — {subject} (#{id})',
            'html'    => '<p>{customer_name} ha inviato un nuovo messaggio sul ticket <strong>#{id}</strong> — "{subject}".</p>'
                . '<p>Apri il CRM per rispondere.</p>',
        ],
        'ticket_reply' => [
            'subject' => 'Nuova risposta alla tua richiesta — {subject}',
            'html'    => '<p>Ciao {name},</p><p>Hai una nuova risposta alla tua richiesta "{subject}".</p>'
                . '<p><a href="{link}">Apri la mia area clienti</a> per leggere e rispondere.</p>',
        ],
        'offer_read' => [
            'subject' => 'La tua offerta {company} ti aspetta — {subject}',
            'html'    => '<p>Ciao {name},</p><p>Ti abbiamo inviato la nostra offerta "{subject}" nella tua '
                . 'area clienti {company}. Aprila, leggila e scarica il file.</p>'
                . '<p><a href="{link}">Apri la mia area clienti</a></p>',
        ],
        'offer_accepted' => [
            'subject' => 'Offerta accettata da {customer_name} — invia il contratto',
            'html'    => '<p><strong>{customer_name}</strong> ha accettato l\'offerta "{subject}" '
                . '(conversazione #{id}).</p><p>Inviagli subito il contratto da firmare.</p>',
        ],
        'agent_welcome' => [
            'subject' => 'Il tuo account {company}',
            'html'    => '<p>Ciao {name},</p><p>Il tuo account {company} è stato creato.</p>'
                . '<p><a href="{link}">Accedi al pannello</a></p>'
                . '<p>Utente: <strong>{username}</strong><br>Password: <strong>{password}</strong></p>'
                . '<p>Cambia la password dopo il primo accesso.</p>',
        ],
    ],
];
