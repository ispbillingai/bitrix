<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Sign\Documents as SignDocs;
use Throwable;

/**
 * Quote requests — an agent asks the back office to price something, the office
 * uploads the finished quote, the agent sends it to the customer for review and
 * signature. The client's spec, end to end:
 *
 *   "Within a lead record a REQUEST QUOTE button, so the agent can request a
 *    quote from the back office. The office receives a notification and the
 *    request is logged against the specific lead (which already contains the
 *    company name / legal entity details). A free-text notes field lets the
 *    agent specify the quote requirements. Once the quote is ready the office
 *    uploads it to the lead's area, and the agent sends it to the lead via the
 *    CRM for review and potential signature."
 *
 *   "...and a Request Quote form (similar to JotForm) to request one from
 *    scratch, populating the same fields as the lead record, validating on
 *    company name/VAT number or phone/name; if the record does not already
 *    exist, generate a new lead and attach the quote request to it."
 *
 * EVERY request hangs off a lead — that is the invariant this class keeps. The
 * in-lead button already has one; the from-scratch form finds one (fromScratch)
 * or creates it. Nothing here invents a second place for customer data to live.
 *
 * The quote document is NOT stored in this table: it goes through the existing
 * in-house signing flow (Sign\Documents), which already does the tokenised
 * reading link, the one-time code, the sealed PDF and the audit chain. This
 * table only remembers WHICH document answers WHICH request.
 */
final class QuoteRequests
{
    /** open → the office owes a quote; ready → uploaded; sent → with the customer. */
    public const OPEN = 'open';
    public const READY = 'ready';
    public const SENT = 'sent';
    public const CANCELLED = 'cancelled';

    // ---- asking ---------------------------------------------------------------

    /**
     * File a request against a lead that already exists — the REQUEST QUOTE
     * button inside the lead record. The lead carries the company and the legal
     * details, so the only thing asked for here is what the quote must contain.
     *
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function open(int $leadId, ?int $userId, string $notes): array
    {
        $lead = Leads::find($leadId);
        if (!$lead) {
            return ['ok' => false, 'error' => 'no_lead'];
        }
        $notes = trim($notes);
        if ($notes === '') {
            return ['ok' => false, 'error' => 'no_notes'];
        }

        Db::pdo()->prepare(
            'INSERT INTO quote_requests (lead_id, requested_by, notes, status)
             VALUES (?, ?, ?, ?)'
        )->execute([$leadId, $userId ?: null, $notes, self::OPEN]);
        $id = (int)Db::pdo()->lastInsertId();

        // On the lead's own timeline, where the office reads the history of the
        // customer — a request the seller made is part of that history.
        Activities::add('lead', $leadId, 'system',
            "Quote requested from the back office (#$id):\n" . $notes, $userId);
        Log::write('crm', 'quote_requested', 'lead', $leadId,
            ['request_id' => $id, 'by' => $userId]);

        self::notifyOffice($id, $lead, $notes, $userId);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * The from-scratch form. Resolves the customer the way the client asked —
     * company/VAT, or phone/name — and files the request on the lead that comes
     * back, creating one when nothing matches.
     *
     * @param array $d first_name|last_name|name|company|vat_number|phone|email|
     *                 zone|source|lang|notes|assign_to
     * @return array{ok:bool, id?:int, lead_id?:int, created?:bool, error?:string}
     */
    public static function fromScratch(array $d, ?int $userId): array
    {
        $name    = trim((string)($d['name'] ?? '')) ?: Contacts::fullName(
            (string)($d['first_name'] ?? ''), (string)($d['last_name'] ?? ''));
        $company = trim((string)($d['company'] ?? ''));
        $vat     = VatLock::normalize((string)($d['vat_number'] ?? ''));
        $phone   = Notifier::normalizePhone((string)($d['phone'] ?? ''));
        $email   = trim((string)($d['email'] ?? ''));
        $notes   = trim((string)($d['notes'] ?? ''));

        if ($notes === '') {
            return ['ok' => false, 'error' => 'no_notes'];
        }
        // "a validation check based on company name/VAT number or phone number/
        // name": one of those two pairs has to be there, or there is no customer
        // to quote and nothing to match against.
        if (($company === '' && $vat === '') && ($phone === '' && $name === '')) {
            return ['ok' => false, 'error' => 'no_identity'];
        }

        $leadId  = self::matchLead(['name' => $name, 'company' => $company, 'vat_number' => $vat,
                                    'phone' => $phone, 'email' => $email]);
        $created = false;
        if ($leadId === null) {
            $leadId = Leads::create([
                'name' => $name ?: ($company ?: 'Quote request'),
                'phone' => $phone, 'email' => $email, 'company' => $company,
                'vat_number' => $vat, 'zone' => $d['zone'] ?? '',
                'source' => trim((string)($d['source'] ?? '')) ?: 'quote',
                'lang' => $d['lang'] ?? null,
                'comments' => $notes,
            ], $userId);
            $created = true;
            // The seller typing a customer in owns them, exactly as lead_create
            // on the Leads tab does — otherwise their own entry lands outside
            // their scope and they never see it again. The caller decides who
            // that is: an agent themselves, nobody when the office fills it in.
            $assignTo = (int)($d['assign_to'] ?? 0);
            if ($assignTo > 0) {
                Leads::assign($leadId, $assignTo, $userId);
            }
        }

        $res = self::open($leadId, $userId, $notes);
        if (empty($res['ok'])) {
            return $res;
        }
        return ['ok' => true, 'id' => (int)$res['id'], 'lead_id' => $leadId, 'created' => $created];
    }

