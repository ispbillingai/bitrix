<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Reminder\Scheduler;
use PDO;
use Throwable;

/**
 * Requests from people who are ALREADY customers.
 *
 * What the client asked, in order:
 *   "Leads are arriving who are actually already customers, and this creates
 *    confusion" — "he sells different products: the contact had bought a product
 *    in the past and now asks for a new one" — "in all cases the CRM
 *    administrators must be notified of the new request" — and then, on
 *    12/09: "possibility of receiving a lead request even from an existing
 *    customer (therefore finding the request in the customer's history) and
 *    being able to assign an agent".
 *
 * So a customer's request IS a lead — a new sale — and it is opened on the
 * customer's registry CARD: Leads::create() asks matchCustomer() (same partita
 * IVA, or the phone/email of exactly one card) before it picks a contact. On the
 * card it shows in the customer's history (forCard(): "Richieste (lead)" on the
 * customer page) and on the board marked "Già cliente", where an administrator
 * gives it an agent. Every administrator is alerted with the link (announce()):
 * for a new lead on a card, for a request added to the lead the customer already
 * had open, and for a phone/email that sits on SEVERAL cards — not a match: that
 * lead gets a contact of its own and says "forse già cliente" (suggestions()),
 * for a person to link with one click (link()).
 *
 * An existing lead can still be moved into the customer's messages and closed
 * as 'customer' (closeIntoCustomer()) when the office prefers; it is never
 * deleted — deals, invoices, partner commissions and quotes point at leads. A
 * converted lead is only ever linked.
 *
 * Only a lead-born contact (is_customer = 0, no gestionale code) is ever merged
 * away. Two REGISTRY cards are never merged here.
 */
final class LeadCustomers
{
    /** @var string[]|null base tables with a contact_id column, read once */
    private static ?array $contactTables = null;

    // ---- is this a customer? --------------------------------------------------------

    /** The registry card carrying this VAT — the oldest, as findOrCreate picks it. */
    public static function byVat(string $vat): ?array
    {
        $v = Contacts::matchableVat($vat);
        if ($v === '') {
            return null;
        }
        $s = Db::pdo()->prepare('SELECT * FROM contacts WHERE is_customer = 1 AND vat_number = ? ORDER BY id LIMIT 1');
        $s->execute([$v]);
        return $s->fetch() ?: null;
    }

    /**
     * The customer card these details belong to, or null. VAT first — certain;
     * otherwise the phone and the email, but only when they point at exactly ONE
     * card.
     *
     * @return array{card: array, how: string}|null  how: vat | phone | email | phone+email
     */
    public static function matchCustomer(array $d): ?array
    {
        if (($card = self::byVat((string)($d['vat_number'] ?? ($d['vat'] ?? '')))) !== null) {
            return ['card' => $card, 'how' => 'vat'];
        }
        $forms = self::phoneForms((string)($d['phone'] ?? ''));
        $mail  = mb_strtolower(trim((string)($d['email'] ?? '')));
        $cards = self::cardsByPhoneEmail($forms, $mail);
        if (count($cards) !== 1) {
            return null;
        }
        $c   = $cards[0];
        $how = [];
        if ($forms && (in_array((string)$c['phone'], $forms, true) || in_array((string)$c['phone2'], $forms, true))) {
            $how[] = 'phone';
        }
        if ($mail !== '' && mb_strtolower(trim((string)$c['email'])) === $mail) {
            $how[] = 'email';
        }
        return ['card' => $c, 'how' => implode('+', $how) ?: 'phone'];
    }

    /** Registry cards with this phone (either spelling, either field) or this email — at most three. */
    public static function cardsFor(string $phone, string $email): array
    {
        return self::cardsByPhoneEmail(self::phoneForms($phone), mb_strtolower(trim($email)));
    }

