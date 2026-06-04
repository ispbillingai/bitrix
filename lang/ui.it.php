<?php
/**
 * Stringhe UI in italiano per il pannello. Le chiavi rispecchiano ui.en.php.
 */
return [
    // chrome
    'app_title'      => 'Bitrix24 Glue',
    'app_subtitle'   => 'Pannello di controllo automazioni lead',
    'language'       => 'Lingua',
    'logout'         => 'Esci',

    // login
    'login_title'    => 'Bitrix24 Glue',
    'login_sub'      => 'Accedi al pannello di controllo',
    'login_ph'       => 'Password',
    'login_btn'      => 'Accedi',
    'login_err'      => 'Password errata',

    // nav
    'nav_overview'   => 'Panoramica',
    'nav_setup'      => 'Configurazione',
    'nav_leads'      => 'Lead e Trattative',
    'nav_reminders'  => 'Promemoria',
    'nav_messages'   => 'Messaggi',
    'nav_campaigns'  => 'Campagne',
    'nav_events'     => 'Registro attività',
    'nav_instr'      => 'Istruzioni',

    // common
    'save'           => 'Salva',
    'saved'          => 'Salvato.',
    'configured'     => 'Configurato',
    'not_configured' => 'Non configurato',
    'none_yet'       => 'Ancora niente.',
    'cancel'         => 'Annulla',
    'refresh'        => 'Aggiorna',

    // overview
    'ov_title'         => 'Panoramica',
    'ov_status'        => 'Stato del sistema',
    'st_db'            => 'Database',
    'st_bitrix'        => 'Connessione Bitrix24',
    'st_whatsapp'      => 'WhatsApp (TextMeBot)',
    'st_mail'          => 'Email',
    'ov_pending'       => 'Promemoria in attesa',
    'ov_sent'          => 'Messaggi inviati',
    'ov_failed'        => 'Messaggi falliti',
    'ov_leads'         => 'Lead/trattative tracciati',
    'ov_campaigns'     => 'Campagne',
    'ov_run'           => 'Esegui scheduler ora',
    'ov_ran'           => 'Scheduler eseguito.',
    'ov_cron_hint'     => 'Lo scheduler viene eseguito ogni minuto via cron. Usa questo pulsante per svuotare subito la coda.',

    // setup
    'setup_title'    => 'Configurazione',
    'setup_intro'    => 'Configura qui l\'integrazione. Questi valori sono salvati nel database e hanno effetto immediato — nessuna modifica ai file. Solo le credenziali del database restano in config.php.',
    'sec_bitrix'     => 'Bitrix24',
    'sec_whatsapp'   => 'WhatsApp (TextMeBot)',
    'sec_mail'       => 'Email',
    'sec_logistics'  => 'Logistica',
    'sec_general'    => 'Generale',
    'sec_stages'     => 'Fasi della pipeline',
    'sec_cadence'    => 'Tempistica promemoria',

    'f_bitrix_url'   => 'URL webhook in entrata',
    'f_bitrix_url_h' => 'Bitrix24 → Risorse per sviluppatori → Webhook in entrata. Incolla l\'URL completo con il token e la barra finale.',
    'f_outbound'     => 'Segreto in uscita',
    'f_outbound_h'   => 'Usato nell\'URL del gestore che Bitrix richiama. Tienilo segreto.',
    'f_intake'       => 'Segreto raccolta form',
    'f_intake_h'     => 'Richiesto negli URL dei form/webhook qui sotto.',
    'f_lead_new'     => 'Primo stato lead ("Da lavorare")',
    'f_deal_quote'   => 'Fase trattativa = preventivo inviato',
    'f_deal_signed'  => 'Fase trattativa = firmata/vinta',
    'f_stages_h'     => 'ID dal tuo portale. Elencali con crm.status.list.',
    'f_tmb_key'      => 'Chiave API TextMeBot',
    'f_tmb_key_h'    => 'Dal tuo account TextMeBot. Senza, nessun WhatsApp viene inviato.',
    'f_from_name'    => 'Nome mittente / azienda',
    'f_from_name_h'  => 'Mostrato ai clienti in ogni messaggio come azienda.',
    'f_from_email'   => 'Email mittente',
    'f_log_email'    => 'Email logistica',
    'f_log_phone'    => 'WhatsApp logistica (opzionale, E.164)',
    'f_default_lang' => 'Lingua predefinita',
    'f_tz'           => 'Fuso orario',

    'test_title'     => 'Prova connessioni',
    'test_bitrix'    => 'Prova Bitrix24',
    'test_wa'        => 'Invia WhatsApp di prova',
    'test_email'     => 'Invia email di prova',
    'test_send'      => 'Invia',
    'test_phone_ph'  => '+39...',
    'test_email_ph'  => 'nome@esempio.com',
    'test_ok'        => 'Riuscito',
    'test_fail'      => 'Fallito',

    // urls box
    'urls_title'     => 'I tuoi URL di integrazione',
    'urls_intro'     => 'Usali per collegare Bitrix24 e i tuoi form (Jotform / sito web).',
    'url_form'       => 'Raccolta form / lead (POST)',
    'url_bitrix_ev'  => 'Gestore webhook in uscita Bitrix24',
    'url_appt'       => 'Raccolta appuntamenti (POST)',
    'url_campaign'   => 'Crea campagna (POST)',

    // leads
    'leads_title'    => 'Lead e Trattative tracciati',
    'th_type'        => 'Tipo',
    'th_bitrix_id'   => 'ID Bitrix',
    'th_stage'       => 'Fase',
    'th_customer'    => 'Cliente',
    'th_lang'        => 'Lingua',
    'th_received'    => 'Ricevuto',
    'th_status'      => 'Stato',

    // reminders
    'rem_title'      => 'Coda promemoria',
    'th_due'         => 'Scadenza',
    'th_rule'        => 'Regola',
    'th_recipient'   => 'Destinatario',
    'th_channel'     => 'Canale',
    'rem_cancelled'  => 'Promemoria annullato.',
    'filter_pending' => 'In attesa',
    'filter_all'     => 'Tutti',

    // messages
    'msg_title'      => 'Posta in uscita',
    'th_time'        => 'Ora',
    'th_subject'     => 'Oggetto',

    // campaigns
    'camp_title'     => 'Campagne',
    'camp_new'       => 'Nuova campagna',
    'camp_name'      => 'Nome',
    'camp_channel'   => 'Canale',
    'camp_subject'   => 'Oggetto (solo email)',
    'camp_body'      => 'Messaggio (ammessi {name} e {company})',
    'camp_recipients'=> 'Destinatari (uno per riga: telefono o email)',
    'camp_create'    => 'Crea e accoda',
    'camp_created'   => 'Campagna accodata.',
    'th_total'       => 'Totale',
    'th_sent'        => 'Inviati',
    'th_failed'      => 'Falliti',
    'camp_warn'      => 'Nota: TextMeBot usa un solo numero WhatsApp — invii massivi rischiano il blocco. Per vero marketing di massa usa l\'API ufficiale WhatsApp Business.',

    // events
    'ev_title'       => 'Registro attività',
    'th_source'      => 'Origine',
    'th_event'       => 'Evento',
    'th_entity'      => 'Entità',

    // instructions (prose)
    'instr_title'    => 'Istruzioni',
    'instr_intro'    => 'Questo sistema si interpone tra i tuoi form/sito e Bitrix24. Acquisisce i lead, invia WhatsApp ed email automaticamente, gestisce i timer che Bitrix Standard non può fare e avvisa la logistica quando una trattativa è firmata. Segui questi passi in ordine.',
    'instr_s1_t'     => '1. Collega Bitrix24 (in entrata)',
    'instr_s1'       => 'In Bitrix24 vai su <b>Risorse per sviluppatori → Altro → Webhook in entrata</b>. Seleziona gli ambiti <b>crm</b> e <b>user</b>. Copia l\'URL del webhook (termina con un token) e incollalo in <b>Configurazione → Bitrix24 → URL webhook in entrata</b>, mantenendo la barra finale. Clicca <b>Prova Bitrix24</b> — deve indicare Riuscito.',
    'instr_s2_t'     => '2. Fai notificare Bitrix24 (in uscita)',
    'instr_s2'       => 'In Bitrix24 vai su <b>Risorse per sviluppatori → Altro → Webhook in uscita</b>. Imposta l\'URL del gestore su quello indicato come “Gestore webhook in uscita Bitrix24” nella pagina Configurazione (include già il segreto). Iscriviti agli eventi <b>ONCRMLEADUPDATE, ONCRMDEALADD, ONCRMDEALUPDATE</b>. È questo che attiva il messaggio col profilo dell\'agente, il silenziamento dell\'inattività, i promemoria di firma e i passi di ringraziamento/logistica.',
    'instr_s3_t'     => '3. Aggiungi la chiave WhatsApp',
    'instr_s3'       => 'Incolla la tua <b>chiave API TextMeBot</b> in Configurazione → WhatsApp e Salva. Usa <b>Invia WhatsApp di prova</b> al tuo numero per verificare. Finché non è impostata, il sistema crea comunque i lead e accoda i messaggi, ma nulla viene consegnato su WhatsApp.',
    'instr_s4_t'     => '4. Indirizza i tuoi form',
    'instr_s4'       => 'In Jotform (o nel form del sito) aggiungi un webhook/POST all\'URL “Raccolta form / lead” della pagina Configurazione. Mappa i campi <b>name, phone, email</b> (funzionano anche alias come telefono/messaggio). Aggiungi un campo opzionale <b>lang</b> (en/it) per scrivere a ogni cliente nella sua lingua; altrimenti si usa la lingua predefinita.',
    'instr_s5_t'     => '5. Imposta gli ID delle fasi',
    'instr_s5'       => 'In Configurazione → Fasi della pipeline inserisci gli ID di stato/fase del tuo portale: il primo stato lead (“Da lavorare”), la fase che indica “preventivo inviato” (avvia i promemoria di firma) e la fase “firmata/vinta” (invia il ringraziamento e avvisa la logistica). Puoi elencare gli ID in Bitrix con crm.status.list.',
    'instr_s6_t'     => '6. Vai in produzione',
    'instr_s6'       => 'Compila il nome mittente/azienda e l\'email della logistica. Assicurati che la riga cron dello scheduler sia installata (invia i messaggi accodati ogni minuto). Osserva le schede <b>Registro attività</b> e <b>Messaggi</b> per vedere il flusso. Qualsiasi automazione può essere silenziata in qualunque momento spostando la trattativa di fase.',
    'instr_manual_t' => 'Interrompere un\'automazione',
    'instr_manual'   => 'Ogni promemoria a tempo viene annullato automaticamente quando l\'agente sposta il lead/trattativa fuori dalla fase attesa. Quindi per silenziare un promemoria basta cambiare fase in Bitrix24 — qui non serve fare nulla.',
];
