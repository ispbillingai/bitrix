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

        'lead_uncontacted_customer' => // al CLIENTE dopo un giorno senza contatto
            "Ciao {name}, siamo {company}. 👋 Abbiamo ricevuto la tua richiesta ma "
            . "non siamo ancora riusciti a sentirti. Se vuoi, puoi contattarci "
            . "direttamente al {office_phone} oppure rispondere a questo messaggio: "
            . "saremo felici di aiutarti!",

        'appointment_confirmed' =>
            "Ciao {name}, il tuo appuntamento con {company} è confermato per il {when}. "
            . "Ti aspettiamo!",

        'appointment_customer' =>
            "Ciao {name}, ti ricordiamo il tuo appuntamento con {company} il {when}. "
            . "A presto!",

        'appointment_agent_set' =>
            "📅 Nuovo appuntamento fissato: {customer_name} il {when} (#{id}). Agente: {agent_name}.",

        'appointment_agent' =>
            "⏰ Promemoria appuntamento: {customer_name} il {when} (#{id}). Agente: {agent_name}.",

        // {when} already opens with the weekday ("venerdì 25 settembre…"), so
        // these read "fissato per venerdì", never "per il venerdì".
        'intervention_confirmed' =>
            "Ciao {name}, l'intervento tecnico di {company} è fissato per {when}"
            . "{?location} presso {location}{/location}. "
            . "{?agent_name}Interviene {agent_name}. {/agent_name}Se hai un imprevisto, rispondi a questo messaggio.",

        'intervention_customer' =>
            "Ciao {name}, ti ricordiamo l'intervento tecnico di {company} in programma {when}"
            . "{?location} presso {location}{/location}. "
            . "{?agent_name}Ti raggiunge {agent_name}. {/agent_name}A presto!",

        'intervention_tech_set' =>
            "🔧 Intervento fissato: {customer_name} — {when}"
            . "{?location} — {location}{/location} (#{id}).",

        'intervention_tech' =>
            "⏰ Promemoria intervento: {customer_name} — {when}"
            . "{?location} — {location}{/location} (#{id}).",

        'intervention_taken_over' =>
            "🔄 Ciao {name}, l'intervento presso {customer_name} del {when} (#{id}) "
            . "è stato assegnato a un collega. Non devi più occupartene.",

        // La pianificazione della giornata: il messaggio delle 17:00 e i solleciti.
        // {link} è la conferma "ho pianificato" — senza il clic il CRM continua a chiedere.
        'planning_prompt' =>
            "📋 Ciao {name}, è il momento di organizzare la giornata di domani ({date}). "
            . "Hai {count} interventi già assegnati e {pool} in attesa di essere presi in carico. "
            . "Quando hai finito, conferma qui: {link}",

        'planning_nudge' =>
            "⏰ {name}, la pianificazione di domani ({date}) non risulta ancora confermata. "
            . "Hai {count} interventi assegnati, {pool} ancora da assegnare. "
            . "Conferma qui appena hai finito: {link}",

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

        // Recupero password dello STAFF: il link vale {minutes} minuti ed è monouso.
        // Manutenzione a chiamata: tre mesi dopo l’ultimo intervento.
        'maintenance_due' =>
            "Ciao {name}, sono passati circa {months} mesi dall'ultimo intervento di {company} "
            . "sulla tua attrezzatura. Vuoi fissare una manutenzione? "
            . "Richiedila qui e ti ricontattiamo per concordare giorno e ora: {link}",

        'password_reset' =>
            "Ciao {name}, hai chiesto di reimpostare la password del CRM {company}. "
            . "Apri questo link entro {minutes} minuti e scegli la nuova password: {link} "
            . "Se non sei stato tu, ignora questo messaggio: la password attuale resta valida.",

        'sign_otp' =>
            "{company}: il tuo codice di firma è *{code}*. "
            . "È valido per {minutes} minuti. Non condividerlo con nessuno.",

        'doc_sign_request' => // al cliente: un documento attende la firma
            "Ciao {name}! ✍️ {company} ti ha inviato \"{title}\" da firmare. "
            . "Apri il link, leggi il documento e conferma con il codice monouso "
            . "che ti invieremo: {link}",

        'doc_sign_otp' => // al cliente: il codice per QUESTO documento
            "{company}: il tuo codice per firmare \"{title}\" è *{code}*. "
            . "È valido per {minutes} minuti. Non condividerlo con nessuno.",

        'doc_signed_copy' => // al cliente: fatto, ecco la prova
            "✅ Grazie {name}! \"{title}\" è stato firmato. La tua copia firmata "
            . "contiene il documento originale e un certificato di firma. "
            . "Puoi verificarla in qualsiasi momento qui: {link}",

        'doc_signed_staff' => // all'agente che l'ha inviato
            "✅ {customer_name} ha firmato \"{title}\". La copia sigillata è nella "
            . "pagina Documenti del CRM.",

        'doc_declined_staff' => // all'agente che l'ha inviato
            "⚠️ {customer_name} ha rifiutato di firmare \"{title}\". Motivo: {reason}",

        'ticket_staff' => // all'agente assegnato
            "💬 Nuovo messaggio dal cliente {customer_name} — \"{subject}\" (ticket #{id}). "
            . "Rispondi dal CRM.",

        'customer_lead_admin' => // agli amministratori: un cliente esistente chiede qualcosa — un lead da assegnare
            "📩 Nuova richiesta da un cliente esistente: {customer_name}{code} — {what} ({assigned}).\n{request}\nDa: {from}\n"
            . "Apri il lead e assegna un agente: {link}",

        'customer_maybe_admin' => // agli amministratori: forse è un cliente (più schede corrispondono)
            "📩 Nuova richiesta, forse da un cliente esistente: {from} — {what}.\n"
            . "In anagrafica più clienti hanno questo telefono o email: {cards}.\n{request}\n"
            . "Apri il lead e verifica se è lo stesso cliente: {link}",

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

        'vat_thanks' => // all'agente/partner che ha inserito un lead con P.IVA nuova
            "Grazie {enterer_name} per aver inserito un nuovo lead! 🎉 "
            . "La partita IVA {vat} ({customer_name}) è riservata a te per {lock_days} giorni, fino al {until}. — {company}",

        'vat_taken' => // all'agente/partner bloccato: P.IVA già inserita da altri
            "Ciao {enterer_name}, la partita IVA {vat} risulta già inserita da un altro collaboratore. "
            . "Come da regolamento tornerà disponibile per la lavorazione dopo il periodo di {lock_days} giorni, "
            . "il {available_date}. — {company}",

        // Al PARTNER, ed è l'unico messaggio sull'andamento che riceve mai: la sua
        // segnalazione è arrivata a destinazione. Mentre viene lavorata non gli
        // arriva nulla — nessun cambio di fase, nessuna assegnazione, nessun
        // sollecito.
        'partner_lead_won' =>
            "🎉 Buone notizie {partner_name}: la tua segnalazione *{customer_name}* è stata chiusa positivamente. "
            . "Grazie per la segnalazione! — {company}",

        'partner_lead_lost' =>
            "Ciao {partner_name}, la tua segnalazione *{customer_name}* si è chiusa senza accordo. "
            . "Grazie comunque per la segnalazione — alla prossima. — {company}",

        // Al CLIENTE: ha fatture scadute non pagate. I dati stanno su righe
        // etichettate invece che dentro la frase, così "1 fattura" e "5 fatture"
        // non richiedono due versioni del testo. Nessun numero di telefono nel
        // testo: non tutte le installazioni ne hanno uno configurato, e su
        // WhatsApp/email rispondere al messaggio funziona sempre.
        'invoice_overdue' =>
            "Gentile {name},\n"
            . "dai nostri registri risultano importi non ancora saldati.\n\n"
            . "Fatture non pagate: {count}\n"
            . "Totale dovuto: € {total}\n"
            . "Numeri: {invoices}\n"
            . "Scadenza più remota: {oldest_due}"
            // Le due righe seguenti compaiono solo quando c'è qualcosa da dire:
            // l'IBAN è impostato in Impostazioni → Generale, il link di pagamento
            // esiste solo se i solleciti con link SmallPay sono attivi.
            . "{?bank_details}\nPer pagare con bonifico: {bank_details}{/bank_details}"
            . "{?pay_link}\nOppure paghi online in modo sicuro (carta): {pay_link}{/pay_link}"
            . "\n\nSe il pagamento è già stato effettuato la preghiamo di ignorare questo messaggio "
            . "e, se possibile, di inviarci la contabile.\n"
            . "Per qualsiasi chiarimento può rispondere a questo messaggio.\n"
            . "Grazie, {company}",

        'invoice_paid' => // al CLIENTE: il pagamento online del sollecito è arrivato
            "Grazie {name}! ✅ Abbiamo ricevuto il suo pagamento di {amount} per {description}. "
            . "Lo registriamo in contabilità nei prossimi giorni: non deve fare altro. — {company}",

        'invoice_paid_admin' => // agli AMMINISTRATORI: registrare l'incasso nel gestionale
            "💶 Pagamento online ricevuto: {customer_name} ha pagato {amount} per {description} "
            . "tramite SmallPay. Registra l'incasso nel gestionale e in Sibill — il CRM sospende "
            . "i solleciti a questo cliente per {days} giorni.\n{link}",

        // ---- Contratti SmallPay ----
        // Il link è tutto il messaggio: qualunque preambolo commerciale lo fa
        // ignorare, e qualunque tono da banca lo fa segnalare come phishing.
        // Dire cos'è, quanto costa, e basta.
        'pay_link' =>
            "Gentile {name}, ecco la pagina di pagamento sicura per *{description}* — "
            . "{amount} {every}.\n\n"
            . "{link}\n\n"
            . "La pagina è gestita da SmallPay, il nostro fornitore di pagamenti: "
            . "{company} non vede mai i suoi dati di pagamento. Per qualsiasi dubbio "
            . "può rispondere a questo messaggio.",

        'pay_active' =>
            "Grazie {name}! ✅ Il pagamento di *{description}* è andato a buon fine "
            . "e il contratto è attivo. Addebiteremo {amount} {every} sulla stessa "
            . "conto; può interromperlo quando vuole, basta comunicarcelo. — {company}",

        'pay_failed' => // al CLIENTE: l'addebito è stato rifiutato
            "Gentile {name}, la sua banca non ha autorizzato il pagamento di {amount} "
            . "per *{description}*, quindi non è stato addebitato nulla. Di solito si "
            . "tratta di fondi insufficienti o di un mandato revocato. Risponda a questo "
            . "messaggio e le inviamo un nuovo link di pagamento. — {company}",

        'pay_failed_agent' => // al VENDITORE: è il momento di decidere sul servizio
            "⚠️ Pagamento fallito di {customer_name}: *{description}*, {amount}. "
            . "Rate insolute: {count}. Apri Pagamenti nel CRM per ritentare "
            . "l'addebito o inviare un nuovo link.",

        'test_end_customer' => // al CLIENTE: il noleggio di prova sta per finire
            "Ciao {name}! ⏳ Il tuo periodo di prova della macchina *{model}* "
            . "(matricola {serial}) termina il *{date}*. Se vuoi tenerla, rispondi a "
            . "questo messaggio e prepariamo subito la proposta. Se preferisci la "
            . "restituzione, concordiamo il ritiro. — {company}",

        'test_end_company' => // all'AZIENDA: decidere il fine prova
            "⏳ Fine test tra {days} giorni ({date}): {customer_name} "
            . "({customer_phone}) — macchina {model}, matricola {serial}. "
            . "Contattare il cliente per chiudere: acquisto, noleggio o ritiro.",

        // ---- conteggi provvigioni (Provvigioni) — src/Commission/Statements.php ----
        // Al PARTNER o all'AGENTE: l'ufficio ha caricato un conteggio provvigioni;
        // il link porta alla sua area, dove scarica il conteggio e carica la fattura.
        'commission_statement' =>
            "Ciao {payee_name}, l'ufficio ti ha inviato un conteggio provvigioni:\n"
            . "*{title}* — *{amount}*\n\n"
            . "Scarica il conteggio e carica la tua fattura qui: {link}\n— {company}",

        // Al partner/agente: la fattura è stata rimandata indietro, con il motivo.
        'commission_rejected' =>
            "Ciao {payee_name}, la fattura per *{title}* ({amount}) è stata rimandata indietro.\n"
            . "Motivo: {reason}\n\n"
            . "Correggila e caricala di nuovo qui: {link}\n— {company}",

        // Al partner/agente: pagata.
        'commission_paid' =>
            "Ciao {payee_name}, abbiamo pagato le tue provvigioni *{title}*: *{paid_amount}* il {paid_on}."
            . "{?payment_method}\nModalità: {payment_method}{/payment_method}{?payment_ref}\nRiferimento: {payment_ref}{/payment_ref}\n\n"
            . "Il tuo storico: {link}\n— {company}",

        // All'UFFICIO (ogni amministratore attivo): un partner/agente ha caricato la fattura.
        'commission_invoice_admin' =>
            "🧾 Fattura provvigioni ricevuta da {payee_name}\n"
            . "Conteggio: *{title}* ({amount})\n"
            . "Fattura n. {invoice_number} — {invoice_amount}\n\n"
            . "Da pagare: {link}",

        // Al partner/agente che non emette fattura: il conteggio è pronto e sarà pagato così com'è.
        'commission_statement_noinv' =>
            "Ciao {payee_name}, l'ufficio ha preparato il tuo conteggio provvigioni:\n"
            . "*{title}* — *{amount}*\n\n"
            . "Non serve la fattura: te lo paghiamo noi, per esempio in contanti. Il dettaglio è qui: {link}\n— {company}",

        // ---- provvigioni a rate (src/Commission/Plans.php) ----
        // Al partner/agente: la provvigione su una vendita sarà pagata a rate,
        // man mano che il cliente paga. {schedule} = una riga per rata.
        'commission_plan' =>
            "Ciao {payee_name}, la tua provvigione *{title}*{?customer} ({customer}){/customer} è di *{amount}* "
            . "e ti sarà pagata in {rates} rate, man mano che il cliente paga:\n{schedule}\n\n"
            . "Ogni rata diventa un conteggio appena il cliente la salda. Il dettaglio è qui: {link}\n— {company}",

        // Al partner/agente: il cliente ha pagato una (o più) rate — la quota è maturata.
        'commission_rate_earned' =>
            "Ciao {payee_name}, il cliente{?customer} {customer}{/customer} ha pagato ({rate}): "
            . "è maturata la tua provvigione di *{amount}* — {title}.\n\n"
            . "Carica la fattura qui: {link}\n— {company}",

        // Come sopra, per chi non emette fattura.
        'commission_rate_earned_noinv' =>
            "Ciao {payee_name}, il cliente{?customer} {customer}{/customer} ha pagato ({rate}): "
            . "è maturata la tua provvigione di *{amount}* — {title}.\n\n"
            . "Non serve la fattura: te la paghiamo noi. Il dettaglio è qui: {link}\n— {company}",
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
        'lead_uncontacted_customer' => [
            'subject' => '{company}: possiamo aiutarti?',
            'html'    => '<p>Ciao {name},</p><p>siamo {company}. Abbiamo ricevuto la tua richiesta ma '
                . 'non siamo ancora riusciti a sentirti. Puoi contattarci al <strong>{office_phone}</strong> '
                . 'oppure rispondere a questa email: saremo felici di aiutarti.</p>',
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
        'appointment_agent_set' => [
            'subject' => 'Nuovo appuntamento: {customer_name} — {when}',
            'html'    => '<p>📅 Nuovo appuntamento fissato con <strong>{customer_name}</strong> '
                . 'il <strong>{when}</strong> (#{id}).</p><p>Agente: <strong>{agent_name}</strong></p>',
        ],
        'appointment_agent' => [
            'subject' => 'Promemoria appuntamento: {customer_name}',
            'html'    => '<p>Appuntamento con {customer_name} il <strong>{when}</strong> (#{id}).</p>'
                . '<p>Agente: <strong>{agent_name}</strong></p>',
        ],
        'intervention_confirmed' => [
            'subject' => 'Intervento tecnico fissato per {when} — {company}',
            'html'    => '<p>Ciao {name},</p><p>L\'intervento tecnico è fissato per <strong>{when}</strong>'
                . '{?location} presso <strong>{location}</strong>{/location}.</p>'
                . '{?agent_name}<p>Interviene <strong>{agent_name}</strong>.</p>{/agent_name}<p>Se hai un imprevisto, rispondi a questa email.</p>',
        ],
        'intervention_customer' => [
            'subject' => 'Promemoria: intervento tecnico {company}',
            'html'    => '<p>Ciao {name},</p><p>Ti ricordiamo l\'intervento tecnico in programma <strong>{when}</strong>'
                . '{?location} presso <strong>{location}</strong>{/location}.</p>'
                . '{?agent_name}<p>Ti raggiunge <strong>{agent_name}</strong>.</p>{/agent_name}',
        ],
        'intervention_tech_set' => [
            'subject' => 'Intervento fissato: {customer_name} — {when}',
            'html'    => '<p>🔧 Intervento fissato con <strong>{customer_name}</strong> '
                . '— <strong>{when}</strong>{?location} — {location}{/location} (#{id}).</p>',
        ],
        'intervention_tech' => [
            'subject' => 'Promemoria intervento: {customer_name}',
            'html'    => '<p>Intervento da <strong>{customer_name}</strong> — <strong>{when}</strong>'
                . '{?location} — {location}{/location} (#{id}).</p>',
        ],
        'intervention_taken_over' => [
            'subject' => 'Intervento riassegnato: {customer_name}',
            'html'    => '<p>Ciao {name},</p><p>L\'intervento presso <strong>{customer_name}</strong> '
                . 'del <strong>{when}</strong> (#{id}) è stato assegnato a un collega. '
                . 'Non devi più occupartene.</p>',
        ],
        'planning_prompt' => [
            'subject' => 'Pianifica la giornata di domani ({date})',
            'html'    => '<p>Ciao {name},</p><p>È il momento di organizzare la giornata di '
                . '<strong>{date}</strong>. Hai <strong>{count}</strong> interventi già assegnati e '
                . '<strong>{pool}</strong> in attesa di essere presi in carico.</p>'
                . '<p><a href="{link}">Ho finito: conferma la pianificazione</a></p>',
        ],
        'planning_nudge' => [
            'subject' => 'Sollecito: pianificazione di domani ({date}) non confermata',
            'html'    => '<p>Ciao {name},</p><p>La pianificazione del <strong>{date}</strong> non risulta '
                . 'ancora confermata. Hai <strong>{count}</strong> interventi assegnati e '
                . '<strong>{pool}</strong> ancora da assegnare.</p>'
                . '<p><a href="{link}">Conferma la pianificazione</a></p>',
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
        'maintenance_due' => [
            'subject' => 'Manutenzione periodica — {company}',
            'html'    => '<p>Ciao {name},</p><p>Sono passati circa <strong>{months} mesi</strong> '
                . 'dall\x27ultimo intervento sulla tua attrezzatura.</p>'
                . '<p><a href="{link}">Richiedi un appuntamento di manutenzione</a></p>'
                . '<p>Ti ricontattiamo per concordare giorno e ora.</p>',
        ],
        'password_reset' => [
            'subject' => 'Reimposta la tua password — {company}',
            'html'    => '<p>Ciao {name},</p><p>Hai chiesto di reimpostare la password del CRM di {company}.</p>'
                . '<p><a href="{link}">Scegli una nuova password</a></p>'
                . '<p>Il link vale <strong>{minutes} minuti</strong> e può essere usato una sola volta.</p>'
                . '<p>Se non sei stato tu, ignora questa email: la password attuale resta valida.</p>',
        ],
        'sign_otp' => [
            'subject' => 'Il tuo codice di firma {company}: {code}',
            'html'    => '<p>Ciao {name},</p><p>Il tuo codice monouso per firmare il contratto è:</p>'
                . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px">{code}</p>'
                . '<p>È valido per {minutes} minuti. Non condividerlo con nessuno.</p>',
        ],
        'doc_sign_request' => [
            'subject' => '{company}: firma "{title}"',
            'html'    => '<p>Ciao {name},</p><p>{company} ti ha inviato <strong>"{title}"</strong> da firmare.</p>'
                . '<p>Apri il link qui sotto, leggi il documento e conferma con il codice monouso che ti '
                . 'invieremo. Bastano meno di due minuti.</p>'
                . '<p><a href="{link}">Leggi e firma il documento</a></p>'
                . '<p>Oppure incolla questo link nel browser:<br>{link}</p>',
        ],
        'doc_sign_otp' => [
            'subject' => 'Il tuo codice per firmare "{title}": {code}',
            'html'    => '<p>Ciao {name},</p><p>Il tuo codice monouso per firmare <strong>"{title}"</strong> è:</p>'
                . '<p style="font-size:24px;font-weight:bold;letter-spacing:4px">{code}</p>'
                . '<p>È valido per {minutes} minuti. Non condividerlo con nessuno.</p>',
        ],
        'doc_signed_copy' => [
            'subject' => 'Firmato: "{title}"',
            'html'    => '<p>Ciao {name},</p><p><strong>"{title}"</strong> è stato firmato. Grazie.</p>'
                . '<p>La tua copia firmata contiene il documento originale e un certificato di firma che '
                . 'riporta chi ha firmato, come è stato identificato e quando.</p>'
                . '<p><a href="{link}">Verifica questa firma</a></p>',
        ],
        'doc_signed_staff' => [
            'subject' => 'Firmato: "{title}" — {customer_name}',
            'html'    => '<p><strong>{customer_name}</strong> ha firmato "{title}".</p>'
                . '<p>La copia sigillata e il registro delle operazioni sono nella pagina Documenti del CRM.</p>',
        ],
        'doc_declined_staff' => [
            'subject' => 'Rifiutato: "{title}" — {customer_name}',
            'html'    => '<p><strong>{customer_name}</strong> ha rifiutato di firmare "{title}".</p>'
                . '<p>Motivo indicato: {reason}</p>',
        ],
        'ticket_staff' => [
            'subject' => 'Nuovo messaggio dal cliente — {subject} (#{id})',
            'html'    => '<p>{customer_name} ha inviato un nuovo messaggio sul ticket <strong>#{id}</strong> — "{subject}".</p>'
                . '<p>Apri il CRM per rispondere.</p>',
        ],
        'customer_lead_admin' => [
            'subject' => 'Nuova richiesta da un cliente — {customer_name}',
            'html'    => '<p>Nuova richiesta da un cliente esistente: <strong>{customer_html}</strong> — {what} ({assigned_html}).</p>'
                . '<p>{request_html}</p><p>Da: {from_html}</p>'
                . '<p><a href="{link}">Apri il lead e assegna un agente</a></p>',
        ],
        'customer_maybe_admin' => [
            'subject' => 'Nuova richiesta, forse da un cliente esistente',
            'html'    => '<p>Nuova richiesta, forse da un cliente esistente: <strong>{from_html}</strong> — {what}.</p>'
                . '<p>In anagrafica più clienti hanno questo telefono o email: {cards_html}.</p>'
                . '<p>{request_html}</p>'
                . '<p><a href="{link}">Apri il lead e verifica se è lo stesso cliente</a></p>',
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
        'vat_thanks' => [
            'subject' => 'Nuovo lead registrato — P.IVA {vat} riservata a te',
            'html'    => '<p>Grazie {enterer_name} per aver inserito un nuovo lead!</p>'
                . '<p>La partita IVA <strong>{vat}</strong> ({customer_name}) è riservata a te per '
                . '<strong>{lock_days} giorni</strong>, fino al <strong>{until}</strong>.</p><p>{company}</p>',
        ],
        'vat_taken' => [
            'subject' => 'P.IVA {vat} già inserita da un altro collaboratore',
            'html'    => '<p>Ciao {enterer_name},</p><p>la partita IVA <strong>{vat}</strong> risulta già inserita '
                . 'da un altro collaboratore. Come da regolamento tornerà disponibile per la lavorazione dopo il '
                . 'periodo di {lock_days} giorni, il <strong>{available_date}</strong>.</p><p>{company}</p>',
        ],
        'partner_lead_won' => [
            'subject' => 'La tua segnalazione {customer_name} è stata chiusa',
            'html'    => '<p>Buone notizie {partner_name},</p>'
                . '<p>la tua segnalazione <strong>{customer_name}</strong> è stata chiusa positivamente.</p>'
                . '<p>Grazie per la segnalazione!</p><p>{company}</p>',
        ],
        'partner_lead_lost' => [
            'subject' => 'La tua segnalazione {customer_name} non è andata a buon fine',
            'html'    => '<p>Ciao {partner_name},</p>'
                . '<p>la tua segnalazione <strong>{customer_name}</strong> si è chiusa senza accordo.</p>'
                . '<p>Grazie comunque per la segnalazione — alla prossima.</p><p>{company}</p>',
        ],
        'invoice_overdue' => [
            'subject' => 'Sollecito di pagamento — importi insoluti',
            'html'    => '<p>Gentile {name},</p>'
                . '<p>dai nostri registri risultano importi non ancora saldati.</p>'
                . '<p>Fatture non pagate: <strong>{count}</strong><br>'
                . 'Totale dovuto: <strong>&euro; {total}</strong><br>'
                . 'Numeri: {invoices}<br>'
                . 'Scadenza più remota: <strong>{oldest_due}</strong> ({days_late} giorni fa)</p>'
                . '{?bank_details}<p>Per pagare con bonifico: <strong>{bank_details}</strong></p>{/bank_details}'
                . '{?pay_link}<p>Oppure paghi online in modo sicuro (carta): <a href="{pay_link}">Apri la pagina di pagamento</a><br>'
                . 'Se il link non si apre, copi questo indirizzo nel browser: {pay_link}</p>{/pay_link}'
                . '<p>Se il pagamento è già stato effettuato la preghiamo di ignorare questa comunicazione e, '
                . 'se possibile, di inviarci la contabile.</p>'
                . '<p>Per qualsiasi chiarimento può rispondere a questa email.</p>'
                . '<p>Cordiali saluti,<br>{company}</p>',
        ],
        'invoice_paid' => [
            'subject' => 'Pagamento ricevuto — {description}',
            'html'    => '<p>Gentile {name},</p>'
                . '<p>grazie: abbiamo ricevuto il suo pagamento di <strong>{amount}</strong> per '
                . '<strong>{description}</strong>.</p>'
                . '<p>Lo registriamo in contabilità nei prossimi giorni: non deve fare altro.</p>'
                . '<p>Cordiali saluti,<br>{company}</p>',
        ],
        'invoice_paid_admin' => [
            'subject' => 'Pagamento online ricevuto — {customer_name}',
            'html'    => '<p>Pagamento online ricevuto: <strong>{customer_html}</strong> ha pagato '
                . '<strong>{amount}</strong> per {description_html} tramite SmallPay.</p>'
                . '<p>Registra l\'incasso nel gestionale e in Sibill. Il CRM sospende i solleciti a questo '
                . 'cliente per {days} giorni.</p>'
                . '<p><a href="{link}">Apri la scheda del cliente</a></p>',
        ],

        // ---- Contratti SmallPay ----
        'pay_link' => [
            'subject' => 'Il suo link di pagamento — {description}',
            'html'    => '<p>Gentile {name},</p>'
                . '<p>ecco la pagina di pagamento sicura per <strong>{description}</strong>.</p>'
                . '<p>Importo: <strong>{amount}</strong> {every}</p>'
                . '<p><a href="{link}">Apri la pagina di pagamento</a></p>'
                . '<p>La pagina è gestita da SmallPay, il nostro fornitore di pagamenti, quindi '
                . '{company} non vede mai i suoi dati di pagamento. Se il link non si apre, copi '
                . 'questo indirizzo nel browser:<br>{link}</p>'
                . '<p>Per qualsiasi dubbio può rispondere a questa email.</p>'
                . '<p>Cordiali saluti,<br>{company}</p>',
        ],
        'pay_active' => [
            'subject' => 'Pagamento ricevuto — {description}',
            'html'    => '<p>Gentile {name},</p>'
                . '<p>grazie. Il pagamento di <strong>{description}</strong> è andato a buon fine '
                . 'e il contratto è ora attivo.</p>'
                . '<p>Addebiteremo <strong>{amount}</strong> {every} sullo stesso conto. Può '
                . 'interrompere il contratto quando vuole comunicandocelo: non è previsto alcun '
                . 'preavviso.</p>'
                . '<p>Cordiali saluti,<br>{company}</p>',
        ],
        'pay_failed' => [
            'subject' => 'Pagamento non riuscito — {description}',
            'html'    => '<p>Gentile {name},</p>'
                . '<p>la sua banca non ha autorizzato il pagamento di <strong>{amount}</strong> per '
                . '<strong>{description}</strong>, quindi non le è stato addebitato nulla.</p>'
                . '<p>Di norma si tratta di fondi insufficienti o di un mandato revocato, non di un '
                . 'problema da parte sua. Risponda a questa email e le invieremo un nuovo link '
                . 'di pagamento.</p>'
                . '<p>Cordiali saluti,<br>{company}</p>',
        ],
        'pay_failed_agent' => [
            'subject' => 'Pagamento fallito: {customer_name}',
            'html'    => '<p>{customer_name} — <strong>{description}</strong>, {amount}.</p>'
                . '<p>Rate insolute: <strong>{count}</strong>.</p>'
                . '<p>Apri <strong>Pagamenti</strong> nel CRM per ritentare l\'addebito o inviare '
                . 'un nuovo link.</p>',
        ],
        'test_end_customer' => [
            'subject' => 'Il tuo periodo di prova termina il {date}',
            'html'    => '<p>Ciao {name},</p>'
                . '<p>il periodo di prova della macchina <strong>{model}</strong> '
                . '(matricola {serial}) termina il <strong>{date}</strong>.</p>'
                . '<p>Se vuoi tenerla, rispondi a questa email e prepariamo subito la proposta. '
                . 'Se preferisci la restituzione, concordiamo insieme il ritiro.</p>'
                . '<p>Un saluto,<br>{company}</p>',
        ],
        'test_end_company' => [
            'subject' => 'Fine test tra {days} giorni: {customer_name}',
            'html'    => '<p>Il periodo di prova di <strong>{customer_name}</strong> '
                . '({customer_phone}) termina il <strong>{date}</strong>.</p>'
                . '<p>Macchina: <strong>{model}</strong> — matricola {serial}.</p>'
                . '<p>Contattare il cliente per chiudere: acquisto, noleggio o ritiro.</p>',
        ],

        // ---- conteggi provvigioni ----
        'commission_statement' => [
            'subject' => 'Conteggio provvigioni: {title} — {amount}',
            'html'    => '<p>Ciao {payee_name},</p>'
                . '<p>l’ufficio ti ha inviato un conteggio provvigioni: <strong>{title}</strong>, per <strong>{amount}</strong>.</p>'
                . '<p>Scarica il conteggio e carica la tua fattura dalla tua area: <a href="{link}">{link}</a></p>'
                . '<p>{company}</p>',
        ],
        'commission_rejected' => [
            'subject' => 'Fattura provvigioni da correggere: {title}',
            'html'    => '<p>Ciao {payee_name},</p>'
                . '<p>la fattura per <strong>{title}</strong> ({amount}) è stata rimandata indietro.</p>'
                . '<p>Motivo: <strong>{reason}</strong></p>'
                . '<p>Correggila e caricala di nuovo: <a href="{link}">{link}</a></p><p>{company}</p>',
        ],
        'commission_paid' => [
            'subject' => 'Provvigioni pagate: {title} — {paid_amount}',
            'html'    => '<p>Ciao {payee_name},</p>'
                . '<p>abbiamo pagato le tue provvigioni <strong>{title}</strong>: <strong>{paid_amount}</strong> il {paid_on}.</p>'
                . '{?payment_method}<p>Modalità: {payment_method}</p>{/payment_method}{?payment_ref}<p>Riferimento: {payment_ref}</p>{/payment_ref}'
                . '<p>Lo storico delle tue provvigioni e fatture: <a href="{link}">{link}</a></p><p>{company}</p>',
        ],
        'commission_invoice_admin' => [
            'subject' => 'Fattura provvigioni ricevuta da {payee_name} — {invoice_amount}',
            'html'    => '<p>🧾 <strong>{payee_name}</strong> ha caricato la fattura per il conteggio <strong>{title}</strong> ({amount}).</p>'
                . '<p>Fattura n. <strong>{invoice_number}</strong> — {invoice_amount}</p>'
                . '<p><a href="{link}">Apri e registra il pagamento</a></p>',
        ],

        'commission_statement_noinv' => [
            'subject' => 'Conteggio provvigioni: {title} — {amount}',
            'html'    => '<p>Ciao {payee_name},</p>'
                . '<p>l’ufficio ha preparato il tuo conteggio provvigioni: <strong>{title}</strong>, per <strong>{amount}</strong>.</p>'
                . '<p>Non serve la fattura: te lo paghiamo noi, per esempio in contanti. Il dettaglio è nella tua area: <a href="{link}">{link}</a></p>'
                . '<p>{company}</p>',
        ],

        'commission_plan' => [
            'subject' => 'Provvigione a rate: {title} — {amount}',
            'html'    => '<p>Ciao {payee_name},</p>'
                . '<p>la tua provvigione <strong>{title}</strong>{?customer} ({customer}){/customer} è di <strong>{amount}</strong> '
                . 'e ti sarà pagata in {rates} rate, man mano che il cliente paga:</p>'
                . '<p>{schedule_html}</p>'
                . '<p>Ogni rata diventa un conteggio appena il cliente la salda. Il dettaglio è nella tua area: <a href="{link}">{link}</a></p>'
                . '<p>{company}</p>',
        ],
        'commission_rate_earned' => [
            'subject' => 'Provvigione maturata: {amount} — {title}',
            'html'    => '<p>Ciao {payee_name},</p>'
                . '<p>il cliente{?customer} <strong>{customer}</strong>{/customer} ha pagato ({rate}): '
                . 'è maturata la tua provvigione di <strong>{amount}</strong> — {title}.</p>'
                . '<p>Carica la fattura dalla tua area: <a href="{link}">{link}</a></p><p>{company}</p>',
        ],
        'commission_rate_earned_noinv' => [
            'subject' => 'Provvigione maturata: {amount} — {title}',
            'html'    => '<p>Ciao {payee_name},</p>'
                . '<p>il cliente{?customer} <strong>{customer}</strong>{/customer} ha pagato ({rate}): '
                . 'è maturata la tua provvigione di <strong>{amount}</strong> — {title}.</p>'
                . '<p>Non serve la fattura: te la paghiamo noi. Il dettaglio è nella tua area: <a href="{link}">{link}</a></p><p>{company}</p>',
        ],
    ],
];