    private static function cardsByPhoneEmail(array $forms, string $mail): array
    {
        if (!$forms && $mail === '') {
            return [];
        }
        $conds = [];
        $args  = [];
        if ($forms) {
            $in = implode(',', array_fill(0, count($forms), '?'));
            $conds[] = "phone IN ($in) OR phone2 IN ($in)";
            array_push($args, ...$forms, ...$forms);
        }
        if ($mail !== '') {
            $conds[] = 'email = ?';
            $args[]  = $mail;
        }
        $s = Db::pdo()->prepare(
            'SELECT * FROM contacts WHERE is_customer = 1 AND (' . implode(' OR ', $conds) . ') ORDER BY id LIMIT 3'
        );
        $s->execute($args);
        return $s->fetchAll() ?: [];
    }

    /**
     * Registry cards sharing a phone number or an email with these leads — for
     * the leads NOT already on a card. One query for the whole board.
     *
     * @param array<int,array> $leads rows with id, status, customer_phone,
     *                                customer_email, ct_is_customer
     * @return array<int, array<int, array{id:int, name:string, code:?string, vat:?string, how:string[]}>>
     *         keyed by lead id
     */
    public static function suggestions(array $leads): array
    {
        $want   = [];
        $phones = [];
        $emails = [];
        foreach ($leads as $l) {
            if (!is_array($l) || !empty($l['ct_is_customer']) || in_array($l['status'] ?? '', ['junk', 'customer'], true)) {
                continue;
            }
            $forms = self::phoneForms((string)($l['customer_phone'] ?? ''));
            $mail  = mb_strtolower(trim((string)($l['customer_email'] ?? '')));
            if (!$forms && $mail === '') {
                continue;
            }
            $want[(int)$l['id']] = ['phones' => $forms, 'email' => $mail];
            foreach ($forms as $f) {
                $phones[$f] = true;
            }
            if ($mail !== '') {
                $emails[$mail] = true;
            }
        }
        if (!$want) {
            return [];
        }

        $conds = [];
        $args  = [];
        if ($phones) {
            $pk = array_map('strval', array_keys($phones));
            $in = implode(',', array_fill(0, count($pk), '?'));
            $conds[] = "phone IN ($in) OR phone2 IN ($in)";
            array_push($args, ...$pk, ...$pk);
        }
        if ($emails) {
            $ek = array_map('strval', array_keys($emails));
            $conds[] = 'email IN (' . implode(',', array_fill(0, count($ek), '?')) . ')';
            array_push($args, ...$ek);
        }
        $s = Db::pdo()->prepare(
            'SELECT id, name, customer_code, vat_number, phone, phone2, email FROM contacts
              WHERE is_customer = 1 AND (' . implode(' OR ', $conds) . ') ORDER BY id'
        );
        $s->execute($args);
        $cards = $s->fetchAll();

        $out = [];
        foreach ($want as $leadId => $w) {
            foreach ($cards as $c) {
                $how = [];
                if ($w['phones'] && (in_array((string)$c['phone'], $w['phones'], true)
                                     || in_array((string)$c['phone2'], $w['phones'], true))) {
                    $how[] = 'phone';
                }
                if ($w['email'] !== '' && mb_strtolower(trim((string)$c['email'])) === $w['email']) {
                    $how[] = 'email';
                }
                if ($how) {
                    $out[$leadId][] = [
                        'id'   => (int)$c['id'],
                        'name' => (string)$c['name'],
                        'code' => $c['customer_code'] !== null ? (string)$c['customer_code'] : null,
                        'vat'  => $c['vat_number'] !== null && $c['vat_number'] !== '' ? (string)$c['vat_number'] : null,
                        'how'  => $how,
                    ];
                }
            }
        }
        return $out;
    }

    /** Every lead on one card, newest first — the requests list on the customer page. */
    public static function forCard(int $contactId): array
    {
        $s = Db::pdo()->prepare(
            'SELECT l.*, u.username AS agent_username, u.full_name AS agent_name
               FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
              WHERE l.contact_id = ? ORDER BY l.id DESC'
        );
        $s->execute([$contactId]);
        return $s->fetchAll() ?: [];
    }

