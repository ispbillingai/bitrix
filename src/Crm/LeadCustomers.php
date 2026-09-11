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
 *   "Leads are arriving who are actually already customers, and this creates
 *    confusion." — "he sells different products: the contact had bought a
 *    product in the past and now asks for a new one." — "If you find a lead and
 *    a customer you must delete the lead, take only the information he asked for
 *    and insert it in the customer message area." — "In all cases the CRM
 *    administrators must be notified of the new request."
 *
 * So a customer's request is not a lead. At every door a request comes in by —
 * the website and fair forms, a partner's area, the new-lead form, the website
 * API, the mailbox importer — intake() asks first whether this is a customer:
 * same partita IVA as a registry card, or the phone/email of exactly one card.
 * If so, no lead is made. What they asked for is written into the customer's
 * message area (their chat, Tickets) as their own message, their agent is told
 * by the chat itself, and every administrator is told as well.
 *
 * A lead that is ALREADY in the CRM and turns out to be a customer is not
 * deleted but CLOSED (status 'customer', closeIntoCustomer()): its request goes
 * into the chat the same way, it leaves the board and the open counts, and it
 * stays on record — deals, invoices, partner commissions and quote requests all
 * point at leads, and deleting them would break every one of those. A CONVERTED
 * lead is how the customer was won: it is only linked to the card (link()).
 *
 * A phone or email on TWO cards is a question for a person, not a match: such a
 * request becomes an ordinary lead that says "forse già cliente" (suggestions()),
 * and the office moves it with one click. The administrators are alerted about
 * that request as well (maybeNotifications()) — "in all cases".
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

    // ---- a new request from a customer ------------------------------------------------

    /**
     * The check every door runs before a request may become a lead. null = not a
     * customer, carry on as before; otherwise the request is already in the
     * customer's messages and the administrators have been told.
     *
     * @param string $door website | fair | partner | manual | intake
     * @param array  $ctx  partner (name) | source (the intake's source)
     * @return array{card: array, ticket_id: int, how: string}|null
     */
    public static function intake(array $d, string $door, array $ctx = [], ?int $actorId = null): ?array
    {
        $m = self::matchCustomer($d);
        if ($m === null) {
            // Not ONE customer — but two or three cards may share this phone or
            // email. Then it carries on as an ordinary lead ("forse già cliente")
            // and the administrators are alerted about this request as well: "in
            // all cases". Not from the new-lead form: there the phone check right
            // after refuses a number that sits on a customer's card.
            if ($door !== 'manual') {
                $maybe = self::cardsByPhoneEmail(self::phoneForms((string)($d['phone'] ?? '')),
                                                 mb_strtolower(trim((string)($d['email'] ?? ''))));
                if (count($maybe) >= 2) {
                    Log::write('crm', 'customer_maybe_request', 'contact', (int)$maybe[0]['id'], [
                        'door'  => $door, 'cards' => array_map(static fn(array $c): int => (int)$c['id'], $maybe),
                        'name'  => (string)($d['name'] ?? ''), 'phone' => (string)($d['phone'] ?? ''),
                        'email' => (string)($d['email'] ?? ''),
                    ]);
                    self::notifyAdminsMaybe($maybe, $d, $door, $ctx, $actorId);
                }
            }
            return null;
        }
        $tk = self::requestToCustomer($m['card'], $d, $door, $ctx + ['how' => $m['how']], $actorId, true);
        return ['card' => $m['card'], 'ticket_id' => $tk, 'how' => $m['how']];
    }

    /**
     * Write a request into the customer's message area — the chat on their card,
     * the thread the office and the customer already talk in — as the customer's
     * own message: "take only the information he asked for". A short line under
     * it says who wrote and how it came in, because the person asking is not
     * always the one on the card (an employee, a partner passing it on).
     *
     * $notify: the customer's agent (through the chat) and every administrator
     * are told. Off only when an OLD request is being moved (closeIntoCustomer):
     * that is not a new request.
     *
     * The same message twice within ten minutes — a double submit, a webhook
     * retry — is written once.
     *
     * @return int the ticket id
     */
    public static function requestToCustomer(array $card, array $d, string $door, array $ctx = [],
                                             ?int $actorId = null, bool $notify = true): int
    {
        $cardId  = (int)$card['id'];
        $request = trim((string)($d['comments'] ?? ''));
        $subject = self::subjectFor($door, $d, $ctx, $actorId);

        $vat  = trim((string)($d['vat_number'] ?? ''));
        $from = array_values(array_filter([
            trim((string)($d['name'] ?? '')), trim((string)($d['phone'] ?? '')),
            trim((string)($d['email'] ?? '')), trim((string)($d['company'] ?? '')),
            $vat !== '' ? 'P.IVA ' . $vat : '',
        ], 'strlen'));
        $meta = [];
        if ($from) {
            $meta[] = 'Da: ' . implode(' · ', $from);
        }
        if (!empty($d['preferred_at'])) {
            $meta[] = 'Appuntamento preferito: ' . $d['preferred_at'];
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

        $tk = Tickets::open($cardId, $subject, $body, null, null, $notify);
        Log::write('crm', 'customer_request', 'contact', $cardId, [
            'door' => $door, 'ticket' => $tk, 'how' => $ctx['how'] ?? null, 'lead' => $ctx['lead_id'] ?? null,
            'name' => (string)($d['name'] ?? ''), 'phone' => (string)($d['phone'] ?? ''),
            'email' => (string)($d['email'] ?? ''), 'vat' => $vat, 'by' => $actorId,
        ]);
        if ($notify) {
            self::notifyAdmins($card, $subject, $request, implode(' · ', $from), $tk);
        }
        return $tk;
    }

    /**
     * One queued message per administrator who can be reached, through the same
     * queue as every other CRM message — it spaces the WhatsApp sends and retries
     * a failed one. Built apart from the sending, so it can be checked without
     * sending anything.
     *
     * @return array<int,array> Scheduler::enqueue rows
     */
    public static function adminNotifications(array $card, string $subject, string $request, string $from, int $ticketId): array
    {
        $link    = Config::appBaseUrl() . '/dashboard.php?tab=tickets&tk=' . $ticketId;
        $code    = !empty($card['customer_code']) ? ' (cod. ' . $card['customer_code'] . ')' : '';
        $excerpt = mb_strlen($request) > 400 ? mb_substr($request, 0, 400) . '…' : $request;
        if ($excerpt === '') {
            $excerpt = 'Richiesta di contatto, senza messaggio.';
        }
        $users = self::admins();

        $rows = [];
        foreach ($users as $u) {
            $rows[] = [
                'entity_type'    => 'contact',
                'entity_id'      => (int)$card['id'],
                'rule_key'       => 'customer_request_admin',
                'recipient_type' => 'agent',
                'channel'        => self::channelFor($u),
                'due_at'         => date('Y-m-d H:i:s'),
                'dedupe_key'     => 'custreq:' . $ticketId . ':' . (int)$u['id'] . ':'
                                    . substr(md5($subject . '|' . $request . '|' . $from), 0, 12),
                // The payload carries the administrator's own phone and email:
                // the queue addresses an 'agent' recipient from these.
                'payload'        => [
                    'name'          => trim((string)($u['full_name'] ?? '')) ?: (string)$u['username'],
                    'customer_name' => (string)$card['name'],
                    'code'          => $code,
                    'customer_html' => htmlspecialchars((string)$card['name'] . $code, ENT_QUOTES),
                    'subject'       => $subject,
                    'request'       => $excerpt,
                    'request_html'  => nl2br(htmlspecialchars($excerpt, ENT_QUOTES)),
                    'from'          => $from !== '' ? $from : '—',
                    'from_html'     => htmlspecialchars($from !== '' ? $from : '—', ENT_QUOTES),
                    'id'            => (string)$ticketId,
                    'link'          => $link,
                    'agent_phone'   => (string)($u['phone'] ?? ''),
                    'agent_email'   => (string)($u['email'] ?? ''),
                ],
            ];
        }
        return $rows;
    }

    /** "In all cases the CRM administrators must be notified of the new request." */
    private static function notifyAdmins(array $card, string $subject, string $request, string $from, int $ticketId): void
    {
        // Always on; a test process switches it off for itself (Config overlay).
        if (!Config::get('crm.customer_request_notify', true)) {
            return;
        }
        try {
            $sch = new Scheduler();
            foreach (self::adminNotifications($card, $subject, $request, $from, $ticketId) as $row) {
                $sch->enqueue($row);
            }
        } catch (Throwable $e) {
            // The request is in the customer's messages either way.
            Log::write('crm', 'customer_request_notify_failed', 'contact', (int)$card['id'], ['error' => $e->getMessage()]);
        }
    }

    /**
     * The alert for a request that MAY be a customer's: its phone or email sits
     * on two or three cards, so it went on as an ordinary lead ("forse già
     * cliente") for a person to check — and "in all cases the CRM administrators
     * must be notified of the new request". The link opens the leads board
     * searched on that phone (or email). Built apart from the sending, like
     * adminNotifications().
     *
     * @return array<int,array> Scheduler::enqueue rows
     */
    public static function maybeNotifications(array $cards, array $d, string $door, array $ctx = [], ?int $actorId = null): array
    {
        $request = trim((string)($d['comments'] ?? ''));
        $excerpt = $request === '' ? 'Richiesta di contatto, senza messaggio.'
                 : (mb_strlen($request) > 400 ? mb_substr($request, 0, 400) . '…' : $request);
        $phone = Notifier::normalizePhone((string)($d['phone'] ?? ''));
        $email = trim((string)($d['email'] ?? ''));
        $from  = implode(' · ', array_values(array_filter([
            trim((string)($d['name'] ?? '')), $phone, $email, trim((string)($d['company'] ?? '')),
        ], 'strlen'))) ?: '—';
        $names = implode('; ', array_map(static fn(array $c): string => (string)$c['name']
            . (!empty($c['customer_code']) ? ' (cod. ' . $c['customer_code'] . ')' : ''), $cards));
        $q       = $phone !== '' ? $phone : ($email !== '' ? $email : trim((string)($d['name'] ?? '')));
        $link    = Config::appBaseUrl() . '/dashboard.php?tab=leads&q=' . rawurlencode(mb_substr($q, 0, 100));
        $subject = self::subjectFor($door, $d, $ctx, $actorId);

        $rows = [];
        foreach (self::admins() as $u) {
            $rows[] = [
                'entity_type'    => 'contact',
                'entity_id'      => (int)$cards[0]['id'],
                'rule_key'       => 'customer_maybe_admin',
                'recipient_type' => 'agent',
                'channel'        => self::channelFor($u),
                'due_at'         => date('Y-m-d H:i:s'),
                'dedupe_key'     => 'custmaybe:' . substr(md5($from . '|' . $request . '|' . date('YmdHi')), 0, 16)
                                    . ':' . (int)$u['id'],
                'payload'        => [
                    'name'         => trim((string)($u['full_name'] ?? '')) ?: (string)$u['username'],
                    'subject'      => $subject,
                    'from'         => $from,
                    'from_html'    => htmlspecialchars($from, ENT_QUOTES),
                    'cards'        => $names,
                    'cards_html'   => htmlspecialchars($names, ENT_QUOTES),
                    'request'      => $excerpt,
                    'request_html' => nl2br(htmlspecialchars($excerpt, ENT_QUOTES)),
                    'link'         => $link,
                    'agent_phone'  => (string)($u['phone'] ?? ''),
                    'agent_email'  => (string)($u['email'] ?? ''),
                ],
            ];
        }
        return $rows;
    }

    private static function notifyAdminsMaybe(array $cards, array $d, string $door, array $ctx, ?int $actorId): void
    {
        // Always on; a test process switches it off for itself (Config overlay).
        if (!Config::get('crm.customer_request_notify', true)) {
            return;
        }
        try {
            $sch = new Scheduler();
            foreach (self::maybeNotifications($cards, $d, $door, $ctx, $actorId) as $row) {
                $sch->enqueue($row);
            }
        } catch (Throwable $e) {
            Log::write('crm', 'customer_request_notify_failed', 'contact', (int)$cards[0]['id'], ['error' => $e->getMessage()]);
        }
    }

    // ---- a lead already in the CRM ---------------------------------------------------

    /**
     * An OPEN lead that turns out to be a customer: what they asked for goes into
     * the customer's messages and the lead is closed as 'customer' — off the
     * board, out of the open counts, still on record, reversible. A converted
     * lead is refused: it is how the customer was won, not a duplicate of them.
     *
     * Quiet: moving an old request is not a new one, so nobody is paged.
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
        ], 'lead', ['lead_id' => $leadId, 'received_at' => $lead['received_at'] ?: $lead['created_at']], $userId, false);

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

    private static function subjectFor(string $door, array $d, array $ctx, ?int $actorId): string
    {
        return match ($door) {
            'website' => !empty($ctx['partner']) ? 'Nuova richiesta dal sito (link di ' . $ctx['partner'] . ')'
                                                 : 'Nuova richiesta dal sito',
            'fair'    => 'Nuova richiesta dalla fiera' . (!empty($d['fair_name']) ? ' ' . $d['fair_name'] : ''),
            'partner' => 'Nuova richiesta dal partner ' . (string)($ctx['partner'] ?? ''),
            'manual'  => 'Nuova richiesta inserita da ' . self::staffName($actorId),
            'intake'  => 'Nuova richiesta' . (!empty($ctx['source']) ? ' (' . $ctx['source'] . ')' : ''),
            'lead'    => 'Richiesta dal lead #' . (int)($ctx['lead_id'] ?? 0),
            default   => 'Nuova richiesta',
        };
    }

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