    /**
     * A quote asked for from a CUSTOMER's page — "they want a price, raise it".
     *
     * Quote requests hang off leads, and a registry customer imported from the
     * gestionale usually has none: 10,000 customers arrived as contacts, not as
     * leads. So one is opened for them, QUIETLY (they are already a customer —
     * welcoming them as a new lead and then nudging them for not replying would
     * be nonsense) and against their EXISTING contact row, so the request shows
     * up on the page it was asked from rather than on a twin.
     *
     * An open lead they already have is reused instead.
     *
     * @return array{ok:bool, id?:int, lead_id?:int, error?:string}
     */
    public static function forCustomer(int $contactId, ?int $userId, string $notes): array
    {
        $c = Contacts::find($contactId);
        if (!$c) {
            return ['ok' => false, 'error' => 'no_contact'];
        }
        if (trim($notes) === '') {
            return ['ok' => false, 'error' => 'no_notes'];
        }

        $stmt = Db::pdo()->prepare(
            "SELECT id FROM leads WHERE contact_id = ? AND status IN ('open','converted')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$contactId]);
        $leadId = (int)($stmt->fetchColumn() ?: 0);

        if ($leadId <= 0) {
            $leadId = Leads::create([
                'contact_id' => $contactId,
                'quiet'      => true,
                'name'       => (string)($c['name'] ?? '') ?: 'Cliente',
                'company'    => (string)($c['company'] ?? ''),
                'phone'      => (string)($c['phone'] ?? ''),
                'email'      => (string)($c['email'] ?? ''),
                'vat_number' => (string)($c['vat_number'] ?? ''),
                'lang'       => $c['lang'] ?? null,
                'source'     => 'quote',
                'title'      => 'Preventivo — ' . (string)($c['name'] ?? ''),
            ], $userId);
        }

        $res = self::open($leadId, $userId, $notes);
        if (empty($res['ok'])) {
            return $res;
        }
        return ['ok' => true, 'id' => (int)$res['id'], 'lead_id' => $leadId];
    }

    /**
     * The lead these details already belong to, or null.
     *
     * Identity first, through the one duplicate rule the whole CRM shares
     * (Leads::duplicateId — VAT, phone, email). COMPANY NAME is the addition the
     * quote form needs: a back-office quote is asked for by company far more
     * often than by the person's mobile, and "Panificio Salerno" typed twice must
     * not open two leads. Matched case-insensitively against the contact's
     * company, and only on a lead still worth attaching to (open, or already a
     * customer) — a discarded one must not swallow a fresh request.
     */
    public static function matchLead(array $d): ?int
    {
        $byIdentity = Leads::duplicateId([
            'name'       => (string)($d['name'] ?? ''),
            'phone'      => (string)($d['phone'] ?? ''),
            'email'      => (string)($d['email'] ?? ''),
            'vat_number' => (string)($d['vat_number'] ?? ''),
            'source'     => 'quote',
        ]);
        if ($byIdentity !== null) {
            return $byIdentity;
        }

        $company = trim((string)($d['company'] ?? ''));
        if ($company === '') {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            "SELECT l.id FROM leads l
               JOIN contacts c ON c.id = l.contact_id
              WHERE c.company <> '' AND LOWER(c.company) = LOWER(?)
                AND l.status IN ('open','converted')
              ORDER BY l.id DESC LIMIT 1"
        );
        $stmt->execute([$company]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    // ---- answering ------------------------------------------------------------

    /**
     * The office uploads the finished quote. It becomes a signing document for
     * the lead's contact — draft, not sent: sending is the agent's move, which
     * is what the client asked for ("the agent sends it to the lead").
     *
     * @param array|null $file a $_FILES entry (PDF)
     * @return array{ok:bool, document_id?:int, error?:string}
     */
    public static function attachQuote(int $id, ?array $file, ?int $userId = null): array
    {
        $r = self::find($id);
        if (!$r) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ((string)$r['status'] === self::CANCELLED) {
            return ['ok' => false, 'error' => 'cancelled'];
        }
        $lead = Leads::find((int)$r['lead_id']);
        if (!$lead || (int)($lead['contact_id'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'no_contact'];
        }

        $title = trim((string)Config::get('crm.quote_title', '')) ?: 'Preventivo';
        $who   = trim((string)($lead['customer_name'] ?? ''));
        $doc = SignDocs::create([
            'title'      => $who !== '' ? "$title — $who" : $title,
            'contact_id' => (int)$lead['contact_id'],
            'lang'       => $lead['lang'] ?? null,
        ], $file, $userId);
        if (empty($doc['ok'])) {
            return ['ok' => false, 'error' => (string)($doc['error'] ?? 'save_failed')];
        }

        Db::pdo()->prepare(
            'UPDATE quote_requests SET document_id = ?, status = ?, ready_at = NOW() WHERE id = ?'
        )->execute([(int)$doc['id'], self::READY, $id]);

        Activities::add('lead', (int)$r['lead_id'], 'system',
            "Quote uploaded by the back office for request #$id", $userId);
        Log::write('crm', 'quote_uploaded', 'lead', (int)$r['lead_id'],
            ['request_id' => $id, 'document_id' => (int)$doc['id'], 'by' => $userId]);

        self::notifyRequester($id, $lead);
        return ['ok' => true, 'document_id' => (int)$doc['id']];
    }

    /**
     * The agent sends the quote to the customer: the existing signing flow does
     * the messaging, the tokenised reading page and the one-time code, so the
     * customer can read it and — if they want to — sign it there and then.
     */
    public static function sendToCustomer(int $id, ?int $userId = null): array
    {
        $r = self::find($id);
        if (!$r || empty($r['document_id'])) {
            return ['ok' => false, 'error' => 'no_quote'];
        }
        if (!SignDocs::send((int)$r['document_id'], $userId)) {
            return ['ok' => false, 'error' => 'send_failed'];
        }
        Db::pdo()->prepare(
            'UPDATE quote_requests SET status = ?, sent_at = COALESCE(sent_at, NOW()) WHERE id = ?'
        )->execute([self::SENT, $id]);

        Activities::add('lead', (int)$r['lead_id'], 'system',
            "Quote #$id sent to the customer for review and signature", $userId);
        Log::write('crm', 'quote_sent', 'lead', (int)$r['lead_id'],
            ['request_id' => $id, 'document_id' => (int)$r['document_id'], 'by' => $userId]);
        return ['ok' => true];
    }

    /** Withdraw a request the office is never going to answer. */
    public static function cancel(int $id, ?int $userId = null): bool
    {
        $r = self::find($id);
        if (!$r || (string)$r['status'] === self::SENT) {
            return false;
        }
        Db::pdo()->prepare('UPDATE quote_requests SET status = ? WHERE id = ?')
            ->execute([self::CANCELLED, $id]);
        Activities::add('lead', (int)$r['lead_id'], 'system', "Quote request #$id cancelled", $userId);
        Log::write('crm', 'quote_cancelled', 'lead', (int)$r['lead_id'], ['request_id' => $id, 'by' => $userId]);
        return true;
    }

    // ---- reads ----------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $s = Db::pdo()->prepare('SELECT * FROM quote_requests WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /**
     * The queue. $agentId scopes it to one seller's own requests — an agent sees
     * what they asked for, an admin sees everything.
     */
    public static function all(?int $agentId = null, int $limit = 300): array
    {
        $limit = max(1, min(1000, $limit));
        $where = $agentId ? ' WHERE q.requested_by = ' . (int)$agentId : '';
        return Db::pdo()->query(
            "SELECT q.*, l.customer_name, l.customer_phone, l.customer_email, l.vat_number,
                    l.zone, l.status AS lead_status, l.stage_code, ct.company,
                    u.username AS requester_username, u.full_name AS requester_name,
                    d.status AS doc_status, d.title AS doc_title, d.signed_at, d.uid AS doc_uid
               FROM quote_requests q
               JOIN leads l ON l.id = q.lead_id
               LEFT JOIN contacts ct ON ct.id = l.contact_id
               LEFT JOIN users u ON u.id = q.requested_by
               LEFT JOIN sign_documents d ON d.id = q.document_id
             $where ORDER BY q.id DESC LIMIT $limit"
        )->fetchAll() ?: [];
    }

    /** Every request filed on one lead — shown inside the lead record itself. */
    public static function forLead(int $leadId): array
    {
        $s = Db::pdo()->prepare(
            "SELECT q.*, u.username AS requester_username, u.full_name AS requester_name,
                    d.status AS doc_status, d.title AS doc_title, d.signed_at
               FROM quote_requests q
               LEFT JOIN users u ON u.id = q.requested_by
               LEFT JOIN sign_documents d ON d.id = q.document_id
              WHERE q.lead_id = ? ORDER BY q.id DESC"
        );
        $s->execute([$leadId]);
        return $s->fetchAll() ?: [];
    }

    /**
     * Every quote request for a CUSTOMER rather than for one lead — the card on
     * the customer page. Requests hang off leads, and a customer can have more
     * than one lead behind them over the years, so it reaches through the lead
     * to the contact instead of asking for a lead id the caller does not have.
     */
    public static function forContact(int $contactId): array
    {
        $s = Db::pdo()->prepare(
            "SELECT q.*, l.customer_name, l.contact_id,
                    u.username AS requester_username, u.full_name AS requester_name,
                    d.status AS doc_status, d.title AS doc_title, d.signed_at, d.signed_path
               FROM quote_requests q
               JOIN leads l ON l.id = q.lead_id
               LEFT JOIN users u ON u.id = q.requested_by
               LEFT JOIN sign_documents d ON d.id = q.document_id
              WHERE l.contact_id = ? ORDER BY q.id DESC"
        );
        $s->execute([$contactId]);
        return $s->fetchAll() ?: [];
    }

    /** How many requests sit in each status — the tiles on the Quotes tab. */
    public static function counts(?int $agentId = null): array
    {
        $where = $agentId ? ' WHERE requested_by = ' . (int)$agentId : '';
        $out = [self::OPEN => 0, self::READY => 0, self::SENT => 0, self::CANCELLED => 0];
        foreach (Db::pdo()->query("SELECT status, COUNT(*) n FROM quote_requests$where GROUP BY status") as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /** Requests still waiting on the office — the badge the office actually needs. */
    public static function openCount(): int
    {
        return (int)Db::pdo()->query(
            "SELECT COUNT(*) FROM quote_requests WHERE status = 'open'"
        )->fetchColumn();
    }

    // ---- notifications --------------------------------------------------------

    /**
     * "The office receives a notification." ADMIN USERS ONLY, by WhatsApp and
     * email — the client's rule, stated plainly: a quote request goes to the back
     * office and to nobody else. Not the technicians, not the other sellers, not
     * the customer. sendToStaff() is called with 'admin' and there is deliberately
     * no fallback to another role if no admin is reachable: a quote request going
     * to the wrong desk is worse than one sitting in the queue on screen.
     */
    private static function notifyOffice(int $id, array $lead, string $notes, ?int $userId): void
    {
        try {
            $who     = trim((string)($lead['customer_name'] ?? '')) ?: ('#' . (int)$lead['id']);
            $company = trim((string)(Contacts::find((int)($lead['contact_id'] ?? 0))['company'] ?? ''));
            $agent   = self::staffName($userId);
            $link    = Config::appBaseUrl() . '/dashboard.php?tab=quotes';
            $brand   = (string)Config::get('app.company_name', 'CRM');

            $head = "📄 $brand — nuova richiesta di preventivo da $agent\n"
                . 'Cliente: ' . $who . ($company !== '' ? " ($company)" : '')
                . (!empty($lead['vat_number']) ? "\nP.IVA: " . (string)$lead['vat_number'] : '')
                . (!empty($lead['customer_phone']) ? "\nTel: " . (string)$lead['customer_phone'] : '');
            $text = $head . "\n\nRichiesta:\n" . $notes . "\n\nCarica il preventivo: " . $link;

            $html = '<p>📄 <b>Nuova richiesta di preventivo</b> da ' . htmlspecialchars($agent, ENT_QUOTES) . '</p>'
                . '<p>Cliente: <b>' . htmlspecialchars($who, ENT_QUOTES) . '</b>'
                . ($company !== '' ? ' (' . htmlspecialchars($company, ENT_QUOTES) . ')' : '')
                . (!empty($lead['vat_number']) ? '<br>P.IVA: ' . htmlspecialchars((string)$lead['vat_number'], ENT_QUOTES) : '')
                . (!empty($lead['customer_phone']) ? '<br>Tel: ' . htmlspecialchars((string)$lead['customer_phone'], ENT_QUOTES) : '')
                . '</p><p><b>Richiesta:</b><br>' . nl2br(htmlspecialchars($notes, ENT_QUOTES)) . '</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Carica il preventivo nel CRM</a></p>';

            self::sendToStaff('admin', $text, "Richiesta preventivo #$id — $who", $html);
        } catch (Throwable $e) {
            // Notifying the office must never lose the request itself.
            Log::write('crm', 'quote_notify_failed', 'lead', (int)($lead['id'] ?? 0),
                ['request_id' => $id, 'error' => $e->getMessage()]);
        }
    }

    /** The quote is ready — tell the agent who asked, so they can send it on. */
    private static function notifyRequester(int $id, array $lead): void
    {
        try {
            $r = self::find($id);
            $uid = (int)($r['requested_by'] ?? 0);
            if ($uid <= 0) {
                return;
            }
            $s = Db::pdo()->prepare('SELECT phone, email FROM users WHERE id = ? AND active = 1');
            $s->execute([$uid]);
            $u = $s->fetch();
            if (!$u) {
                return;
            }
            $who   = trim((string)($lead['customer_name'] ?? '')) ?: ('#' . (int)$lead['id']);
            $link  = Config::appBaseUrl() . '/dashboard.php?tab=quotes';
            $brand = (string)Config::get('app.company_name', 'CRM');
            $text  = "✅ $brand — il preventivo per $who è pronto. Invialo al cliente dal CRM: $link";
            $html  = '<p>✅ Il preventivo per <b>' . htmlspecialchars($who, ENT_QUOTES) . '</b> è pronto.</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Invialo al cliente dal CRM</a></p>';
            $n = new Notifier();
            if (trim((string)($u['phone'] ?? '')) !== '') {
                $n->whatsapp((string)$u['phone'], $text);
            }
            if (trim((string)($u['email'] ?? '')) !== '') {
                $n->email((string)$u['email'], "Preventivo pronto — $who", $html);
            }
        } catch (Throwable $e) {
            Log::write('crm', 'quote_ready_notify_failed', 'quote_request', $id, ['error' => $e->getMessage()]);
        }
    }

    /** Message every active user of a role. True if at least one channel went out. */
    private static function sendToStaff(string $role, string $text, string $subject, string $html): bool
    {
        $stmt = Db::pdo()->prepare('SELECT phone, email FROM users WHERE role = ? AND active = 1');
        $stmt->execute([$role]);
        $n = new Notifier();
        $any = false;
        foreach ($stmt->fetchAll() as $u) {
            if (trim((string)($u['phone'] ?? '')) !== '') {
                $any = $n->whatsapp((string)$u['phone'], $text) || $any;
            }
            if (trim((string)($u['email'] ?? '')) !== '') {
                $any = $n->email((string)$u['email'], $subject, $html) || $any;
            }
        }
        return $any;
    }

    private static function staffName(?int $userId): string
    {
        if (!$userId) {
            return 'CRM';
        }
        $s = Db::pdo()->prepare('SELECT COALESCE(NULLIF(full_name, ""), username) FROM users WHERE id = ?');
        $s->execute([$userId]);
        return (string)($s->fetchColumn() ?: 'CRM');
    }
}