    // ---- telling the administrators ---------------------------------------------------

    /**
     * "In all cases the CRM administrators must be notified of the new request" —
     * with the link to the lead, where they give it an agent. Called by
     * Leads::create() and Leads::groupRequest():
     *   new      a lead was opened on the customer's card
     *   grouped  the request was added to the lead the customer already had open
     *   maybe    the phone/email sits on several cards — the lead has a contact
     *            of its own, and a person decides which customer it is
     * Anything else about the lead (not on a card, a single card for 'maybe') is
     * simply not announced. Never throws: the lead exists either way.
     */
    public static function announce(int $leadId, string $kind, string $request = ''): void
    {
        try {
            $lead = Leads::find($leadId);
            if (!$lead) {
                return;
            }
            if ($kind === 'maybe') {
                $cards = self::cardsFor((string)$lead['customer_phone'], (string)$lead['customer_email']);
                if (count($cards) < 2) {
                    return;
                }
            } else {
                $card = !empty($lead['contact_id']) ? Contacts::find((int)$lead['contact_id']) : null;
                if (!$card || (int)$card['is_customer'] !== 1) {
                    return;
                }
                $cards = [$card];
            }
            Log::write('crm', 'customer_lead_request', 'lead', $leadId,
                ['kind' => $kind, 'cards' => array_map(static fn(array $c): int => (int)$c['id'], $cards)]);

            // Always on; a test process switches it off for itself (Config overlay).
            if (!Config::get('crm.customer_request_notify', true)) {
                return;
            }
            $sch = new Scheduler();
            foreach (self::leadAlerts($lead, $cards, $kind, $request) as $row) {
                $sch->enqueue($row);
            }
        } catch (Throwable $e) {
            Log::write('crm', 'customer_request_notify_failed', 'lead', $leadId, ['error' => $e->getMessage()]);
        }
    }

    /**
     * One queued message per administrator who can be reached, on the channels
     * they have, through the same queue as every other CRM message (it spaces the
     * WhatsApp sends and retries a failed one). Built apart from the sending so
     * it can be checked without sending anything.
     *
     * @param array $lead  a leads row (id, customer_name/phone/email, comments, assigned_to)
     * @param array $cards the customer card (new/grouped) or the cards that share it (maybe)
     * @return array<int,array> Scheduler::enqueue rows
     */
    public static function leadAlerts(array $lead, array $cards, string $kind, string $request = ''): array
    {
        $leadId  = (int)$lead['id'];
        $request = trim($request) !== '' ? trim($request) : trim((string)($lead['comments'] ?? ''));
        $excerpt = $request === '' ? 'Richiesta di contatto, senza messaggio.'
                 : (mb_strlen($request) > 400 ? mb_substr($request, 0, 400) . '…' : $request);
        $from    = implode(' · ', array_values(array_filter([
            trim((string)($lead['customer_name'] ?? '')), trim((string)($lead['customer_phone'] ?? '')),
            trim((string)($lead['customer_email'] ?? '')),
        ], 'strlen'))) ?: '—';
        $agent   = !empty($lead['assigned_to']) ? self::staffName((int)$lead['assigned_to']) : '';
        $card    = $cards[0];
        $code    = !empty($card['customer_code']) ? ' (cod. ' . $card['customer_code'] . ')' : '';
        $names   = implode('; ', array_map(static fn(array $c): string => (string)$c['name']
            . (!empty($c['customer_code']) ? ' (cod. ' . $c['customer_code'] . ')' : ''), $cards));
        $vars = [
            'customer_name'    => (string)$card['name'],
            'code'             => $code,
            'customer_html'    => htmlspecialchars((string)$card['name'] . $code, ENT_QUOTES),
            'cards'            => $names,
            'cards_html'       => htmlspecialchars($names, ENT_QUOTES),
            'lead'             => (string)$leadId,
            'what'             => ($kind === 'grouped' ? 'aggiunta al lead #' : 'nuovo lead #') . $leadId,
            'what_en'          => ($kind === 'grouped' ? 'added to lead #' : 'new lead #') . $leadId,
            'assigned'         => $agent !== '' ? 'agente: ' . $agent : 'nessun agente assegnato',
            'assigned_en'      => $agent !== '' ? 'agent: ' . $agent : 'no agent assigned',
            'request'          => $excerpt,
            'request_html'     => nl2br(htmlspecialchars($excerpt, ENT_QUOTES)),
            'from'             => $from,
            'from_html'        => htmlspecialchars($from, ENT_QUOTES),
            'link'             => Config::appBaseUrl() . '/dashboard.php?tab=leads&lead=' . $leadId,
        ];
        $vars['assigned_html']    = htmlspecialchars($vars['assigned'], ENT_QUOTES);
        $vars['assigned_en_html'] = htmlspecialchars($vars['assigned_en'], ENT_QUOTES);

        $rows = [];
        foreach (self::admins() as $u) {
            $rows[] = [
                'entity_type'    => 'lead',
                'entity_id'      => $leadId,
                'rule_key'       => $kind === 'maybe' ? 'customer_maybe_admin' : 'customer_lead_admin',
                'recipient_type' => 'agent',
                'channel'        => self::channelFor($u),
                'due_at'         => date('Y-m-d H:i:s'),
                'dedupe_key'     => 'custlead:' . $leadId . ':' . $kind . ':'
                                    . substr(md5($request . '|' . date('YmdHi')), 0, 10) . ':' . (int)$u['id'],
                // The payload carries the administrator's own phone and email: the
                // queue addresses an 'agent' recipient from these.
                'payload'        => $vars + [
                    'name'        => trim((string)($u['full_name'] ?? '')) ?: (string)$u['username'],
                    'agent_phone' => (string)($u['phone'] ?? ''),
                    'agent_email' => (string)($u['email'] ?? ''),
                ],
            ];
        }
        return $rows;
    }

