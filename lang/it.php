<?php
/**
 * Copy dei messaggi in italiano. Le chiavi rispecchiano esattamente lang/en.php.
 * Usato da Glue\Reminder\Templates. I {segnaposto} vengono riempiti all'invio:
 *   {company} {name} {customer_name} {customer_phone} {customer_email}
 *   {bitrix_id} {agent_name} {agent_phone} {agent_email} {when} {deadline}
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
            "Ciao {name}, il tuo agente dedicato presso {company} è *{agent_name}*. "
            . "Puoi contattare {agent_name} direttamente al {agent_phone} oppure a {agent_email}. "
            . "Ti contatterà al più presto!",

        'lead_inactivity' => // all'AGENTE
            "⏰ Promemoria: il lead *{name}* (ID {bitrix_id}) è in attesa e non è "
            . "ancora stato lavorato. Verificalo su Bitrix24.",

        'appointment_customer' =>
            "Ciao {name}, ti ricordiamo il tuo appuntamento con {company} il {when}. "
            . "A presto!",

        'appointment_agent' =>
            "⏰ Promemoria appuntamento: {customer_name} il {when} (trattativa {bitrix_id}).",

        'sign_due' =>
            "Ciao {name}, il preventivo di {company} è pronto per la firma. "
            . "Ti invitiamo a verificarlo e firmarlo tramite Bitrix24 Sign entro il {deadline}. "
            . "Rispondi qui se hai bisogno di assistenza.",

        'sign_overdue' =>
            "Ciao {name}, ti ricordiamo che il preventivo di {company} è ancora "
            . "in attesa di firma. Puoi firmarlo in qualsiasi momento tramite Bitrix24 Sign. "
            . "Facci sapere se ti serve aiuto.",

        'thank_you' =>
            "🎉 Grazie, {name}! Abbiamo ricevuto la tua firma. Il tuo ordine con "
            . "{company} è confermato e il nostro reparto logistica sta organizzando la consegna. "
            . "Ti terremo aggiornato.",

        'logistics_notify' => // al reparto logistica
            "📦 Nuova trattativa firmata {bitrix_id}. Cliente: {name} ({customer_phone}). "
            . "Si prega di organizzare la consegna.",
    ],

    'email' => [
        'welcome' => [
            'subject' => 'Benvenuto in {company}',
            'html'    => '<p>Ciao {name},</p><p>Grazie per aver contattato {company}. '
                . 'Abbiamo ricevuto la tua richiesta e un membro del nostro team ti contatterà a breve.</p>',
        ],
        'agent_assigned' => [
            'subject' => 'Il tuo agente {company}: {agent_name}',
            'html'    => '<p>Ciao {name},</p><p>Il tuo agente dedicato è <strong>{agent_name}</strong>.</p>'
                . '<p>Telefono: {agent_phone}<br>Email: {agent_email}</p>'
                . '<p>Ti contatterà al più presto.</p>',
        ],
        'lead_inactivity' => [
            'subject' => 'Azione richiesta: lead {name} non lavorato',
            'html'    => '<p>Il lead <strong>{name}</strong> (ID {bitrix_id}) non è stato lavorato. '
                . 'Verificalo su Bitrix24.</p>',
        ],
        'appointment_customer' => [
            'subject' => 'Promemoria: il tuo appuntamento con {company}',
            'html'    => '<p>Ciao {name},</p><p>Ti ricordiamo il tuo appuntamento del <strong>{when}</strong>.</p>',
        ],
        'appointment_agent' => [
            'subject' => 'Promemoria appuntamento: {customer_name}',
            'html'    => '<p>Appuntamento con {customer_name} il <strong>{when}</strong> (trattativa {bitrix_id}).</p>',
        ],
        'sign_due' => [
            'subject' => 'Firma il tuo preventivo di {company}',
            'html'    => '<p>Ciao {name},</p><p>Il tuo preventivo è pronto. Verificalo e firmalo tramite '
                . 'Bitrix24 Sign entro il <strong>{deadline}</strong>.</p>',
        ],
        'sign_overdue' => [
            'subject' => 'Promemoria: il tuo preventivo è in attesa di firma',
            'html'    => '<p>Ciao {name},</p><p>Il preventivo di {company} è ancora in attesa di firma. '
                . 'Puoi firmarlo in qualsiasi momento tramite Bitrix24 Sign.</p>',
        ],
        'thank_you' => [
            'subject' => 'Grazie — il tuo ordine è confermato',
            'html'    => '<p>Grazie, {name}!</p><p>Abbiamo ricevuto la tua firma e il nostro reparto '
                . 'logistica sta organizzando la consegna. Ti terremo aggiornato.</p>',
        ],
        'logistics_notify' => [
            'subject' => 'Nuova trattativa firmata {bitrix_id} — organizzare consegna',
            'html'    => '<p>La trattativa <strong>{bitrix_id}</strong> è stata firmata.</p>'
                . '<p>Cliente: {name}<br>Telefono: {customer_phone}<br>Email: {customer_email}</p>'
                . '<p>Si prega di organizzare la consegna.</p>',
        ],
    ],
];
