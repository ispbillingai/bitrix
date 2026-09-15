<?php
declare(strict_types=1);

namespace Glue\Ai;

use Glue\Crm\Activities;
use Glue\Crm\Appointments;
use Glue\Crm\Contacts;
use Glue\Crm\Customers;
use Glue\Crm\Deals;
use Glue\Crm\Leads;
use Glue\Crm\Pipelines;
use Glue\Crm\Tasks;
use Glue\Crm\Tickets;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Sibill\Invoices;
use Throwable;

/**
 * What the assistant can do in the CRM, and for whom.
 *
 * Every tool runs AS the person asking: an agent's assistant sees the agent's
 * leads, deals, customers and tickets and nothing else — the same scope the
 * dashboard gives them — an admin's sees everything, a technician's sees
 * customers, its own tickets and installations. The scope is not something
 * the model can argue its way around: it is applied inside each function,
 * on the query, before any data exists to be shown.
 *
 * Reading is free. Anything that CHANGES the CRM or reaches a customer
 * (create a task, add a note, message or WhatsApp a customer, move a lead,
 * book an appointment…) is an ACTION: the model proposes it, the person
 * confirms it in the chat, and only then does execute() run it. The model
 * is told this in the tool result, so it never claims an action is done.
 *
 * $ctx = ['uid' => int, 'role' => 'admin'|'agent'|'tech', 'name' => string, 'lang' => 'it'|'en']
 */
final class Tools
{
    /** The tools that change something. Proposed, confirmed, then run. */
    public const ACTIONS = [
        'create_task', 'add_note', 'send_message_to_customer', 'send_whatsapp', 'set_ticket_status',
        'move_lead_stage', 'move_deal_stage', 'update_lead', 'create_lead', 'book_appointment',
    ];

    // ---- definitions -----------------------------------------------------------------