    // ---- a lead already in the CRM ---------------------------------------------------

    /**
     * Move an OPEN lead that is a customer's into the customer's messages and
     * close it as 'customer' — off the board, out of the open counts, still on
     * record, reversible. For when the office would rather answer it in the chat
     * than give it to an agent. A converted lead is refused: it is how the
     * customer was won. Quiet: moving an old request is not a new one.
     *
     * @return array{ok:bool, error?:string, ticket_id?:int}
     */
    public static function closeIntoCustomer(int $leadId, int $cardId, ?int $userId = null): array
    {
        $lead = Leads::find($leadId);
        if (!$lead) {
            return ['ok' => false, 'error' => 'no_lead'];
        }
        if ((string)$lead['status'] !== 'open') {
            return ['ok' => false, 'error' => 'not_open'];
        }
        $card = Contacts::find($cardId);
        if (!$card || (int)$card['is_customer'] !== 1) {
            return ['ok' => false, 'error' => 'not_customer'];
        }
        if ((int)$lead['contact_id'] !== $cardId) {
            $l = self::link($leadId, $cardId, 'close', $userId);
            if (empty($l['ok'])) {
                return $l;
            }
            $card = Contacts::find($cardId) ?: $card;   // it may have taken a phone or an email
        }

        $tk = self::requestToCustomer($card, [
            'name'       => $lead['customer_name'], 'phone' => $lead['customer_phone'],
            'email'      => $lead['customer_email'], 'vat_number' => $lead['vat_number'],
            'comments'   => $lead['comments'], 'fair_name' => $lead['fair_name'],
            'fair_city'  => $lead['fair_city'], 'source_url' => $lead['source_url'],
        ], ['lead_id' => $leadId, 'received_at' => $lead['received_at'] ?: $lead['created_at']], $userId);

        Db::pdo()->prepare("UPDATE leads SET status = 'customer' WHERE id = ?")->execute([$leadId]);
        VatLock::releaseForLead($leadId);
        (new Scheduler())->cancelForEntity('lead', $leadId);

        $label = (string)$card['name'] . (!empty($card['customer_code']) ? ' (cod. ' . $card['customer_code'] . ')' : '');
        Activities::add('lead', $leadId, 'system',
            "Chiuso: già cliente. La richiesta è nei messaggi di $label (conversazione #$tk).", $userId);
        Log::write('crm', 'lead_closed_customer', 'lead', $leadId, [
            'card' => $cardId, 'ticket' => $tk, 'by' => $userId,
            'was'  => array_intersect_key($lead, array_flip(['status', 'stage_code', 'assigned_to', 'contact_id', 'referred_by_partner_id'])),
        ]);
        Leads::pushSync($leadId);
        return ['ok' => true, 'ticket_id' => $tk];
    }

