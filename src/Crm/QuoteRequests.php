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
    /** Signed by the customer. Terminal: the stock has been drawn. */
    public const ACCEPTED = 'accepted';
    /** The seller looked at the generated quote and sent it back for changes. */
    public const REVISION = 'revision';

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
                'notify_customer' => false,   // the quote request itself tells the office
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

        // Once signed, the document on file IS the agreement — an upload must not
        // quietly point the request at a different one.
        if (!empty($r['stock_applied_at'])) {
            return ['ok' => false, 'error' => 'accepted'];
        }

        $title = trim((string)Config::get('crm.quote_title', '')) ?: 'Preventivo';
        $who   = trim((string)($lead['customer_name'] ?? ''));
        $doc = SignDocs::create([
            'title'      => $who !== '' ? "$title — $who" : $title,
            'contact_id' => (int)$lead['contact_id'],
            'lang'       => $lead['lang'] ?? null,
        ] + self::signerFor($lead), $file, $userId);
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
        // The file on it is the version the seller asked to have changed.
        if ((string)$r['status'] === self::REVISION) {
            return ['ok' => false, 'error' => 'revision'];
        }
        // Re-addressed from the lead on every send, so a resend after the lead's
        // phone or email was corrected goes to the corrected one — and a quote
        // already filed against the company card goes to the person after all.
        $lead = Leads::find((int)$r['lead_id']);
        if ($lead) {
            SignDocs::setSigner((int)$r['document_id'], self::signerFor($lead), $userId);
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

    /**
     * Who a quote is addressed to: the person on the lead — the one who asked,
     * and the one the seller is talking to. Blanks fall back to the linked
     * contact's card inside Sign\Documents. A lead can hang off a company card
     * (matched on its VAT, or on a number already on it), and addressing the
     * quote from the card is how Valentina Green's quote went to the company's
     * registry email and to no phone at all.
     */
    private static function signerFor(array $lead): array
    {
        return [
            'signer_name'  => trim((string)($lead['customer_name'] ?? '')),
            'signer_email' => trim((string)($lead['customer_email'] ?? '')),
            'signer_phone' => trim((string)($lead['customer_phone'] ?? '')),
        ];
    }

    /**
     * The seller looked at the generated quote and wants something changed
     * before it goes out — the client's "view file and request modification",
     * next to "send to the customer". It goes back to the office with what
     * should change; the office edits it in the builder and regenerates, which
     * puts it back to READY and tells the seller again (notifyRequester).
     *
     * Allowed on a quote that exists and has not been signed: READY (not sent
     * yet), SENT (the customer came back with changes) or already REVISION (the
     * seller adds to their request). While a change is pending the old file
     * cannot be sent — sendToCustomer() refuses.
     *
     * The office hears about it by WhatsApp and email — admins only, the same
     * rule every quote notification follows.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function requestRevision(int $id, ?int $userId, string $note): array
    {
        $r = self::find($id);
        if (!$r) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (empty($r['document_id'])
            || !in_array((string)$r['status'], [self::READY, self::SENT, self::REVISION], true)) {
            return ['ok' => false, 'error' => 'not_ready'];
        }
        $note = trim($note);
        if ($note === '') {
            return ['ok' => false, 'error' => 'no_revise_note'];
        }

        Db::pdo()->prepare(
            'UPDATE quote_requests SET status = ?, revision_note = ?, revision_requested_at = NOW(),
                    revision_by = ? WHERE id = ?'
        )->execute([self::REVISION, $note, $userId ?: null, $id]);

        $number = (string)($r['number'] ?? '') ?: ('#' . $id);
        Activities::add('lead', (int)$r['lead_id'], 'system',
            "Modifica richiesta al preventivo $number:\n" . $note, $userId);
        Log::write('crm', 'quote_revision_requested', 'lead', (int)$r['lead_id'],
            ['request_id' => $id, 'by' => $userId]);

        try {
            $lead  = Leads::find((int)$r['lead_id']) ?: [];
            $who   = trim((string)($lead['customer_name'] ?? '')) ?: ('#' . (int)$r['lead_id']);
            $agent = self::staffName($userId);
            $brand = (string)Config::get('app.company_name', 'CRM');
            $link  = Config::appBaseUrl() . '/dashboard.php?tab=quotes&build=' . $id;
            $text  = "✏️ $brand — $agent chiede una modifica al preventivo $number per $who:\n"
                   . $note . "\n\nApri il preventivo: $link";
            $html  = '<p>✏️ <b>' . htmlspecialchars($agent, ENT_QUOTES) . '</b> chiede una modifica al preventivo <b>'
                   . htmlspecialchars($number, ENT_QUOTES) . '</b> per ' . htmlspecialchars($who, ENT_QUOTES) . ':</p>'
                   . '<p>' . nl2br(htmlspecialchars($note, ENT_QUOTES)) . '</p>'
                   . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Apri il preventivo nel CRM</a></p>';
            self::sendToStaff('admin', $text, "Modifica richiesta — preventivo $number", $html);
        } catch (Throwable $e) {
            // The request is recorded either way; a failed message must not lose it.
            Log::write('crm', 'quote_revision_notify_failed', 'lead', (int)$r['lead_id'],
                ['request_id' => $id, 'error' => $e->getMessage()]);
        }
        return ['ok' => true];
    }

    /** Withdraw a request the office is never going to answer. */
    public static function cancel(int $id, ?int $userId = null): bool
    {
        $r = self::find($id);
        if (!$r || in_array((string)$r['status'], [self::SENT, self::ACCEPTED], true)) {
            return false;
        }
        Db::pdo()->prepare('UPDATE quote_requests SET status = ? WHERE id = ?')
            ->execute([self::CANCELLED, $id]);
        Activities::add('lead', (int)$r['lead_id'], 'system', "Quote request #$id cancelled", $userId);
        Log::write('crm', 'quote_cancelled', 'lead', (int)$r['lead_id'], ['request_id' => $id, 'by' => $userId]);
        return true;
    }

    // ---- building the quote ------------------------------------------------------

    /** The priced lines of a request, with the stock each article has now. */
    public static function lines(int $id): array
    {
        $s = Db::pdo()->prepare(
            'SELECT ql.*, a.stock, a.stock_available
               FROM quote_lines ql LEFT JOIN articles a ON a.id = ql.article_id
              WHERE ql.quote_request_id = ? ORDER BY ql.sort, ql.id'
        );
        $s->execute([$id]);
        return $s->fetchAll() ?: [];
    }

    /**
     * Replace the lines and the quote-level fields in one go — the builder posts
     * the whole table, so there is no per-row round trip to fall out of step.
     *
     * An article line keeps the catalogue's CODE (it is the link to the shelf)
     * but the office may reword the description and set its own price: the
     * catalogue is where the price starts, not where it has to end.
     *
     * @param array $rows lines[i][kind|article_id|code|description|qty|price|discount|vat]
     * @param array $head discount_pct | valid_until | customer_notes
     * @return array{ok:bool, lines?:int, error?:string}
     */
    public static function saveLines(int $id, array $rows, array $head, ?int $userId = null): array
    {
        $r = self::find($id);
        if (!$r) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!empty($r['stock_applied_at'])) {
            return ['ok' => false, 'error' => 'accepted'];
        }
        if ((string)$r['status'] === self::CANCELLED) {
            return ['ok' => false, 'error' => 'cancelled'];
        }

        $clean = [];
        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $kind = ($row['kind'] ?? '') === 'service' ? 'service' : 'article';
            $aid  = (int)($row['article_id'] ?? 0);
            $art  = ($kind === 'article' && $aid > 0) ? Articles::find($aid) : null;
            if ($kind === 'article' && !$art) {
                $kind = 'service';   // an article that no longer exists prints as a plain line
            }
            $desc = trim((string)($row['description'] ?? ''));
            if ($desc === '' && $art) {
                $desc = (string)($art['description'] ?: $art['code']);
            }
            $qty = self::num((string)($row['qty'] ?? ''));
            if ($desc === '' || $qty <= 0) {
                continue;
            }
            $clean[] = [
                'kind'         => $kind,
                'article_id'   => $art ? $aid : null,
                'code'         => $art ? (string)$art['code'] : (trim((string)($row['code'] ?? '')) ?: null),
                'description'  => mb_substr($desc, 0, 255),
                'qty'          => $qty,
                'unit_price'   => max(0.0, self::num((string)($row['price'] ?? '0'))),
                'discount_pct' => min(100.0, max(0.0, self::num((string)($row['discount'] ?? '0')))),
                'vat_rate'     => min(100.0, max(0.0, self::num((string)($row['vat'] ?? '22')))),
            ];
        }

        $vu = trim((string)($head['valid_until'] ?? ''));
        $vu = ($vu !== '' && strtotime($vu)) ? date('Y-m-d', (int)strtotime($vu)) : null;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM quote_lines WHERE quote_request_id = ?')->execute([$id]);
            $ins = $pdo->prepare(
                'INSERT INTO quote_lines (quote_request_id, sort, kind, article_id, code, description,
                                          qty, unit_price, discount_pct, vat_rate)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($clean as $i => $l) {
                $ins->execute([$id, $i, $l['kind'], $l['article_id'], $l['code'], $l['description'],
                               $l['qty'], $l['unit_price'], $l['discount_pct'], $l['vat_rate']]);
            }
            $pdo->prepare('UPDATE quote_requests SET discount_pct = ?, valid_until = ?, customer_notes = ? WHERE id = ?')
                ->execute([
                    min(100.0, max(0.0, self::num((string)($head['discount_pct'] ?? '0')))),
                    $vu,
                    trim((string)($head['customer_notes'] ?? '')) ?: null,
                    $id,
                ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Log::write('crm', 'quote_lines_saved', 'lead', (int)$r['lead_id'],
            ['request_id' => $id, 'lines' => count($clean), 'by' => $userId]);
        return ['ok' => true, 'lines' => count($clean)];
    }

    /**
     * Price the lines. Each line: qty x price, less its own discount; then the
     * quote-level discount on everything; VAT computed per rate on what is left,
     * which is how an Italian invoice computes it. Every line is rounded to the
     * cent before it is summed, so the printed lines add up to the printed total.
     */
    public static function totals(array $lines, float $docDiscount = 0.0): array
    {
        $dd  = min(100.0, max(0.0, $docDiscount));
        $out = ['lines' => [], 'gross' => 0.0, 'after_lines' => 0.0, 'net' => 0.0, 'vat' => [],
                'vat_total' => 0.0, 'total' => 0.0, 'doc_discount_pct' => $dd];
        foreach ($lines as $l) {
            $gross = (float)$l['qty'] * (float)$l['unit_price'];
            $after = round($gross * (1 - (float)$l['discount_pct'] / 100), 2);
            $net   = round($after * (1 - $dd / 100), 2);
            $l['gross']      = round($gross, 2);
            $l['line_total'] = $after;
            $l['net']        = $net;
            $out['lines'][]      = $l;
            $out['gross']       += $gross;
            $out['after_lines'] += $after;
            $out['net']         += $net;
            $rate = number_format((float)$l['vat_rate'], 2, '.', '');
            $out['vat'][$rate] = ['base' => ($out['vat'][$rate]['base'] ?? 0.0) + $net, 'tax' => 0.0];
        }
        ksort($out['vat']);
        foreach ($out['vat'] as $rate => $v) {
            $out['vat'][$rate]['tax'] = round($v['base'] * (float)$rate / 100, 2);
            $out['vat_total'] += $out['vat'][$rate]['tax'];
        }
        $out['gross']       = round($out['gross'], 2);
        $out['after_lines'] = round($out['after_lines'], 2);
        $out['net']         = round($out['net'], 2);
        $out['vat_total']   = round($out['vat_total'], 2);
        $out['total']       = round($out['net'] + $out['vat_total'], 2);
        return $out;
    }

    /**
     * Render the lines to a PDF and attach it — the document the seller then
     * sends through the existing signing flow.
     *
     * Regenerating replaces the file, and the version it replaces is VOIDED if
     * the customer has not signed it: two signable versions of one quote is how
     * a customer ends up accepting the wrong price. A version they HAVE signed
     * cannot be replaced at all.
     *
     * @return array{ok:bool, document_id?:int, number?:string, error?:string}
     */
    public static function generateDocument(int $id, ?int $userId = null): array
    {
        $r = self::find($id);
        if (!$r) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!empty($r['stock_applied_at'])) {
            return ['ok' => false, 'error' => 'accepted'];
        }
        if ((string)$r['status'] === self::CANCELLED) {
            return ['ok' => false, 'error' => 'cancelled'];
        }
        $lines = self::lines($id);
        if (!$lines) {
            return ['ok' => false, 'error' => 'no_lines'];
        }
        $lead = Leads::find((int)$r['lead_id']);
        if (!$lead || (int)($lead['contact_id'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'no_contact'];
        }
        $contact = Contacts::find((int)$lead['contact_id']) ?: [];

        $old = !empty($r['document_id']) ? SignDocs::find((int)$r['document_id']) : null;
        if ($old && (string)$old['status'] === 'signed') {
            return ['ok' => false, 'error' => 'accepted'];
        }

        $number = (string)($r['number'] ?? '') ?: sprintf('P%s-%04d', date('Y'), $id);
        $valid  = (string)($r['valid_until'] ?? '') ?: date('Y-m-d', strtotime('+30 days'));
        $r['number']      = $number;
        $r['valid_until'] = $valid;

        $totals = self::totals($lines, (float)($r['discount_pct'] ?? 0));
        $bytes  = QuotePdf::build($r, $lead, $contact, $totals);

        $who = trim((string)($lead['customer_name'] ?? '')) ?: (string)($contact['name'] ?? '');
        $doc = SignDocs::createFromBytes([
            'title'      => "Preventivo $number" . ($who !== '' ? " — $who" : ''),
            'contact_id' => (int)$lead['contact_id'],
            'lang'       => $lead['lang'] ?? null,
        ] + self::signerFor($lead), $bytes, "Preventivo-$number.pdf", $userId);
        if (empty($doc['ok'])) {
            return ['ok' => false, 'error' => (string)($doc['error'] ?? 'save_failed')];
        }

        if ($old && in_array((string)$old['status'], ['draft', 'sent', 'viewed'], true)) {
            SignDocs::void((int)$old['id'], $userId, 'Sostituito da una nuova versione del preventivo ' . $number);
        }

        Db::pdo()->prepare(
            'UPDATE quote_requests SET document_id = ?, status = ?, ready_at = NOW(), generated_at = NOW(),
                    number = ?, valid_until = ? WHERE id = ?'
        )->execute([(int)$doc['id'], self::READY, $number, $valid, $id]);

        Activities::add('lead', (int)$r['lead_id'], 'system',
            "Preventivo $number composto nel CRM: " . count($lines) . ' righe, totale EUR '
            . number_format($totals['total'], 2, ',', '.'), $userId);
        Log::write('crm', 'quote_generated', 'lead', (int)$r['lead_id'],
            ['request_id' => $id, 'number' => $number, 'document_id' => (int)$doc['id'],
             'total' => $totals['total'], 'replaced' => $old ? (int)$old['id'] : null, 'by' => $userId]);

        self::notifyRequester($id, $lead);
        return ['ok' => true, 'document_id' => (int)$doc['id'], 'number' => $number];
    }

    /**
     * The customer signed. If what they signed is a quote, it is now ACCEPTED,
     * and its article lines are drawn from stock — the client's "crucially".
     *
     * Once only, and claimed before anything moves: the UPDATE ... WHERE
     * stock_applied_at IS NULL either takes the quote or finds it taken, so a
     * replayed signature, a retry or two requests racing cannot draw the stock
     * twice. Each line then moves on its own; one that fails (the article was
     * deleted meanwhile) is logged and does not stop the others, because the
     * claim has been made and a half-applied quote is recoverable while a
     * double-applied one silently is not.
     *
     * Called from Sign\Documents after the seal, inside a try — a stock problem
     * must never undo a signature that has already been sealed.
     */
    public static function onDocumentSigned(int $documentId): void
    {
        $pdo = Db::pdo();
        $s   = $pdo->prepare('SELECT * FROM quote_requests WHERE document_id = ? LIMIT 1');
        $s->execute([$documentId]);
        $r = $s->fetch();
        if (!$r) {
            return;   // a signed document that is not a quote — nothing to do here
        }

        $claim = $pdo->prepare(
            'UPDATE quote_requests SET stock_applied_at = NOW(), accepted_at = COALESCE(accepted_at, NOW()),
                    status = ? WHERE id = ? AND stock_applied_at IS NULL'
        );
        $claim->execute([self::ACCEPTED, (int)$r['id']]);
        if ($claim->rowCount() === 0) {
            return;   // already applied
        }

        $lead   = Leads::find((int)$r['lead_id']) ?: [];
        $who    = trim((string)($lead['customer_name'] ?? '')) ?: ('#' . (int)$r['lead_id']);
        $number = (string)($r['number'] ?? '') ?: ('#' . (int)$r['id']);

        $drawn = [];
        $fail  = [];
        foreach (self::lines((int)$r['id']) as $l) {
            if ($l['kind'] !== 'article' || empty($l['article_id'])) {
                continue;   // a service is not on a shelf
            }
            try {
                $mv = Articles::moveStock((int)$l['article_id'], 'unload', (string)$l['qty'],
                    "Preventivo $number accettato — $who", null, 'quote', false);
                if (!empty($mv['ok'])) {
                    $drawn[] = (int)$l['article_id'];
                } else {
                    $fail[] = ['article_id' => (int)$l['article_id'], 'error' => $mv['error'] ?? '?'];
                }
            } catch (Throwable $e) {
                $fail[] = ['article_id' => (int)$l['article_id'], 'error' => $e->getMessage()];
            }
        }
        // One restock check for the whole quote, not one digest per line.
        if ($drawn) {
            Articles::checkLowStock($drawn);
        }

        Activities::add('lead', (int)$r['lead_id'], 'system',
            "Preventivo $number firmato dal cliente — accettato. "
            . ($drawn ? count($drawn) . ' articoli scaricati dal magazzino.' : 'Nessun articolo di magazzino.')
            . ($fail ? ' ATTENZIONE: ' . count($fail) . ' righe non scaricate, vedi registro eventi.' : ''));
        Log::write('crm', $fail ? 'quote_accepted_partial' : 'quote_accepted', 'lead', (int)$r['lead_id'],
            ['request_id' => (int)$r['id'], 'number' => $number, 'drawn' => $drawn, 'failed' => $fail]);
    }

    /** "12,50" / "12.50" / "1.234,56" -> float. The office types Italian. */
    private static function num(string $raw): float
    {
        $v = trim($raw);
        if ($v === '') {
            return 0.0;
        }
        $v = (string)preg_replace('/[^0-9,.\-]/', '', $v);
        $lastComma = strrpos($v, ',');
        $lastDot   = strrpos($v, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $v = str_replace(',', '.', str_replace('.', '', $v));
        } else {
            $v = str_replace(',', '', $v);
        }
        return (float)$v;
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
                    l.contact_id, ct.name AS contact_name,
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
            "SELECT COUNT(*) FROM quote_requests WHERE status IN ('open','revision')"
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