    /** Tool definitions for the API, filtered to what this role may use. camelCase keys: the SDK maps them. */
    public static function definitions(array $ctx): array
    {
        $role = (string)$ctx['role'];
        $isAdmin = $role === 'admin';
        $str = fn(string $d) => ['type' => 'string', 'description' => $d];
        $int = fn(string $d) => ['type' => 'integer', 'description' => $d];
        $obj = fn(array $props, array $req = []) => ['type' => 'object', 'properties' => $props, 'required' => $req];
        $tool = fn(string $name, string $desc, array $schema) => ['name' => $name, 'description' => $desc, 'inputSchema' => $schema];

        $defs = [
            $tool('search_customers', 'Cerca clienti e contatti nel CRM per nome, azienda, partita IVA, codice cliente, telefono, email o città. Restituisce al massimo 15 risultati con il loro id (contact_id). Usalo per trovare il contact_id prima di get_customer.',
                $obj(['query' => $str('Testo da cercare (almeno 2 caratteri)')], ['query'])),
            $tool('get_customer', 'La scheda completa di un cliente/contatto: dati anagrafici, lead, trattative, ticket con gli ultimi messaggi, appuntamenti, attività, documenti, rapporti di installazione' . ($isAdmin ? ', fatture aperte/scadute e contratti' : '') . '.',
                $obj(['contact_id' => $int('Id del contatto (da search_customers)')], ['contact_id'])),
            $tool('search_records', 'Cerca tra lead, trattative, ticket, attività e appuntamenti per testo (nome cliente, oggetto, titolo, note). Restituisce righe compatte con id e stato.',
                $obj(['query' => $str('Testo da cercare'), 'type' => ['type' => 'string', 'enum' => ['all', 'lead', 'deal', 'ticket', 'task', 'appointment'], 'description' => 'Tipo di record, default all']], ['query'])),
            $tool('get_ticket', 'Un ticket (conversazione con un cliente) con tutti i suoi messaggi in ordine cronologico. Usalo per riassumere una conversazione o capire un problema.',
                $obj(['ticket_id' => $int('Id del ticket')], ['ticket_id'])),
            $tool('list_tasks', 'Elenco delle attività (task): aperte, scadute o completate, opzionalmente di una persona.',
                $obj(['status' => ['type' => 'string', 'enum' => ['open', 'overdue', 'done', 'all'], 'description' => 'default open'],
                      'assigned_to' => $int('Id utente (solo admin; gli altri vedono le proprie)')])),
            $tool('list_appointments', 'Appuntamenti confermati o richiesti in una finestra di giorni (default: prossimi 7).',
                $obj(['days_ahead' => $int('Giorni da oggi in avanti, default 7'), 'days_back' => $int('Giorni indietro, default 0'),
                      'agent_id' => $int('Id agente (solo admin)')])),
            $tool('list_staff', 'Le persone del team con id, nome e ruolo — serve per assegnare attività o appuntamenti.', $obj([])),
        ];
        if ($role !== 'tech') {
            $defs[] = $tool('get_lead', 'Un lead con la sua cronologia (note, cambi di fase), i preventivi e gli appuntamenti collegati.',
                $obj(['lead_id' => $int('Id del lead')], ['lead_id']));
            $defs[] = $tool('get_deal', 'Una trattativa con la sua cronologia.', $obj(['deal_id' => $int('Id della trattativa')], ['deal_id']));
            $defs[] = $tool('pipeline_report', 'Fotografia della pipeline: lead e trattative per fase con importi, ticket aperti, attività scadute, appuntamenti della settimana. Base per report e analisi.', $obj([]));
        }
        if ($isAdmin) {
            $defs[] = $tool('leads_by_source', 'Report mensile dei lead per origine (ricevuti, convertiti, scartati, aperti).',
                $obj(['month' => $str('Mese YYYY-MM, default il mese corrente')]));
            $defs[] = $tool('invoices_report', 'Fatture (Sibill): totali aperti e scaduti, e i clienti con più scaduto.', $obj([]));
        }

        // ---- actions ----
        $defs[] = $tool('create_task', 'AZIONE (richiede conferma dell\'utente): crea un\'attività nel CRM. Se l\'utente chiede "ricordami di…" o "crea un task…", usa questa.',
            $obj(['title' => $str('Titolo breve'), 'description' => $str('Dettagli (opzionale)'),
                  'due_at' => $str('Scadenza "YYYY-MM-DD HH:MM" (opzionale)'),
                  'assigned_to' => $int('Id utente a cui assegnarla (solo admin; gli altri la ricevono in proprio)'),
                  'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
                  'related_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact', 'ticket'], 'description' => 'Record collegato (opzionale)'],
                  'related_id' => $int('Id del record collegato')], ['title']));
        $defs[] = $tool('add_note', 'AZIONE (richiede conferma): aggiunge una nota alla cronologia di un lead, di una trattativa o di un contatto.',
            $obj(['entity_type' => ['type' => 'string', 'enum' => ['lead', 'deal', 'contact']], 'entity_id' => $int('Id del record'),
                  'body' => $str('Testo della nota')], ['entity_type', 'entity_id', 'body']));
        $defs[] = $tool('send_message_to_customer', 'AZIONE (richiede conferma): scrive al cliente nella sua chat del CRM (area clienti). Il cliente riceve un avviso via WhatsApp ed email con il link per leggere e rispondere. Se esiste già una conversazione aperta con lui, il messaggio va in quella; altrimenti ne apre una nuova con l\'oggetto dato.',
            $obj(['contact_id' => $int('Id del contatto'), 'subject' => $str('Oggetto (usato solo per una nuova conversazione)'),
                  'body' => $str('Testo del messaggio, già pronto per il cliente')], ['contact_id', 'body']));
        $defs[] = $tool('send_whatsapp', 'AZIONE (richiede conferma): invia un WhatsApp diretto al numero del cliente dal numero aziendale. Per messaggi brevi e immediati; per conversazioni usa send_message_to_customer.',
            $obj(['contact_id' => $int('Id del contatto'), 'text' => $str('Testo del WhatsApp')], ['contact_id', 'text']));
        $defs[] = $tool('set_ticket_status', 'AZIONE (richiede conferma): cambia lo stato di un ticket (open = da gestire, pending = in attesa del cliente, closed = chiuso).',
            $obj(['ticket_id' => $int('Id del ticket'), 'status' => ['type' => 'string', 'enum' => ['open', 'pending', 'closed']]], ['ticket_id', 'status']));
        if ($role !== 'tech') {
            $stagesL = implode(', ', array_map(fn($s) => $s['code'] . ' (' . $s['name'] . ')', Pipelines::stagesForEntity('lead')));
            $stagesD = implode(', ', array_map(fn($s) => $s['code'] . ' (' . $s['name'] . ')', Pipelines::stagesForEntity('deal')));
            $defs[] = $tool('move_lead_stage', 'AZIONE (richiede conferma): sposta un lead in un\'altra fase della pipeline. Fasi: ' . $stagesL . '.',
                $obj(['lead_id' => $int('Id del lead'), 'stage_code' => $str('Codice fase'), 'note' => $str('Nota facoltativa')], ['lead_id', 'stage_code']));
            $defs[] = $tool('move_deal_stage', 'AZIONE (richiede conferma): sposta una trattativa in un\'altra fase. Fasi: ' . $stagesD . '.',
                $obj(['deal_id' => $int('Id della trattativa'), 'stage_code' => $str('Codice fase')], ['deal_id', 'stage_code']));
            $defs[] = $tool('update_lead', 'AZIONE (richiede conferma): aggiorna i campi di un lead (nome, telefono, email, azienda, partita IVA, zona, note). Passa solo i campi da cambiare.',
                $obj(['lead_id' => $int('Id del lead'), 'name' => $str('Nome e cognome'), 'phone' => $str('Telefono'), 'email' => $str('Email'),
                      'company' => $str('Azienda'), 'vat_number' => $str('Partita IVA'), 'zone' => $str('Zona'), 'comments' => $str('Note / richiesta')], ['lead_id']));
            $defs[] = $tool('create_lead', 'AZIONE (richiede conferma): crea un nuovo lead. Se il telefono è già di un lead aperto o di un cliente in anagrafica, il CRM lo rifiuta e lo dice.',
                $obj(['name' => $str('Nome e cognome'), 'phone' => $str('Telefono'), 'email' => $str('Email'), 'company' => $str('Azienda'),
                      'vat_number' => $str('Partita IVA'), 'zone' => $str('Zona'), 'comments' => $str('Cosa chiede il cliente'),
                      'source' => $str('Origine, default manual')], ['name']));
            $defs[] = $tool('book_appointment', 'AZIONE (richiede conferma): fissa un appuntamento con un cliente o un lead. Il cliente e l\'agente ricevono conferma e promemoria.',
                $obj(['lead_id' => $int('Id del lead (opzionale)'), 'contact_id' => $int('Id del contatto (opzionale)'),
                      'name' => $str('Nome del cliente'), 'phone' => $str('Telefono del cliente'), 'email' => $str('Email del cliente'),
                      'starts_at' => $str('Data e ora "YYYY-MM-DD HH:MM"'), 'agent_id' => $int('Id agente (solo admin; gli altri sono l\'agente)'),
                      'title' => $str('Titolo'), 'location' => $str('Luogo')], ['name', 'starts_at']));
        }
        return $defs;
    }

    public static function isAction(string $name): bool
    {
        return in_array($name, self::ACTIONS, true);
    }

    // ---- reads -----------------------------------------------------------------------

    /** Run a read tool. Returns text for the model. Unknown or forbidden = a clear refusal, never an exception. */
    public static function read(string $name, array $in, array $ctx): string
    {
        try {
            $allowed = array_column(self::definitions($ctx), 'name');
            if (!in_array($name, $allowed, true)) {
                return 'Strumento non disponibile per questo utente.';
            }
            return match ($name) {
                'search_customers'  => self::searchCustomers((string)($in['query'] ?? ''), $ctx),
                'get_customer'      => self::getCustomer((int)($in['contact_id'] ?? 0), $ctx),
                'search_records'    => self::searchRecords((string)($in['query'] ?? ''), (string)($in['type'] ?? 'all'), $ctx),
                'get_ticket'        => self::getTicket((int)($in['ticket_id'] ?? 0), $ctx),
                'get_lead'          => self::getLead((int)($in['lead_id'] ?? 0), $ctx),
                'get_deal'          => self::getDeal((int)($in['deal_id'] ?? 0), $ctx),
                'list_tasks'        => self::listTasks((string)($in['status'] ?? 'open'), (int)($in['assigned_to'] ?? 0), $ctx),
                'list_appointments' => self::listAppointments((int)($in['days_ahead'] ?? 7), (int)($in['days_back'] ?? 0), (int)($in['agent_id'] ?? 0), $ctx),
                'list_staff'        => self::listStaff(),
                'pipeline_report'   => self::pipelineReport($ctx),
                'leads_by_source'   => self::leadsBySource((string)($in['month'] ?? '')),
                'invoices_report'   => self::invoicesReport(),
                default             => 'Strumento sconosciuto.',
            };
        } catch (Throwable $e) {
            Log::write('ai', 'tool_failed', null, null, ['tool' => $name, 'error' => $e->getMessage()]);
            return 'Errore nello strumento: ' . $e->getMessage();
        }
    }

    private static function j(mixed $v): string
    {
        return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function scope(array $ctx): ?int
    {
        return $ctx['role'] === 'agent' ? (int)$ctx['uid'] : null;
    }

    /** May this user look at this contact? Admins and technicians anyone; agents their own customers. */
    private static function mayContact(array $ctx, int $contactId): bool
    {
        if ($contactId <= 0) {
            return false;
        }
        if ($ctx['role'] === 'agent') {
            return Tickets::mayMessage((int)$ctx['uid'], $contactId);
        }
        return Contacts::find($contactId) !== null;
    }

    private static function leadVisible(array $ctx, array $lead): bool
    {
        return $ctx['role'] === 'admin' || ($ctx['role'] === 'agent' && (int)($lead['assigned_to'] ?? 0) === (int)$ctx['uid']);
    }

    private static function ticketVisible(array $ctx, array $t): bool
    {
        return $ctx['role'] === 'admin' || (int)($t['assigned_agent_id'] ?? 0) === (int)$ctx['uid'];
    }

    private static function searchCustomers(string $q, array $ctx): string
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return 'Scrivi almeno 2 caratteri.';
        }
        if ($ctx['role'] === 'agent') {
            $rows = Tickets::searchCustomersForStaff((int)$ctx['uid'], $q, 15);
            if (!$rows) {
                return 'Nessun cliente tra i tuoi corrisponde a "' . $q . '". (Vedi solo i clienti dei tuoi lead e delle tue trattative.)';
            }
            return self::j(array_map(fn($c) => ['contact_id' => (int)$c['id'], 'name' => $c['name'], 'company' => $c['company'],
                'phone' => $c['phone'], 'email' => $c['email']], $rows));
        }
        $like = '%' . $q . '%';
        $s = Db::pdo()->prepare(
            'SELECT id, name, company, customer_code, vat_number, phone, phone2, email, city, is_customer
               FROM contacts
              WHERE name LIKE ? OR company LIKE ? OR vat_number LIKE ? OR customer_code = ? OR phone LIKE ? OR phone2 LIKE ?
                 OR email LIKE ? OR city LIKE ?
              ORDER BY is_customer DESC, name LIMIT 15'
        );
        $s->execute([$like, $like, $like, $q, $like, $like, $like, $like]);
        $rows = $s->fetchAll();
        if (!$rows) {
            return 'Nessun contatto corrisponde a "' . $q . '".';
        }
        return self::j(array_map(fn($c) => [
            'contact_id' => (int)$c['id'], 'name' => $c['name'], 'company' => $c['company'],
            'customer_code' => $c['customer_code'], 'vat' => $c['vat_number'], 'phone' => $c['phone'] ?: $c['phone2'],
            'email' => $c['email'], 'city' => $c['city'], 'registry_customer' => (bool)$c['is_customer'],
        ], $rows));
    }

    private static function getCustomer(int $cid, array $ctx): string
    {
        if (!self::mayContact($ctx, $cid)) {
            return 'Contatto non trovato o non visibile a questo utente.';
        }
        $ov = Customers::overview($cid);
        if (!$ov) {
            return 'Contatto non trovato.';
        }
        $c = $ov['contact'];
        $out = ['contact' => [
            'contact_id' => (int)$c['id'], 'name' => $c['name'], 'company' => $c['company'], 'customer_code' => $c['customer_code'],
            'vat' => $c['vat_number'], 'phone' => $c['phone'], 'phone2' => $c['phone2'], 'email' => $c['email'], 'pec' => $c['pec'] ?? null,
            'address' => trim(implode(' ', array_filter([$c['address'], $c['zip'], $c['city'], $c['province']]))),
            'registry_customer' => (bool)$c['is_customer'], 'customer_since' => $c['customer_since'],
            'gestionale_agent' => $c['gestionale_agent'], 'contract_expiry' => $c['contract_expiry'],
            'portal_enabled' => (bool)$c['portal_enabled'], 'notes' => $c['notes'],
        ]];
        if ($ctx['role'] === 'admin') {
            $out['contact']['balance_gestionale'] = (float)$c['balance'];
            $out['invoices'] = ['open_total' => round((float)$ov['owed'], 2), 'overdue_count' => (int)$ov['overdue'],
                'recent' => array_map(fn($i) => ['number' => $i['number'], 'date' => $i['creation_date'], 'due' => $i['due_date'],
                    'gross' => (float)$i['gross_amount'], 'open' => (float)$i['open_amount'], 'state' => $i['pay_state']],
                    array_slice($ov['invoices'], 0, 10))];
            $out['contracts'] = array_map(fn($k) => ['id' => (int)$k['id'], 'status' => $k['status'] ?? null,
                'amount_cents' => $k['amount_cents'] ?? null, 'description' => $k['description'] ?? null], $ov['contracts']);
        }
        $leads = array_filter($ov['leads'], fn($l) => $ctx['role'] !== 'tech' && ($ctx['role'] === 'admin' || (int)$l['assigned_to'] === (int)$ctx['uid']));
        $out['leads'] = array_map(fn($l) => ['lead_id' => (int)$l['id'], 'status' => $l['status'], 'stage' => Pipelines::label('lead', (string)$l['stage_code']),
            'source' => $l['source'], 'received_at' => $l['received_at'], 'agent_id' => $l['assigned_to'], 'request' => mb_substr((string)$l['comments'], 0, 300)], array_values($leads));
        $deals = array_filter($ov['deals'], fn($d) => $ctx['role'] !== 'tech' && ($ctx['role'] === 'admin' || (int)$d['assigned_to'] === (int)$ctx['uid']));
        $out['deals'] = array_map(fn($d) => ['deal_id' => (int)$d['id'], 'title' => $d['title'], 'status' => $d['status'],
            'stage' => Pipelines::label('deal', (string)$d['stage_code']), 'amount' => (float)$d['amount'], 'agent_id' => $d['assigned_to'],
            'created_at' => $d['created_at']], array_values($deals));
        $tickets = array_filter($ov['tickets'], fn($t) => self::ticketVisible($ctx, $t));
        $out['tickets'] = array_map(function ($t) {
            $msgs = array_slice($t['messages'], -3);
            return ['ticket_id' => (int)$t['id'], 'subject' => $t['subject'], 'status' => $t['status'], 'updated_at' => $t['updated_at'],
                'last_sender' => $t['last_sender'], 'agent_id' => $t['assigned_agent_id'],
                'last_messages' => array_map(fn($m) => ['from' => $m['sender_type'] === 'customer' ? 'cliente' : ($m['sender_name'] ?: 'staff'),
                    'at' => $m['created_at'], 'text' => mb_substr((string)$m['body'], 0, 400) ?: ($m['attachment_name'] ? '[allegato: ' . $m['attachment_name'] . ']' : '')], $msgs)];
        }, array_values($tickets));
        $out['documents'] = array_map(fn($d) => ['title' => $d['title'], 'status' => $d['status'], 'sent_at' => $d['sent_at'], 'signed_at' => $d['signed_at']],
            array_slice($ov['documents'], 0, 10));
        $pdo = Db::pdo();
        $s = $pdo->prepare('SELECT id, title, starts_at, preferred_at, status, agent_id, location FROM appointments WHERE contact_id = ? ORDER BY COALESCE(starts_at, preferred_at) DESC LIMIT 10');
        $s->execute([$cid]);
        $out['appointments'] = array_map(fn($a) => ['appointment_id' => (int)$a['id'], 'title' => $a['title'], 'when' => $a['starts_at'] ?: $a['preferred_at'],
            'status' => $a['status'], 'agent_id' => $a['agent_id'], 'location' => $a['location']], $s->fetchAll());
        $s = $pdo->prepare("SELECT id, title, status, due_at, assigned_to FROM tasks WHERE (related_type = 'contact' AND related_id = ?)
                            OR (related_type = 'lead' AND related_id IN (SELECT id FROM leads WHERE contact_id = ?))
                            OR (related_type = 'deal' AND related_id IN (SELECT id FROM deals WHERE contact_id = ?)) ORDER BY id DESC LIMIT 10");
        $s->execute([$cid, $cid, $cid]);
        $out['tasks'] = array_map(fn($t) => ['task_id' => (int)$t['id'], 'title' => $t['title'], 'status' => $t['status'], 'due_at' => $t['due_at'], 'assigned_to' => $t['assigned_to']], $s->fetchAll());
        try {
            $s = $pdo->prepare('SELECT id, report_type, machine_model, serial_number, status, sent_at, created_at FROM install_reports WHERE contact_id = ? ORDER BY id DESC LIMIT 5');
            $s->execute([$cid]);
            $out['install_reports'] = array_map(fn($r) => ['report_id' => (int)$r['id'], 'type' => $r['report_type'], 'model' => $r['machine_model'],
                'serial' => $r['serial_number'], 'status' => $r['status'], 'sent_at' => $r['sent_at'], 'created_at' => $r['created_at']], $s->fetchAll());
        } catch (Throwable) {
            // no installations table on this install
        }
        // The last things anyone wrote about this customer, across their leads and deals.
        $hist = [];
        foreach ($leads as $l) {
            foreach (Activities::forEntity('lead', (int)$l['id'], 6) as $a) {
                $hist[] = ['at' => $a['created_at'], 'on' => 'lead #' . $l['id'], 'by' => $a['full_name'] ?: $a['username'] ?: 'sistema', 'text' => mb_substr((string)$a['body'], 0, 300)];
            }
        }
        foreach ($deals as $d) {
            foreach (Activities::forEntity('deal', (int)$d['id'], 6) as $a) {
                $hist[] = ['at' => $a['created_at'], 'on' => 'deal #' . $d['id'], 'by' => $a['full_name'] ?: $a['username'] ?: 'sistema', 'text' => mb_substr((string)$a['body'], 0, 300)];
            }
        }
        foreach (Activities::forEntity('contact', $cid, 6) as $a) {
            $hist[] = ['at' => $a['created_at'], 'on' => 'contatto', 'by' => $a['full_name'] ?: $a['username'] ?: 'sistema', 'text' => mb_substr((string)$a['body'], 0, 300)];
        }
        usort($hist, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
        $out['recent_history'] = array_slice($hist, 0, 15);
        $out['areas'] = array_map(fn($a) => ['router' => $a['name'], 'devices' => (int)$a['device_count'], 'down' => (int)$a['devices_down']], $ov['areas']);
        return self::j($out);
    }

    private static function searchRecords(string $q, string $type, array $ctx): string
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return 'Scrivi almeno 2 caratteri.';
        }
        $pdo = Db::pdo();
        $like = '%' . $q . '%';
        $scope = self::scope($ctx);
        $out = [];
        $want = fn(string $t) => $type === 'all' || $type === $t;
        if ($want('lead') && $ctx['role'] !== 'tech') {
            $sql = 'SELECT id, customer_name, customer_phone, status, stage_code, assigned_to, received_at, comments FROM leads
                     WHERE (customer_name LIKE ? OR customer_email LIKE ? OR customer_phone LIKE ? OR comments LIKE ? OR vat_number LIKE ?)'
                 . ($scope ? ' AND assigned_to = ' . $scope : '') . ' ORDER BY id DESC LIMIT 10';
            $s = $pdo->prepare($sql);
            $s->execute([$like, $like, $like, $like, $like]);
            $out['leads'] = array_map(fn($l) => ['lead_id' => (int)$l['id'], 'name' => $l['customer_name'], 'phone' => $l['customer_phone'], 'status' => $l['status'],
                'stage' => Pipelines::label('lead', (string)$l['stage_code']), 'agent_id' => $l['assigned_to'], 'received_at' => $l['received_at'],
                'request' => mb_substr((string)$l['comments'], 0, 160)], $s->fetchAll());
        }
        if ($want('deal') && $ctx['role'] !== 'tech') {
            $sql = 'SELECT id, title, customer_name, status, stage_code, amount, assigned_to, created_at FROM deals
                     WHERE (title LIKE ? OR customer_name LIKE ? OR customer_email LIKE ?)' . ($scope ? ' AND assigned_to = ' . $scope : '') . ' ORDER BY id DESC LIMIT 10';
            $s = $pdo->prepare($sql);
            $s->execute([$like, $like, $like]);
            $out['deals'] = array_map(fn($d) => ['deal_id' => (int)$d['id'], 'title' => $d['title'], 'customer' => $d['customer_name'], 'status' => $d['status'],
                'stage' => Pipelines::label('deal', (string)$d['stage_code']), 'amount' => (float)$d['amount'], 'agent_id' => $d['assigned_to']], $s->fetchAll());
        }
        if ($want('ticket')) {
            $own = $ctx['role'] === 'admin' ? '' : ' AND t.assigned_agent_id = ' . (int)$ctx['uid'];
            $s = $pdo->prepare("SELECT t.id, t.subject, t.status, t.updated_at, t.assigned_agent_id, c.name AS customer FROM tickets t
                                LEFT JOIN contacts c ON c.id = t.contact_id
                                WHERE (t.subject LIKE ? OR c.name LIKE ? OR EXISTS (SELECT 1 FROM ticket_messages m WHERE m.ticket_id = t.id AND m.body LIKE ?))$own
                                ORDER BY t.updated_at DESC LIMIT 10");
            $s->execute([$like, $like, $like]);
            $out['tickets'] = array_map(fn($t) => ['ticket_id' => (int)$t['id'], 'subject' => $t['subject'], 'customer' => $t['customer'], 'status' => $t['status'],
                'updated_at' => $t['updated_at'], 'agent_id' => $t['assigned_agent_id']], $s->fetchAll());
        }
        if ($want('task')) {
            $own = $ctx['role'] === 'admin' ? '' : ' AND assigned_to = ' . (int)$ctx['uid'];
            $s = $pdo->prepare("SELECT id, title, status, due_at, assigned_to, related_type, related_id FROM tasks WHERE (title LIKE ? OR description LIKE ?)$own ORDER BY id DESC LIMIT 10");
            $s->execute([$like, $like]);
            $out['tasks'] = array_map(fn($t) => ['task_id' => (int)$t['id'], 'title' => $t['title'], 'status' => $t['status'], 'due_at' => $t['due_at'],
                'assigned_to' => $t['assigned_to'], 'related' => $t['related_type'] ? $t['related_type'] . ' #' . $t['related_id'] : null], $s->fetchAll());
        }
        if ($want('appointment')) {
            $own = $ctx['role'] === 'admin' ? '' : ' AND agent_id = ' . (int)$ctx['uid'];
            $s = $pdo->prepare("SELECT id, title, customer_name, starts_at, preferred_at, status, agent_id FROM appointments WHERE (title LIKE ? OR customer_name LIKE ? OR notes LIKE ?)$own ORDER BY COALESCE(starts_at, preferred_at) DESC LIMIT 10");
            $s->execute([$like, $like, $like]);
            $out['appointments'] = array_map(fn($a) => ['appointment_id' => (int)$a['id'], 'title' => $a['title'], 'customer' => $a['customer_name'],
                'when' => $a['starts_at'] ?: $a['preferred_at'], 'status' => $a['status'], 'agent_id' => $a['agent_id']], $s->fetchAll());
        }
        $out = array_filter($out);
        return $out ? self::j($out) : 'Nessun record corrisponde a "' . $q . '".';
    }

    private static function getTicket(int $id, array $ctx): string
    {
        $t = Tickets::find($id);
        if (!$t || !self::ticketVisible($ctx, $t)) {
            return 'Ticket non trovato o non visibile a questo utente.';
        }
        $msgs = array_map(fn($m) => ['at' => $m['created_at'], 'from' => $m['sender_type'] === 'customer' ? 'cliente' : ($m['sender_name'] ?: 'staff'),
            'text' => (string)$m['body'], 'attachment' => $m['attachment_name'] ? (Tickets::isAudio($m['attachment_name']) ? '[messaggio vocale: ' . $m['attachment_name'] . ']' : '[file: ' . $m['attachment_name'] . ']') : null,
            'signature_request' => $m['sign_document_id'] ? ($m['sign_title'] . ' — ' . $m['sign_status']) : null], Tickets::thread($id));
        return self::j(['ticket_id' => $id, 'subject' => $t['subject'], 'status' => $t['status'], 'customer' => $t['customer_name'], 'contact_id' => (int)$t['contact_id'],
            'agent' => $t['agent_name'] ?: $t['agent_username'], 'agent_id' => $t['assigned_agent_id'], 'created_at' => $t['created_at'], 'updated_at' => $t['updated_at'],
            'customer_seen_at' => $t['customer_seen_at'], 'messages' => $msgs]);
    }

    private static function getLead(int $id, array $ctx): string
    {
        $l = Leads::find($id);
        if (!$l || !self::leadVisible($ctx, $l)) {
            return 'Lead non trovato o non visibile a questo utente.';
        }
        $pdo = Db::pdo();
        $s = $pdo->prepare('SELECT id, status, notes, created_at FROM quote_requests WHERE lead_id = ? ORDER BY id DESC LIMIT 5');
        $s->execute([$id]);
        $quotes = $s->fetchAll();
        $s = $pdo->prepare('SELECT id, title, starts_at, preferred_at, status, agent_id FROM appointments WHERE lead_id = ? ORDER BY id DESC LIMIT 5');
        $s->execute([$id]);
        $appts = $s->fetchAll();
        return self::j([
            'lead_id' => $id, 'name' => $l['customer_name'], 'phone' => $l['customer_phone'], 'email' => $l['customer_email'], 'company' => $l['company'] ?? null,
            'vat' => $l['vat_number'], 'contact_id' => $l['contact_id'], 'status' => $l['status'], 'stage_code' => $l['stage_code'],
            'stage' => Pipelines::label('lead', (string)$l['stage_code']), 'source' => $l['source'], 'zone' => $l['zone'], 'agent_id' => $l['assigned_to'],
            'received_at' => $l['received_at'], 'request' => $l['comments'],
            'history' => array_map(fn($a) => ['at' => $a['created_at'], 'by' => $a['full_name'] ?: $a['username'] ?: 'sistema', 'type' => $a['type'], 'text' => $a['body']], Activities::forEntity('lead', $id, 25)),
            'quotes' => array_map(fn($q) => ['quote_id' => (int)$q['id'], 'status' => $q['status'], 'notes' => mb_substr((string)$q['notes'], 0, 200), 'created_at' => $q['created_at']], $quotes),
            'appointments' => array_map(fn($a) => ['appointment_id' => (int)$a['id'], 'title' => $a['title'], 'when' => $a['starts_at'] ?: $a['preferred_at'], 'status' => $a['status'], 'agent_id' => $a['agent_id']], $appts),
        ]);
    }

    private static function getDeal(int $id, array $ctx): string
    {
        $d = Deals::find($id);
        if (!$d || !self::leadVisible($ctx, $d)) {
            return 'Trattativa non trovata o non visibile a questo utente.';
        }
        return self::j([
            'deal_id' => $id, 'title' => $d['title'], 'customer' => $d['customer_name'], 'contact_id' => $d['contact_id'], 'lead_id' => $d['lead_id'],
            'status' => $d['status'], 'stage_code' => $d['stage_code'], 'stage' => Pipelines::label('deal', (string)$d['stage_code']),
            'amount' => (float)$d['amount'], 'currency' => $d['currency'], 'agent_id' => $d['assigned_to'], 'expected_close' => $d['expected_close_date'],
            'offer_status' => $d['offer_status'] ?? null, 'signed_at' => $d['signed_at'] ?? null, 'created_at' => $d['created_at'],
            'history' => array_map(fn($a) => ['at' => $a['created_at'], 'by' => $a['full_name'] ?: $a['username'] ?: 'sistema', 'type' => $a['type'], 'text' => $a['body']], Activities::forEntity('deal', $id, 25)),
        ]);
    }

    private static function listTasks(string $status, int $assignedTo, array $ctx): string
    {
        $uid = $ctx['role'] === 'admin' ? ($assignedTo ?: null) : (int)$ctx['uid'];
        $rows = Tasks::all(300, $uid);
        $rows = array_values(array_filter($rows, fn($t) => match ($status) {
            'overdue' => $t['status'] === 'open' && $t['due_at'] !== null && $t['due_at'] < date('Y-m-d H:i:s'),
            'done'    => $t['status'] === 'done',
            'all'     => true,
            default   => $t['status'] === 'open',
        }));
        if (!$rows) {
            return 'Nessuna attività.';
        }
        return self::j(array_map(fn($t) => ['task_id' => (int)$t['id'], 'title' => $t['title'], 'status' => $t['status'], 'due_at' => $t['due_at'],
            'priority' => $t['priority'], 'assigned_to' => $t['assigned_to'], 'assignee' => $t['agent_name'] ?: $t['agent_username'],
            'related' => $t['related_type'] ? $t['related_type'] . ' #' . $t['related_id'] : null], array_slice($rows, 0, 40)));
    }

    private static function listAppointments(int $ahead, int $back, int $agentId, array $ctx): string
    {
        $ahead = max(0, min(365, $ahead));
        $back = max(0, min(365, $back));
        $uid = $ctx['role'] === 'admin' ? ($agentId ?: null) : (int)$ctx['uid'];
        $sql = 'SELECT a.id, a.title, a.customer_name, a.customer_phone, a.starts_at, a.preferred_at, a.status, a.agent_id, a.location, u.full_name, u.username
                  FROM appointments a LEFT JOIN users u ON u.id = a.agent_id
                 WHERE COALESCE(a.starts_at, a.preferred_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE_ADD(CURDATE(), INTERVAL ? DAY)'
             . ($uid ? ' AND a.agent_id = ' . (int)$uid : '') . ' ORDER BY COALESCE(a.starts_at, a.preferred_at) LIMIT 60';
        $s = Db::pdo()->prepare($sql);
        $s->execute([$back, $ahead + 1]);
        $rows = $s->fetchAll();
        if (!$rows) {
            return 'Nessun appuntamento nel periodo.';
        }
        return self::j(array_map(fn($a) => ['appointment_id' => (int)$a['id'], 'title' => $a['title'], 'customer' => $a['customer_name'], 'phone' => $a['customer_phone'],
            'when' => $a['starts_at'] ?: $a['preferred_at'], 'confirmed' => $a['starts_at'] !== null, 'status' => $a['status'],
            'agent' => $a['full_name'] ?: $a['username'], 'agent_id' => $a['agent_id'], 'location' => $a['location']], $rows));
    }

    private static function listStaff(): string
    {
        $rows = Db::pdo()->query("SELECT id, username, full_name, role, title FROM users WHERE active = 1 ORDER BY role, full_name, username")->fetchAll();
        return self::j(array_map(fn($u) => ['user_id' => (int)$u['id'], 'name' => trim((string)$u['full_name']) ?: $u['username'],
            'role' => ['admin' => 'ufficio/admin', 'agent' => 'agente', 'tech' => 'tecnico'][$u['role']] ?? $u['role'], 'title' => $u['title']], $rows));
    }

    private static function pipelineReport(array $ctx): string
    {
        $pdo = Db::pdo();
        $scope = self::scope($ctx);
        $w = $scope ? ' AND assigned_to = ' . $scope : '';
        $leads = [];
        foreach ($pdo->query("SELECT stage_code, COUNT(*) n FROM leads WHERE status = 'open'$w GROUP BY stage_code") as $r) {
            $leads[Pipelines::label('lead', (string)$r['stage_code'])] = (int)$r['n'];
        }
        $deals = [];
        foreach ($pdo->query("SELECT stage_code, COUNT(*) n, COALESCE(SUM(amount),0) v FROM deals WHERE status = 'open'$w GROUP BY stage_code") as $r) {
            $deals[Pipelines::label('deal', (string)$r['stage_code'])] = ['count' => (int)$r['n'], 'value' => (float)$r['v']];
        }
        $month = date('Y-m');
        $won = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(amount),0) v FROM deals WHERE status = 'won' AND updated_at >= '$month-01'$w")->fetch();
        $lost = (int)$pdo->query("SELECT COUNT(*) FROM deals WHERE status = 'lost' AND updated_at >= '$month-01'$w")->fetchColumn();
        $newLeads = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE received_at >= '$month-01'$w")->fetchColumn();
        $tw = $ctx['role'] === 'admin' ? '' : ' AND assigned_agent_id = ' . (int)$ctx['uid'];
        $tickets = $pdo->query("SELECT status, COUNT(*) n FROM tickets WHERE status <> 'closed'$tw GROUP BY status")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $waiting = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status <> 'closed' AND last_sender = 'customer'$tw")->fetchColumn();
        $kw = $ctx['role'] === 'admin' ? '' : ' AND assigned_to = ' . (int)$ctx['uid'];
        $overdue = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'open' AND due_at < NOW()$kw")->fetchColumn();
        $openTasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'open'$kw")->fetchColumn();
        $aw = $ctx['role'] === 'admin' ? '' : ' AND agent_id = ' . (int)$ctx['uid'];
        $apptWeek = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'confirmed' AND starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)$aw")->fetchColumn();
        return self::j([
            'scope' => $scope ? 'solo i tuoi record' : 'tutto il CRM', 'month' => $month,
            'leads_open_by_stage' => $leads, 'leads_received_this_month' => $newLeads,
            'deals_open_by_stage' => $deals, 'deals_won_this_month' => ['count' => (int)$won['n'], 'value' => (float)$won['v']], 'deals_lost_this_month' => $lost,
            'tickets_open' => $tickets, 'tickets_waiting_for_staff' => $waiting,
            'tasks_open' => $openTasks, 'tasks_overdue' => $overdue, 'appointments_next_7_days' => $apptWeek,
        ]);
    }

    private static function leadsBySource(string $month): string
    {
        $ym = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        return self::j(['month' => $ym, 'by_source' => Leads::sourceReport($ym)]);
    }

    private static function invoicesReport(): string
    {
        $sum = Invoices::summary();
        $top = Db::pdo()->query(
            "SELECT counterpart_name, counterpart_vat, contact_id, SUM(open_amount) open_total, COUNT(*) n
               FROM sibill_invoices WHERE direction = 'ISSUED' AND pay_state <> 'paid' AND due_date IS NOT NULL AND due_date < CURDATE()
              GROUP BY counterpart_name, counterpart_vat, contact_id ORDER BY open_total DESC LIMIT 10"
        )->fetchAll();
        return self::j(['summary' => $sum, 'top_overdue' => array_map(fn($r) => ['customer' => $r['counterpart_name'], 'vat' => $r['counterpart_vat'],
            'contact_id' => $r['contact_id'], 'overdue_total' => (float)$r['open_total'], 'invoices' => (int)$r['n']], $top)]);
    }

    // ---- actions ---------------------------------------------------------------------

    /**
     * A one-line, human description of a proposed action, for the confirmation
     * card. Written from the input alone: no side effects, no lookups that could fail.
     */
    public static function describe(string $name, array $in, array $ctx): string
    {
        $when = static fn(?string $s): string => ($s && ($ts = strtotime($s))) ? date('d/m/Y H:i', $ts) : '';
        return match ($name) {
            'create_task' => 'Crea attività «' . (string)($in['title'] ?? '') . '»'
                . (!empty($in['due_at']) ? ' — scadenza ' . $when((string)$in['due_at']) : '')
                . (!empty($in['assigned_to']) && $ctx['role'] === 'admin' ? ' — assegnata a utente #' . (int)$in['assigned_to'] : '')
                . (!empty($in['related_type']) ? ' — su ' . $in['related_type'] . ' #' . (int)($in['related_id'] ?? 0) : ''),
            'add_note' => 'Aggiungi nota su ' . (string)($in['entity_type'] ?? '') . ' #' . (int)($in['entity_id'] ?? 0) . ': «' . mb_substr((string)($in['body'] ?? ''), 0, 200) . '»',
            'send_message_to_customer' => 'Scrivi al cliente #' . (int)($in['contact_id'] ?? 0) . ' nella sua chat: «' . mb_substr((string)($in['body'] ?? ''), 0, 400) . '»',
            'send_whatsapp' => 'Invia WhatsApp al cliente #' . (int)($in['contact_id'] ?? 0) . ': «' . mb_substr((string)($in['text'] ?? ''), 0, 400) . '»',
            'set_ticket_status' => 'Ticket #' . (int)($in['ticket_id'] ?? 0) . ' → stato ' . (string)($in['status'] ?? ''),
            'move_lead_stage' => 'Lead #' . (int)($in['lead_id'] ?? 0) . ' → fase ' . Pipelines::label('lead', (string)($in['stage_code'] ?? '')),
            'move_deal_stage' => 'Trattativa #' . (int)($in['deal_id'] ?? 0) . ' → fase ' . Pipelines::label('deal', (string)($in['stage_code'] ?? '')),
            'update_lead' => 'Aggiorna lead #' . (int)($in['lead_id'] ?? 0) . ': ' . implode(', ', array_map(fn($k, $v) => "$k = $v",
                array_keys(array_diff_key($in, ['lead_id' => 1])), array_values(array_diff_key($in, ['lead_id' => 1])))),
            'create_lead' => 'Crea lead «' . (string)($in['name'] ?? '') . '»' . (!empty($in['phone']) ? ' · ' . $in['phone'] : '') . (!empty($in['company']) ? ' · ' . $in['company'] : ''),
            'book_appointment' => 'Fissa appuntamento con «' . (string)($in['name'] ?? '') . '» il ' . $when((string)($in['starts_at'] ?? ''))
                . (!empty($in['location']) ? ' a ' . $in['location'] : ''),
            default => $name,
        };
    }

    /** Run a confirmed action as the user. Returns ['ok' => bool, 'text' => what happened]. */
    public static function execute(string $name, array $in, array $ctx): array
    {
        try {
            $allowed = array_column(self::definitions($ctx), 'name');
            if (!self::isAction($name) || !in_array($name, $allowed, true)) {
                return ['ok' => false, 'text' => 'Azione non disponibile per questo utente.'];
            }
            return match ($name) {
                'create_task'              => self::doCreateTask($in, $ctx),
                'add_note'                 => self::doAddNote($in, $ctx),
                'send_message_to_customer' => self::doSendMessage($in, $ctx),
                'send_whatsapp'            => self::doSendWhatsapp($in, $ctx),
                'set_ticket_status'        => self::doTicketStatus($in, $ctx),
                'move_lead_stage'          => self::doMoveLead($in, $ctx),
                'move_deal_stage'          => self::doMoveDeal($in, $ctx),
                'update_lead'              => self::doUpdateLead($in, $ctx),
                'create_lead'              => self::doCreateLead($in, $ctx),
                'book_appointment'         => self::doBookAppointment($in, $ctx),
                default                    => ['ok' => false, 'text' => 'Azione sconosciuta.'],
            };
        } catch (Throwable $e) {
            Log::write('ai', 'action_failed', null, null, ['tool' => $name, 'error' => $e->getMessage()]);
            return ['ok' => false, 'text' => 'Errore: ' . $e->getMessage()];
        }
    }

    private static function doCreateTask(array $in, array $ctx): array
    {
        $assignee = $ctx['role'] === 'admin' && !empty($in['assigned_to']) ? (int)$in['assigned_to'] : (int)$ctx['uid'];
        $rel = in_array($in['related_type'] ?? '', ['lead', 'deal', 'contact', 'ticket'], true) ? (string)$in['related_type'] : null;
        $id = Tasks::create([
            'title' => (string)($in['title'] ?? 'Attività'), 'description' => $in['description'] ?? null,
            'assigned_to' => $assignee, 'related_type' => $rel, 'related_id' => $rel ? (int)($in['related_id'] ?? 0) ?: null : null,
            'due_at' => $in['due_at'] ?? null, 'priority' => $in['priority'] ?? 'normal',
        ], (int)$ctx['uid']);
        return ['ok' => true, 'text' => "Attività #$id creata" . ($assignee !== (int)$ctx['uid'] ? " e assegnata all'utente #$assignee" : '') . '.'];
    }

    private static function doAddNote(array $in, array $ctx): array
    {
        $type = (string)($in['entity_type'] ?? '');
        $id = (int)($in['entity_id'] ?? 0);
        $body = trim((string)($in['body'] ?? ''));
        if ($body === '') {
            return ['ok' => false, 'text' => 'Nota vuota.'];
        }
        $ok = match ($type) {
            'lead'    => ($l = Leads::find($id)) && self::leadVisible($ctx, $l) && $ctx['role'] !== 'tech',
            'deal'    => ($d = Deals::find($id)) && self::leadVisible($ctx, $d) && $ctx['role'] !== 'tech',
            'contact' => self::mayContact($ctx, $id),
            default   => false,
        };
        if (!$ok) {
            return ['ok' => false, 'text' => 'Record non trovato o non tuo.'];
        }
        Activities::add($type, $id, 'note', $body, (int)$ctx['uid']);
        return ['ok' => true, 'text' => "Nota aggiunta su $type #$id."];
    }

    private static function doSendMessage(array $in, array $ctx): array
    {
        $cid = (int)($in['contact_id'] ?? 0);
        $body = trim((string)($in['body'] ?? ''));
        if ($body === '') {
            return ['ok' => false, 'text' => 'Messaggio vuoto.'];
        }
        $senderType = $ctx['role'] === 'admin' ? 'admin' : 'agent';
        // An open conversation with this customer continues; a technician may only continue one they hold.
        $s = Db::pdo()->prepare("SELECT id, assigned_agent_id FROM tickets WHERE contact_id = ? AND status <> 'closed' ORDER BY updated_at DESC LIMIT 1");
        $s->execute([$cid]);
        $open = $s->fetch();
        if ($ctx['role'] === 'tech') {
            if (!$open || (int)$open['assigned_agent_id'] !== (int)$ctx['uid']) {
                return ['ok' => false, 'text' => 'Nessuna conversazione aperta presa in carico da te con questo cliente.'];
            }
        } elseif (!self::mayContact($ctx, $cid)) {
            return ['ok' => false, 'text' => 'Cliente non trovato o non tuo.'];
        }
        if ($open) {
            Tickets::reply((int)$open['id'], $senderType, (int)$ctx['uid'], (string)$ctx['name'], $body);
            return ['ok' => true, 'text' => 'Messaggio inviato nella conversazione #' . (int)$open['id'] . '; il cliente riceve l\'avviso via WhatsApp/email.'];
        }
        $id = Tickets::openFromStaff($cid, $senderType, (int)$ctx['uid'], (string)$ctx['name'], (string)($in['subject'] ?? 'Messaggio'), $body);
        return ['ok' => true, 'text' => "Conversazione #$id aperta con il cliente; riceve l'avviso via WhatsApp/email."];
    }

    private static function doSendWhatsapp(array $in, array $ctx): array
    {
        $cid = (int)($in['contact_id'] ?? 0);
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '' || !self::mayContact($ctx, $cid)) {
            return ['ok' => false, 'text' => 'Cliente non trovato, non tuo, o testo vuoto.'];
        }
        $c = Contacts::find($cid);
        $phone = (string)($c['phone'] ?: ($c['phone2'] ?? ''));
        if ($phone === '') {
            return ['ok' => false, 'text' => 'Il cliente non ha un numero di telefono.'];
        }
        $res = (new Notifier())->whatsappResult($phone, $text);
        Log::write('ai', 'whatsapp_sent', 'contact', $cid, ['ok' => (bool)$res['ok'], 'by' => $ctx['uid'], 'error' => $res['error'] ?? null]);
        return $res['ok'] ? ['ok' => true, 'text' => 'WhatsApp inviato a ' . $phone . '.']
            : ['ok' => false, 'text' => 'WhatsApp NON inviato: ' . (string)($res['error'] ?? 'errore del gateway')];
    }

    private static function doTicketStatus(array $in, array $ctx): array
    {
        $t = Tickets::find((int)($in['ticket_id'] ?? 0));
        $status = (string)($in['status'] ?? '');
        if (!$t || !self::ticketVisible($ctx, $t) || !in_array($status, ['open', 'pending', 'closed'], true)) {
            return ['ok' => false, 'text' => 'Ticket non trovato, non tuo, o stato non valido.'];
        }
        Tickets::setStatus((int)$t['id'], $status);
        return ['ok' => true, 'text' => 'Ticket #' . (int)$t['id'] . ' ora è ' . $status . '.'];
    }

    private static function doMoveLead(array $in, array $ctx): array
    {
        $l = Leads::find((int)($in['lead_id'] ?? 0));
        $code = strtoupper(trim((string)($in['stage_code'] ?? '')));
        if (!$l || !self::leadVisible($ctx, $l)) {
            return ['ok' => false, 'text' => 'Lead non trovato o non tuo.'];
        }
        if (!in_array($code, array_column(Pipelines::stagesForEntity('lead'), 'code'), true)) {
            return ['ok' => false, 'text' => "Fase $code inesistente."];
        }
        Leads::moveStage((int)$l['id'], $code, (int)$ctx['uid']);
        if (trim((string)($in['note'] ?? '')) !== '') {
            Activities::add('lead', (int)$l['id'], 'note', trim((string)$in['note']), (int)$ctx['uid']);
        }
        return ['ok' => true, 'text' => 'Lead #' . (int)$l['id'] . ' spostato in ' . Pipelines::label('lead', $code) . '.'];
    }

    private static function doMoveDeal(array $in, array $ctx): array
    {
        $d = Deals::find((int)($in['deal_id'] ?? 0));
        $code = strtoupper(trim((string)($in['stage_code'] ?? '')));
        if (!$d || !self::leadVisible($ctx, $d)) {
            return ['ok' => false, 'text' => 'Trattativa non trovata o non tua.'];
        }
        if (!in_array($code, array_column(Pipelines::stagesForEntity('deal'), 'code'), true)) {
            return ['ok' => false, 'text' => "Fase $code inesistente."];
        }
        Deals::moveStage((int)$d['id'], $code, (int)$ctx['uid']);
        return ['ok' => true, 'text' => 'Trattativa #' . (int)$d['id'] . ' spostata in ' . Pipelines::label('deal', $code) . '.'];
    }

    private static function doUpdateLead(array $in, array $ctx): array
    {
        $l = Leads::find((int)($in['lead_id'] ?? 0));
        if (!$l || !self::leadVisible($ctx, $l)) {
            return ['ok' => false, 'text' => 'Lead non trovato o non tuo.'];
        }
        $fields = array_intersect_key($in, array_flip(['name', 'phone', 'email', 'company', 'vat_number', 'zone', 'comments']));
        $fields = array_filter($fields, fn($v) => $v !== null && trim((string)$v) !== '');
        if (!$fields) {
            return ['ok' => false, 'text' => 'Nessun campo da aggiornare.'];
        }
        Leads::update((int)$l['id'], $fields, (int)$ctx['uid']);
        return ['ok' => true, 'text' => 'Lead #' . (int)$l['id'] . ' aggiornato: ' . implode(', ', array_keys($fields)) . '.'];
    }

    private static function doCreateLead(array $in, array $ctx): array
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'text' => 'Serve almeno il nome.'];
        }
        $owner = Leads::phoneOwner((string)($in['phone'] ?? ''));
        if ($owner !== null) {
            return ['ok' => false, 'text' => 'Numero già registrato su ' . ($owner['kind'] === 'lead' ? 'lead #' . $owner['id'] : 'cliente in anagrafica') . ' (' . $owner['name'] . '): lead non creato.'];
        }
        $dup = Leads::duplicateId(['name' => $name, 'phone' => $in['phone'] ?? '', 'email' => $in['email'] ?? '', 'vat_number' => $in['vat_number'] ?? '', 'source' => $in['source'] ?? 'manual']);
        $id = Leads::create([
            'name' => $name, 'phone' => $in['phone'] ?? '', 'email' => $in['email'] ?? '', 'company' => $in['company'] ?? '',
            'vat_number' => $in['vat_number'] ?? '', 'zone' => $in['zone'] ?? '', 'comments' => $in['comments'] ?? '',
            'source' => $in['source'] ?? 'manual',
        ], (int)$ctx['uid']);
        if ($dup !== null) {
            return ['ok' => true, 'text' => "Il cliente aveva già il lead #$id: la richiesta è stata aggiunta lì come nota."];
        }
        if ($ctx['role'] === 'agent') {
            Leads::assign($id, (int)$ctx['uid'], (int)$ctx['uid']);
        }
        return ['ok' => true, 'text' => "Lead #$id creato" . ($ctx['role'] === 'agent' ? ' e assegnato a te' : '') . '.'];
    }

    private static function doBookAppointment(array $in, array $ctx): array
    {
        $ts = strtotime((string)($in['starts_at'] ?? ''));
        if (!$ts) {
            return ['ok' => false, 'text' => 'Data/ora non valida.'];
        }
        $agentId = $ctx['role'] === 'admin' && !empty($in['agent_id']) ? (int)$in['agent_id'] : (int)$ctx['uid'];
        $leadId = (int)($in['lead_id'] ?? 0) ?: null;
        $cid = (int)($in['contact_id'] ?? 0) ?: null;
        if ($leadId) {
            $l = Leads::find($leadId);
            if (!$l || !self::leadVisible($ctx, $l)) {
                return ['ok' => false, 'text' => 'Lead non trovato o non tuo.'];
            }
            $cid = $cid ?: ((int)$l['contact_id'] ?: null);
        } elseif ($cid && !self::mayContact($ctx, $cid)) {
            return ['ok' => false, 'text' => 'Cliente non trovato o non tuo.'];
        }
        $id = Appointments::request([
            'contact_id' => $cid, 'lead_id' => $leadId, 'agent_id' => $agentId,
            'name' => $in['name'] ?? '', 'phone' => $in['phone'] ?? '', 'email' => $in['email'] ?? '',
            'title' => $in['title'] ?? null, 'location' => $in['location'] ?? null, 'preferred_at' => date('Y-m-d H:i:s', $ts),
        ], (int)$ctx['uid']);
        Appointments::schedule($id, $agentId, date('Y-m-d H:i:s', $ts), ['title' => $in['title'] ?? '', 'location' => $in['location'] ?? ''], (int)$ctx['uid']);
        return ['ok' => true, 'text' => "Appuntamento #$id fissato per " . date('d/m/Y H:i', $ts) . '; conferma e promemoria in partenza.'];
    }
}