    /**
     * Write a lead's request into the customer's message area — the chat on their
     * card — as the customer's own message, with a line saying who wrote and when
     * it came in. Quiet: nobody is paged for an old request being moved. The same
     * message twice within ten minutes (a double click) is written once.
     *
     * @return int the ticket id
     */
    private static function requestToCustomer(array $card, array $d, array $ctx = [], ?int $actorId = null): int
    {
        $cardId  = (int)$card['id'];
        $request = trim((string)($d['comments'] ?? ''));
        $vat     = trim((string)($d['vat_number'] ?? ''));
        $from    = array_values(array_filter([
            trim((string)($d['name'] ?? '')), trim((string)($d['phone'] ?? '')),
            trim((string)($d['email'] ?? '')), $vat !== '' ? 'P.IVA ' . $vat : '',
        ], 'strlen'));
        $meta = [];
        if ($from) {
            $meta[] = 'Da: ' . implode(' · ', $from);
        }
        if (!empty($d['fair_name'])) {
            $meta[] = 'Fiera: ' . $d['fair_name'] . (!empty($d['fair_city']) ? ' (' . $d['fair_city'] . ')' : '');
        }
        if (!empty($d['source_url'])) {
            $meta[] = 'Pagina: ' . $d['source_url'];
        }
        if (!empty($ctx['received_at'])) {
            $meta[] = 'Ricevuta il ' . date('d/m/Y H:i', (int)strtotime((string)$ctx['received_at']));
        }
        $body = ($request !== '' ? $request : 'Richiesta di contatto, senza messaggio.')
              . ($meta ? "\n\n— " . implode("\n— ", $meta) : '');

        $dup = Db::pdo()->prepare(
            "SELECT t.id FROM tickets t JOIN ticket_messages m ON m.ticket_id = t.id
              WHERE t.contact_id = ? AND m.sender_type = 'customer' AND m.body = ?
                AND m.created_at >= NOW() - INTERVAL 10 MINUTE
              ORDER BY m.id DESC LIMIT 1"
        );
        $dup->execute([$cardId, $body]);
        $seen = (int)($dup->fetchColumn() ?: 0);
        if ($seen > 0) {
            return $seen;
        }

        $tk = Tickets::open($cardId, 'Richiesta dal lead #' . (int)($ctx['lead_id'] ?? 0), $body, null, null, false);
        Log::write('crm', 'customer_request', 'contact', $cardId, [
            'door' => 'lead', 'ticket' => $tk, 'lead' => $ctx['lead_id'] ?? null,
            'name' => (string)($d['name'] ?? ''), 'phone' => (string)($d['phone'] ?? ''),
            'email' => (string)($d['email'] ?? ''), 'vat' => $vat, 'by' => $actorId,
        ]);
        return $tk;
    }

