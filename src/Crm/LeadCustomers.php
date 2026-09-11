<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use PDO;
use Throwable;

/**
 * Leads that are really EXISTING customers.
 *
 *   "Leads are arriving who are actually already customers, and this creates
 *    confusion. The VAT numbers of the leads should be compared with those of
 *    the customers and the information should be updated."
 *   "This happens because he sells different products: the contact was already
 *    a customer because he had bought a product in the past, and now he is
 *    asking for a new product."
 *
 * So a lead from a customer is a real lead — a new sale — not a duplicate to
 * refuse. What was wrong is that it did not KNOW it was a customer: it sat on a
 * contact of its own, the customer's card in the registry never showed it, and
 * the seller treated a returning client as a stranger. The usual way it
 * happened: the lead came in without a VAT, was converted, and a week later the
 * gestionale export brought the new customer in — as a NEW card, because the
 * lead's contact had no VAT for the import to adopt it by.
 *
 * Two ways a lead finds its customer:
 *
 *  - By VAT, automatically. Same partita IVA as a registry card → the lead is
 *    moved onto that card and the lead-only contact it sat on is merged into it.
 *    At creation Contacts::findOrCreate already looks at the VAT first; this
 *    covers the rest — a VAT typed in later (Leads::update), a customer who
 *    entered the registry after their lead (after every CustomerImport), and the
 *    leads already in the CRM (a one-off reconcile()).
 *
 *  - By phone or email, as a SUGGESTION the office confirms. 85% of leads carry
 *    no VAT at all. A number or an address shared with a card is strong
 *    evidence but not proof — an employee's mobile on a company card, a family
 *    number — and welding two businesses together is worse than the duplicate.
 *    So the lead says "forse già cliente", names the card, and the office links
 *    it with one click (link()).
 *
 * Only a lead-born contact (is_customer = 0, no gestionale code) is ever merged
 * away. Two REGISTRY cards are never merged here: they can be two customer codes
 * of one business on purpose, and that stays a human decision.
 */
final class LeadCustomers
{
    /** @var string[]|null base tables with a contact_id column, read once */
    private static ?array $contactTables = null;

    // ---- finding ------------------------------------------------------------------

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
            if (!is_array($l) || !empty($l['ct_is_customer']) || ($l['status'] ?? '') === 'junk') {
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

    // ---- linking ------------------------------------------------------------------

    /**
     * Put a lead on its customer's card. The lead-only contact it sat on is
     * merged into the card (merge()) — which carries along any other lead on that
     * contact, its documents, tickets and the rest — and the card takes the
     * phone, email and portal login it lacked: "the information should be
     * updated". The lead itself keeps what it says: who asked, and for what.
     *
     * @param string $how 'vat' (automatic) | 'manual' (the office confirmed a suggestion)
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
        $why   = $how === 'vat' ? 'stessa partita IVA ' . (string)$card['vat_number']
               : ($how === 'manual' ? 'collegato dalla sede' : $how);
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
     * Discarded leads are left alone. $leadId limits it to one lead (after an
     * edit); null runs every lead (after an import, and once as the backfill).
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
              WHERE l.status <> 'junk'" . ($leadId ? ' AND l.id = ' . (int)$leadId : '') . ' ORDER BY l.id'
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