    /**
     * Put a lead on its customer's card. The lead-only contact it sat on is
     * merged into the card (merge()) — which carries along any other lead on that
     * contact, its documents, tickets and the rest — and the card takes the
     * phone, email and portal login it lacked. The lead itself keeps what it
     * says: who asked, and for what.
     *
     * @param string $how 'vat' (automatic) | 'manual' (the office) | 'close' (closeIntoCustomer)
     * @return array{ok:bool, error?:string, already?:bool, moved?:array, leads?:int[]}
     */
    public static function link(int $leadId, int $cardId, string $how, ?int $userId = null): array
    {
        $lead = Leads::find($leadId);
        if (!$lead) {
            return ['ok' => false, 'error' => 'no_lead'];
        }
        $card = Contacts::find($cardId);
        if (!$card || (int)$card['is_customer'] !== 1) {
            return ['ok' => false, 'error' => 'not_customer'];
        }
        $fromId = (int)($lead['contact_id'] ?? 0);
        if ($fromId === $cardId) {
            return ['ok' => true, 'already' => true];
        }
        $from = $fromId > 0 ? Contacts::find($fromId) : null;
        if ($from && ((int)$from['is_customer'] === 1 || $from['customer_code'] !== null)) {
            return ['ok' => false, 'error' => 'both_customers'];
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($from) {
                $m = self::merge($from, $card);
            } else {
                $pdo->prepare('UPDATE leads SET contact_id = ? WHERE id = ?')->execute([$cardId, $leadId]);
                $m = ['moved' => ['leads' => 1], 'leads' => [$leadId]];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $label = (string)$card['name'] . (!empty($card['customer_code']) ? ' (cod. ' . $card['customer_code'] . ')' : '');
        $why   = match ($how) {
            'vat'    => 'stessa partita IVA ' . (string)$card['vat_number'],
            'manual' => 'collegato dalla sede',
            'close'  => 'la sede ha spostato la richiesta nei suoi messaggi',
            default  => $how,
        };
        foreach ($m['leads'] as $lid) {
            Activities::add('lead', (int)$lid, 'system', "Già cliente: collegato alla scheda $label — $why.", $userId);
            Leads::pushSync((int)$lid);
        }
        Log::write('crm', 'lead_linked_customer', 'lead', $leadId, [
            'card' => $cardId, 'from_contact' => $fromId ?: null, 'how' => $how,
            'moved' => $m['moved'], 'leads' => $m['leads'], 'by' => $userId,
        ]);
        return ['ok' => true, 'moved' => $m['moved'], 'leads' => $m['leads']];
    }

    /**
     * Fold a lead-born contact into a registry card. Everything that hangs off it
     * follows — leads, signing documents and their codes, tickets, appointments,
     * install reports, contracts, invoices, network areas, the portal log — and
     * so do its timeline and its pending reminders. The card keeps what the
     * gestionale gave it and takes what it lacks: a phone (or a second phone,
     * when it has one already), an email, a PEC, a portal login. The lead-born
     * row is then deleted; the event log keeps its old id, and a contact_merged
     * entry says where it went. Runs inside link()'s transaction.
     *
     * @return array{moved: array<string,int>, leads: int[]}
     */
    private static function merge(array $from, array $into): array
    {
        $pdo    = Db::pdo();
        $fromId = (int)$from['id'];
        $intoId = (int)$into['id'];

        $ls = $pdo->prepare('SELECT id FROM leads WHERE contact_id = ? ORDER BY id');
        $ls->execute([$fromId]);
        $leads = array_map('intval', $ls->fetchAll(PDO::FETCH_COLUMN));

        $moved = [];
        foreach (self::contactTables() as $tb) {
            $st = $pdo->prepare("UPDATE `$tb` SET contact_id = ? WHERE contact_id = ?");
            $st->execute([$intoId, $fromId]);
            if ($st->rowCount() > 0) {
                $moved[$tb] = $st->rowCount();
            }
        }
        foreach (['activities', 'reminders'] as $tb) {
            $st = $pdo->prepare("UPDATE `$tb` SET entity_id = ? WHERE entity_type = 'contact' AND entity_id = ?");
            $st->execute([$intoId, $fromId]);
            if ($st->rowCount() > 0) {
                $moved[$tb . ' (timeline)'] = $st->rowCount();
            }
        }

        $blank = static fn($v): bool => trim((string)$v) === '';
        $set   = [];
        $fromPhone = trim((string)($from['phone'] ?? ''));
        if ($fromPhone !== '') {
            if ($blank($into['phone'] ?? '')) {
                $set['phone'] = $fromPhone;
            } elseif ($fromPhone !== (string)$into['phone'] && $fromPhone !== (string)($into['phone2'] ?? '')
                      && $blank($into['phone2'] ?? '')) {
                $set['phone2'] = $fromPhone;
            }
        }
        foreach (['email', 'pec', 'company', 'assigned_to'] as $k) {
            if ($blank($into[$k] ?? '') && !$blank($from[$k] ?? '')) {
                $set[$k] = $from[$k];
            }
        }
        // A portal login the customer made while they were "only a lead" must
        // survive the merge, or they are locked out of their own area.
        if ($blank($into['password_hash'] ?? '') && !$blank($from['password_hash'] ?? '')) {
            foreach (['password_hash', 'portal_enabled', 'portal_token', 'portal_token_expires', 'last_login_at'] as $k) {
                $set[$k] = $from[$k] ?? null;
            }
        }
        if ((int)($from['portal_access_count'] ?? 0) > 0) {
            $set['portal_access_count']   = (int)($into['portal_access_count'] ?? 0) + (int)$from['portal_access_count'];
            $set['portal_last_access_at'] = max((string)($into['portal_last_access_at'] ?? ''),
                                                (string)($from['portal_last_access_at'] ?? '')) ?: null;
        }

        // Delete first: whatever the card takes over (a portal token) must not
        // exist twice for even a moment.
        $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$fromId]);
        if ($set) {
            $cols = implode(', ', array_map(static fn($k) => "`$k` = ?", array_keys($set)));
            $pdo->prepare("UPDATE contacts SET $cols WHERE id = ?")->execute([...array_values($set), $intoId]);
        }
        Log::write('crm', 'contact_merged', 'contact', $intoId,
            ['from' => $fromId, 'moved' => $moved, 'filled' => array_keys($set)]);
        return ['moved' => $moved, 'leads' => $leads];
    }

    // ---- the automatic pass (VAT) ---------------------------------------------------

    /**
     * Compare the leads' VAT with the registry and act on it:
     *  - a lead whose VAT is on a registry card it is not on → link() it there;
     *  - a lead that is not a customer yet → its own contact carries its VAT, so
     *    the day the gestionale adds this customer the import ADOPTS the contact
     *    (it matches on VAT) instead of opening a second card beside it.
     * It links, it never closes: a lead whose customer appears in the registry
     * after it is usually the sale that made them a customer. Discarded and
     * closed leads are left alone. $leadId limits it to one lead (after an edit);
     * null runs every lead (after an import, and once as the backfill).
     *
     * @return array{linked:int, vat_synced:int, left_two_cards:int, linked_ids:int[], synced_contacts:int[], plan?:array}
     */
    public static function reconcile(?int $leadId = null, bool $dryRun = false): array
    {
        $out = ['linked' => 0, 'vat_synced' => 0, 'left_two_cards' => 0, 'linked_ids' => [], 'synced_contacts' => []];
        if ($dryRun) {
            $out['plan'] = [];
        }
        $rows = Db::pdo()->query(
            "SELECT l.id, l.vat_number AS lvat, l.contact_id, c.vat_number AS cvat, c.is_customer, c.customer_code
               FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id
              WHERE l.status IN ('open', 'converted')" . ($leadId ? ' AND l.id = ' . (int)$leadId : '') . ' ORDER BY l.id'
        )->fetchAll();

        foreach ($rows as $r) {
            $lv = Contacts::matchableVat((string)$r['lvat']);
            $v  = $lv !== '' ? $lv : Contacts::matchableVat((string)$r['cvat']);
            if ($v === '') {
                continue;
            }
            $card = self::byVat($v);
            if ($card) {
                if ((int)$card['id'] === (int)$r['contact_id']) {
                    continue;
                }
                if ((int)$r['is_customer'] === 1 || $r['customer_code'] !== null) {
                    $out['left_two_cards']++;
                    if ($dryRun) {
                        $out['plan'][] = ['lead' => (int)$r['id'], 'action' => 'two_cards', 'card' => (int)$card['id']];
                    }
                    continue;
                }
                if ($dryRun) {
                    $out['plan'][] = ['lead' => (int)$r['id'], 'action' => 'link', 'card' => (int)$card['id']];
                    $out['linked']++;
                    continue;
                }
                $res = self::link((int)$r['id'], (int)$card['id'], 'vat');
                if (!empty($res['ok']) && empty($res['already'])) {
                    $out['linked']++;
                    $out['linked_ids'][] = (int)$r['id'];
                }
                continue;
            }
            $cid = (int)$r['contact_id'];
            if ($lv !== '' && $cid > 0 && (int)$r['is_customer'] !== 1 && $r['customer_code'] === null
                && $lv !== (string)$r['cvat']) {
                if ($dryRun) {
                    $out['plan'][] = ['lead' => (int)$r['id'], 'action' => 'vat_to_contact', 'contact' => $cid, 'vat' => $lv];
                } else {
                    Db::pdo()->prepare('UPDATE contacts SET vat_number = ? WHERE id = ?')->execute([$lv, $cid]);
                    $out['synced_contacts'][] = $cid;
                }
                $out['vat_synced']++;
            }
        }
        if (!$dryRun && ($out['linked'] || $out['vat_synced'])) {
            Log::write('crm', 'leads_reconciled', null, null, $out);
        }
        return $out;
    }

    // ---- helpers ------------------------------------------------------------------------

    /** Active administrators with a phone or an email — who the alerts go to. */
    private static function admins(): array
    {
        return Db::pdo()->query(
            "SELECT id, full_name, username, phone, email FROM users
              WHERE role = 'admin' AND active = 1
                AND ((phone IS NOT NULL AND phone <> '') OR (email IS NOT NULL AND email <> ''))
              ORDER BY id"
        )->fetchAll() ?: [];
    }

    /** Only the channels this person has: an administrator with no email gets the WhatsApp alone. */
    private static function channelFor(array $u): string
    {
        $phone = trim((string)($u['phone'] ?? '')) !== '';
        $email = trim((string)($u['email'] ?? '')) !== '';
        return $phone && $email ? 'both' : ($phone ? 'whatsapp' : 'email');
    }

    private static function staffName(?int $userId): string
    {
        if (!$userId) {
            return 'CRM';
        }
        $s = Db::pdo()->prepare("SELECT COALESCE(NULLIF(full_name, ''), username) FROM users WHERE id = ?");
        $s->execute([$userId]);
        return (string)($s->fetchColumn() ?: 'CRM');
    }

    /**
     * Both spellings the CRM stores a number in: leads keep Notifier's (an Italian
     * landline loses its trunk 0), the registry keeps the import's (the 0 stays) —
     * the same pair the new-lead form's "number already registered" check uses. A
     * lead's number is already in Notifier's form, so for a landline the registry
     * form is rebuilt by putting the 0 back.
     *
     * @return string[]
     */
    private static function phoneForms(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $forms = [Notifier::normalizePhone($raw), CustomerImport::phone($raw)];
        foreach ($forms as $f) {
            if (preg_match('/^\+39([1-9]\d{5,})$/', (string)$f, $m) && $m[1][0] !== '3') {
                $forms[] = '+390' . $m[1];
            }
        }
        return array_values(array_unique(array_filter(array_map('strval', $forms),
            static fn(string $f): bool => strlen($f) >= 7)));
    }

    /** Base tables with a contact_id column — where a merged contact's rows live. */
    private static function contactTables(): array
    {
        if (self::$contactTables === null) {
            self::$contactTables = Db::pdo()->query(
                "SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
                   JOIN information_schema.TABLES t
                     ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
                  WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'contact_id' AND c.TABLE_NAME <> 'contacts'
                  ORDER BY c.TABLE_NAME"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        return self::$contactTables;
    }
}
