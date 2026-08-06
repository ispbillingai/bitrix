<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Reminder\Scheduler;
use Glue\Reminder\Templates;
use Glue\Sync\BitrixSync;
use Throwable;

/**
 * Leads — incoming requests from the public form, the website webhook, the
 * trade-show app or a partner email. Owns the lifecycle the brief describes:
 * create -> (welcome + inactivity timer) -> assign to a seller (-> send the
 * seller's profile) -> move through the pipeline -> convert to a deal.
 *
 * Replaces the old Lead\Intake, but writes to our own `leads` table instead of
 * calling the Bitrix REST API.
 */
final class Leads
{
    /**
     * Create a lead at the pipeline's first stage and schedule its automations.
     * @param array $d name|phone|email|source|source_url|external_id|title|comments|company|lang
     * @return int new lead id
     */
    public static function create(array $d, ?int $actorId = null): int
    {
        $name   = trim((string)($d['name'] ?? ''));
        // Stored international from the start (+39...), whatever the agent or
        // integration typed — not just fixed up later at WhatsApp send time.
        $phone  = Notifier::normalizePhone((string)($d['phone'] ?? ''));
        $email  = trim((string)($d['email'] ?? ''));
        // Lowercase so "Cashmatic" and "cashmatic" count as one source in reports.
        $source = mb_strtolower(trim((string)($d['source'] ?? 'website'))) ?: 'website';
        $zone   = trim((string)($d['zone'] ?? ''));
        $lang   = Templates::lang($d['lang'] ?? null);
        $title  = trim((string)($d['title'] ?? '')) ?: ($name !== '' ? "Request: $name" : 'New request');
        // Normalised up here, not just before the INSERT: it is one of the
        // identifiers the duplicate check below matches on.
        $vat    = VatLock::normalize((string)($d['vat_number'] ?? ''));

        // Set only by the API intake (webhooks/lead.php): the sender's own id for
        // this request, and the site it was submitted on.
        $externalId = trim((string)($d['external_id'] ?? ''));
        $sourceUrl  = trim((string)($d['source_url'] ?? ''));

        $pdo = Db::pdo();

        // A named lock serialises concurrent creates for the same person, so two
        // requests racing in together (Save clicked twice, a page refresh, a
        // webhook retry) can't both pass the duplicate check below and insert a
        // twin. Keyed on the person alone — not on who is entering them — so the
        // website form and a seller typing the same request in queue behind the
        // same lock and the second one sees the first one's row.
        $lockName = 'glue_lead:' . substr(md5(
            $vat !== '' ? $vat : ($phone !== '' ? $phone : ($email !== '' ? $email : $name))
        ), 0, 40);
        $pdo->prepare('SELECT GET_LOCK(?, 5)')->execute([$lockName]);

        try {
            $dupId = self::duplicateId([
                'name' => $name, 'phone' => $phone, 'email' => $email,
                'vat_number' => $vat, 'source' => $source, 'external_id' => $externalId,
            ]);
            if ($dupId !== null) {
                self::groupRequest($dupId, [
                    'source' => $source, 'name' => $name, 'phone' => $phone,
                    'email' => $email, 'vat' => $vat,
                    'comments' => (string)($d['comments'] ?? ''),
                ], $actorId);
                return $dupId; // finally still releases the lock
            }

            $contactId = Contacts::findOrCreate([
                'name' => $name ?: 'Unknown', 'phone' => $phone, 'email' => $email,
                'company' => $d['company'] ?? null, 'lang' => $lang, 'source' => $source,
            ]);

            $pipelineId = Pipelines::defaultId('lead');
            $firstStage = Pipelines::firstStageCode('lead');

            $vat = VatLock::normalize((string)($d['vat_number'] ?? ''));

            $fairName = trim((string)($d['fair_name'] ?? ''));
            $fairCity = trim((string)($d['fair_city'] ?? ''));

            $stmt = $pdo->prepare(
                'INSERT INTO leads
                    (contact_id, title, source, external_id, source_url, zone, fair_name, fair_city,
                     pipeline_id, stage_code, status, created_by,
                     customer_name, customer_phone, customer_email, vat_number, comments, lang,
                     received_at, stage_changed_at)
                 VALUES (:contact_id, :title, :source, :external_id, :source_url, :zone, :fair_name, :fair_city,
                     :pipeline_id, :stage, "open", :created_by,
                     :name, :phone, :email, :vat, :comments, :lang, NOW(), NOW())'
            );
            $stmt->execute([
                ':contact_id' => $contactId, ':title' => $title, ':source' => $source,
                ':external_id' => $externalId ?: null, ':source_url' => $sourceUrl ?: null,
                ':zone' => $zone ?: null,
                ':fair_name' => $fairName ?: null, ':fair_city' => $fairCity ?: null,
                ':pipeline_id' => $pipelineId, ':stage' => $firstStage,
                // Who typed it in. Null for the public form / fair form / partner API —
                // those call create() with no actor, and null is what marks a lead as
                // genuinely inbound rather than hand-entered.
                ':created_by' => $actorId ?: null,
                ':name' => $name ?: null, ':phone' => $phone ?: null, ':email' => $email ?: null,
                ':vat' => $vat ?: null,
                ':comments' => $d['comments'] ?? null, ':lang' => $lang,
            ]);
            $leadId = (int)$pdo->lastInsertId();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }

        Automation::welcome('lead', $leadId, $lang);
        Automation::inactivity('lead', $leadId, $firstStage);

        Activities::add('lead', $leadId, 'system',
            'Lead created from ' . $source . ($sourceUrl !== '' ? " ($sourceUrl)" : ''), $actorId);
        Log::write('crm', 'lead_created', 'lead', $leadId,
            ['source' => $source, 'source_url' => $sourceUrl, 'external_id' => $externalId,
             'name' => $name, 'phone' => $phone, 'email' => $email]);

        self::pushSync($leadId);
        return $leadId;
    }

    /**
     * The existing lead a new entry would merely duplicate, or null.
     *
     * ONE definition of "duplicate", shared by every door into the CRM: the
     * dashboard form, the public request page, the fair form, the partner API,
     * the website webhook and the mailbox importer all reach it through
     * create(). Accepts raw field values and normalises them itself, so callers
     * can ask before they tidy anything up.
     *
     * An entry matches an existing lead when it shares ANY strong identifier:
     *   - external_id — the sender's own reference, so an API push stays
     *     idempotent however many times it is retried, at any age;
     *   - vat_number  — a partita IVA is one business, and the surest signal of
     *     all that we are looking at a company already in the pipeline;
     *   - phone, or email — either one on its own. Requiring both, or checking
     *     email only when no phone was given, let one mistyped digit file a
     *     second lead for someone whose email matched exactly.
     * Name alone is consulted only when nothing else was supplied — far too
     * many people share one for it to be evidence by itself.
     *
     * A match counts while the existing lead is OPEN or CONVERTED, or was filed
     * within the last two minutes. An open lead IS the customer's live request,
     * so anything arriving for them belongs on it rather than on a second record
     * that messages them in parallel. A converted lead means they are already a
     * customer: a new request from them is accepted but grouped onto their lead
     * (groupRequest) instead of re-entering the pipeline as if they were unknown.
     * Only a DISCARDED lead lets the next request open a fresh record — junking
     * one enquiry must not gag the customer forever. The two-minute floor still
     * absorbs a double-submit whose first lead was closed immediately.
     */
    public static function duplicateId(array $d, ?int $excludeId = null): ?int
    {
        $pdo = Db::pdo();

        // $excludeId: "would these values duplicate a lead OTHER than this one?" —
        // asked when EDITING lead $excludeId, whose own row must not count as its
        // duplicate. Absent on create, where every existing row is a candidate.
        $exclude     = $excludeId !== null && $excludeId > 0;
        $excludeSql  = $exclude ? ' AND id <> ?' : '';

        $source     = mb_strtolower(trim((string)($d['source'] ?? ''))) ?: 'website';
        $externalId = trim((string)($d['external_id'] ?? ''));
        if ($externalId !== '') {
            $args = [$externalId, $source];
            if ($exclude) { $args[] = $excludeId; }
            $stmt = $pdo->prepare("SELECT id FROM leads WHERE external_id = ? AND source = ?$excludeSql ORDER BY id DESC LIMIT 1");
            $stmt->execute($args);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
            // A fresh external_id is not proof of a fresh customer. Retry-safe
            // senders mint one id per MESSAGE — the mailbox importer stamps
            // every mail, partners number every request — so the same person
            // writing in twice arrives under two different ids. Returning null
            // here was how a second email from a customer with an open lead
            // opened a second lead: fall through to the identity match instead.
        }

        $vat   = VatLock::normalize((string)($d['vat_number'] ?? ''));
        $phone = Notifier::normalizePhone((string)($d['phone'] ?? ''));
        $email = trim((string)($d['email'] ?? ''));
        $name  = trim((string)($d['name'] ?? ''));

        $ors = $args = [];
        if ($vat !== '') {
            $ors[]  = 'vat_number = ?';
            $args[] = $vat;
        }
        if ($phone !== '') {
            $ors[]  = 'customer_phone = ?';
            $args[] = $phone;
        }
        if ($email !== '') {
            $ors[]  = 'customer_email = ?';
            $args[] = $email;
        }

        // Nothing but a name to go on: two walk-ins at a fair can genuinely both
        // be "Mario Rossi", so a name is never treated as identity. It only ever
        // catches the same entry arriving twice, inside the double-submit window.
        $age = $ors ? "status IN ('open','converted') OR created_at > (NOW() - INTERVAL 120 SECOND)" : '';
        if (!$ors && $name !== '') {
            $ors[]  = 'customer_name = ?';
            $args[] = $name;
            $age    = 'created_at > (NOW() - INTERVAL 120 SECOND)';
        }
        if (!$ors) {
            return null; // nothing distinctive to match on
        }

        if ($exclude) { $args[] = $excludeId; }
        $stmt = $pdo->prepare(
            'SELECT id FROM leads
              WHERE (' . implode(' OR ', $ors) . ")
                AND ($age)$excludeSql
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute($args);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Record a request that arrived for a customer who already has a lead:
     * accepted, but grouped onto that lead's timeline instead of opening a twin.
     * The request text rides along in the note, so what the customer wrote the
     * second time is preserved — suppressing the twin must not discard the ask.
     * Deliberately no welcome/automation: they are not a new lead.
     */
    public static function groupRequest(int $leadId, array $d, ?int $actorId = null): void
    {
        $source = mb_strtolower(trim((string)($d['source'] ?? ''))) ?: 'website';
        $note   = 'New request from ' . $source . ' grouped onto this lead';
        $text   = trim((string)($d['comments'] ?? ''));
        if ($text !== '') {
            $note .= ":\n" . $text;
        }
        Activities::add('lead', $leadId, 'system', $note, $actorId);
        Log::write('crm', 'lead_duplicate_suppressed', 'lead', $leadId,
            ['source' => $source, 'name' => (string)($d['name'] ?? ''),
             'phone' => (string)($d['phone'] ?? ''), 'email' => (string)($d['email'] ?? ''),
             'vat' => (string)($d['vat'] ?? '')]);
    }

    /** Assign the lead to a seller and message the customer the seller's profile (#3). */
    public static function assign(int $leadId, int $agentId, ?int $actorId = null): void
    {
        $agent = self::agent($agentId);
        if (!$agent) {
            return;
        }
        // Idempotent: if the lead is already on this agent, do nothing. A
        // double-submitted create auto-assigns each time, and without this it
        // would re-send the agent-profile message to the customer on every click.
        $cur = Db::pdo()->prepare('SELECT assigned_to FROM leads WHERE id = ?');
        $cur->execute([$leadId]);
        if ((int)($cur->fetchColumn() ?: 0) === $agentId) {
            return;
        }
        Db::pdo()->prepare('UPDATE leads SET assigned_to = ? WHERE id = ?')->execute([$agentId, $leadId]);

        Automation::agentAssigned('lead', $leadId, $agent);
        $label = trim((string)($agent['full_name'] ?? '')) ?: $agent['username'];
        Activities::add('lead', $leadId, 'system', "Assigned to $label", $actorId);
        Log::write('crm', 'lead_assigned', 'lead', $leadId, ['agent_id' => $agentId]);
        self::pushSync($leadId);
    }

    /** Move the lead to a new stage; silences both nudge cadences once it leaves NEW. */
    public static function moveStage(int $leadId, string $stageCode, ?int $actorId = null): void
    {
        $lead = self::find($leadId);
        if (!$lead) {
            return;
        }
        $oldStage   = (string)$lead['stage_code'];
        $firstStage = Pipelines::firstStageCode('lead');
        $stage      = Pipelines::stage((int)$lead['pipeline_id'], $stageCode);
        $status     = 'open';
        if ($stage && (int)$stage['is_won'] === 1) {
            $status = 'converted';
        } elseif ($stage && (int)$stage['is_lost'] === 1) {
            $status = 'junk';
        }

        Db::pdo()->prepare(
            'UPDATE leads SET stage_code = ?, status = ?, stage_changed_at = NOW() WHERE id = ?'
        )->execute([$stageCode, $status, $leadId]);

        // Discarded here, before it ever became a deal: that IS the end of the road,
        // so the referring partner is told now. A lead that converts instead stays
        // quiet — Deals::moveStage announces the won/lost outcome once the deal has
        // one. Either way the partner hears from us exactly once per outcome.
        if ($status === 'junk' && (string)$lead['status'] !== 'junk') {
            \Glue\Partner\Partners::notifyOutcome('lead', $leadId, 'lost');
        }

        // Landed on the converted stage, so this lead IS business now and must have
        // a deal to go on living in. Dragging a card into the Converted column used
        // to set the status and stop there: the lead left the leads board (which
        // shows open/discarded) and never arrived on the deals board, because only
        // the Convert button ever created the deal. Five leads had vanished that way
        // before this was found. Both doors now go through ensureDeal, which is
        // idempotent, so neither can produce a second deal.
        if ($status === 'converted' && (string)$lead['status'] !== 'converted') {
            self::ensureDeal($lead, $actorId);
        }

        if ($oldStage === $firstStage && $stageCode !== $firstStage) {
            // Both cadences Automation::inactivity started, not just the agent's:
            // cancelling 'lead_inactivity' alone left the customer-facing
            // 'lead_uncontacted_customer' row sitting pending in the queue. It was
            // skipped at dispatch by the stage guard, so nothing went out, but a
            // worked lead has no business still holding a slot in the queue.
            (new Scheduler())->cancelForEntity(
                'lead', $leadId, ['lead_inactivity', 'lead_uncontacted_customer']
            );
        }

        Activities::add('lead', $leadId, 'stage',
            'Stage: ' . Pipelines::label('lead', $oldStage) . ' → ' . Pipelines::label('lead', $stageCode), $actorId);
        Log::write('crm', 'lead_stage_changed', 'lead', $leadId, ['from' => $oldStage, 'to' => $stageCode]);
        self::pushSync($leadId);
    }

    /** Convert a lead into a deal; marks the lead converted. Returns the deal id. */
    public static function convert(int $leadId, ?int $actorId = null): int
    {
        $lead = self::find($leadId);
        // Only an open lead converts — a second tap of the Convert button (seen
        // in the wild: two deals 7s apart) must not create a duplicate deal.
        if (!$lead || $lead['status'] !== 'open') {
            return 0;
        }
        $dealId = self::ensureDeal($lead, $actorId);
        $won = Pipelines::wonStageCode('lead') ?? 'CONVERTED';
        self::moveStage($leadId, $won, $actorId);   // finds the deal already there
        return $dealId;
    }

    /**
     * The deal this lead became, created if it hasn't got one yet. Returns its id.
     *
     * The single place a lead turns into a deal, reached from both doors: the
     * Convert button (convert(), which calls this first and then moves the stage)
     * and dragging the card onto the converted stage (moveStage, which calls this
     * after). Whichever runs first, the other finds the deal already there.
     *
     * The named lock is the same guard create() uses: two requests racing in — a
     * double-tapped button, a card dropped twice — could otherwise both read "no
     * deal" and both insert one.
     */
    private static function ensureDeal(array $lead, ?int $actorId = null): int
    {
        $leadId = (int)$lead['id'];
        $pdo    = Db::pdo();
        $lock   = 'glue_lead_deal:' . $leadId;
        $pdo->prepare('SELECT GET_LOCK(?, 5)')->execute([$lock]);
        try {
            $q = $pdo->prepare('SELECT id FROM deals WHERE lead_id = ? ORDER BY id LIMIT 1');
            $q->execute([$leadId]);
            $existing = (int)($q->fetchColumn() ?: 0);
            if ($existing > 0) {
                return $existing;
            }
            $dealId = Deals::create([
                'title'       => $lead['title'] ?: ('Deal: ' . ($lead['customer_name'] ?? '')),
                'contact_id'  => $lead['contact_id'],
                'lead_id'     => $leadId,
                'name'        => $lead['customer_name'],
                'phone'       => $lead['customer_phone'],
                'email'       => $lead['customer_email'],
                'lang'        => $lead['lang'],
                'assigned_to' => $lead['assigned_to'],
            ], $actorId);
            Activities::add('lead', $leadId, 'system', "Converted to deal #$dealId", $actorId);
            return $dealId;
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    // ---- reads ----------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM leads WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<int,array> recent leads with agent + creator + partner labels.
     * $assignedTo scopes to one seller, $source to one origin, $zone to one area,
     * $partnerId to the leads one partner brought in (entered in their area or
     * through their referral link — both set referred_by_partner_id).
     */
    public static function all(int $limit = 300, ?int $assignedTo = null, ?string $source = null, ?string $zone = null, ?int $partnerId = null): array
    {
        $limit = max(1, min(1000, $limit));
        $conds = [];
        if ($assignedTo) {
            $conds[] = 'l.assigned_to = ' . (int)$assignedTo;
        }
        if ($source !== null && $source !== '') {
            $conds[] = 'l.source = ' . Db::pdo()->quote($source);
        }
        if ($zone !== null && $zone !== '') {
            $conds[] = 'l.zone = ' . Db::pdo()->quote($zone);
        }
        if ($partnerId) {
            $conds[] = 'l.referred_by_partner_id = ' . (int)$partnerId;
        }
        $where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';
        return Db::pdo()->query(
            "SELECT l.*, u.username AS agent_username, u.full_name AS agent_name,
                    c.username AS creator_username, c.full_name AS creator_name,
                    pt.name AS partner_name
             FROM leads l
             LEFT JOIN users u ON u.id = l.assigned_to
             LEFT JOIN users c ON c.id = l.created_by
             LEFT JOIN partners pt ON pt.id = l.referred_by_partner_id
             $where ORDER BY l.id DESC LIMIT $limit"
        )->fetchAll();
    }

    /**
     * Leads grouped by stage_code for the kanban board. $assignedTo scopes to one
     * seller, $partnerId to one partner's leads.
     *
     * Shows OPEN leads, DISCARDED ones (status 'junk', so the Discarded column
     * actually populates), and leads CONVERTED in the last 60 days — the same
     * courtesy Deals::byStage extends to won/lost deals, and for the same reason:
     * a card dragged into the Converted column simply evaporated, which reads as
     * "I lost the lead", not as "it moved on". It now lands in that column and
     * stays put long enough to be seen. Older converted leads drop off; they live
     * on as deals, which is where the work continues.
     */
    public static function byStage(?int $assignedTo = null, ?int $partnerId = null): array
    {
        $where = "WHERE (l.status IN ('open', 'junk')
                     OR (l.status = 'converted' AND l.updated_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)))"
            . ($assignedTo ? ' AND l.assigned_to = ' . (int)$assignedTo : '')
            . ($partnerId ? ' AND l.referred_by_partner_id = ' . (int)$partnerId : '');
        $rows = Db::pdo()->query(
            "SELECT l.*, u.username AS agent_username, u.full_name AS agent_name,
                    c.username AS creator_username, c.full_name AS creator_name,
                    pt.name AS partner_name
             FROM leads l
             LEFT JOIN users u ON u.id = l.assigned_to
             LEFT JOIN users c ON c.id = l.created_by
             LEFT JOIN partners pt ON pt.id = l.referred_by_partner_id
             $where ORDER BY l.id DESC"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['stage_code']][] = $r;
        }
        return $out;
    }

    /** @return string[] known sources (seed suggestions + everything already in the table) for the form's datalist */
    public static function sources(): array
    {
        $db = Db::pdo()->query(
            "SELECT DISTINCT source FROM leads WHERE source IS NOT NULL AND source <> '' ORDER BY source"
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_unique(array_merge(['manual', 'website', 'cashmatic'], $db)));
    }

    /** @return string[] zones already used on leads (for the form datalist + the filter dropdown). */
    public static function zones(): array
    {
        return Db::pdo()->query(
            "SELECT DISTINCT zone FROM leads WHERE zone IS NOT NULL AND zone <> '' ORDER BY zone"
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return string[] fair names already used (datalist on the trade-fair form). */
    public static function fairs(): array
    {
        return Db::pdo()->query(
            "SELECT DISTINCT fair_name FROM leads WHERE fair_name IS NOT NULL AND fair_name <> '' ORDER BY fair_name"
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return string[] fair cities already used (datalist on the trade-fair form). */
    public static function fairCities(): array
    {
        return Db::pdo()->query(
            "SELECT DISTINCT fair_city FROM leads WHERE fair_city IS NOT NULL AND fair_city <> '' ORDER BY fair_city"
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Edit a lead's own fields (name/other data — #15). Only keys present in $d are
     * touched; the linked contact's name/phone/email/company are kept in step so the
     * portal + timeline stay consistent. Returns true if the lead exists.
     * @param array $d name|phone|email|company|vat_number|source|zone|comments|lang
     */
    public static function update(int $leadId, array $d, ?int $actorId = null): bool
    {
        $lead = self::find($leadId);
        if (!$lead) {
            return false;
        }
        // input key => [lead column, normalizer]. 'company' has no lead column — it
        // is synced to the contact only (below), like on create.
        $map = [
            'name'       => ['customer_name',  fn($v) => trim((string)$v) ?: null],
            'phone'      => ['customer_phone', fn($v) => Notifier::normalizePhone((string)$v) ?: null],
            'email'      => ['customer_email', fn($v) => trim((string)$v) ?: null],
            'vat_number' => ['vat_number',     fn($v) => VatLock::normalize((string)$v) ?: null],
            'source'     => ['source',         fn($v) => mb_strtolower(trim((string)$v)) ?: null],
            'zone'       => ['zone',           fn($v) => trim((string)$v) ?: null],
            'fair_name'  => ['fair_name',      fn($v) => trim((string)$v) ?: null],
            'fair_city'  => ['fair_city',      fn($v) => trim((string)$v) ?: null],
            'comments'   => ['comments',       fn($v) => (string)$v !== '' ? (string)$v : null],
            'lang'       => ['lang',           fn($v) => Templates::lang($v)],
        ];
        $sets = [];
        $args = [];
        foreach ($map as $in => [$col, $norm]) {
            if (!array_key_exists($in, $d)) {
                continue;
            }
            if ($in === 'lang' && trim((string)$d[$in]) === '') {
                continue; // blank language = leave as-is
            }
            $sets[] = "$col = ?";
            $args[] = $norm($d[$in]);
        }
        if ($sets) {
            $args[] = $leadId;
            Db::pdo()->prepare('UPDATE leads SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        }

        // Keep the linked contact's core fields in step (name/phone/email/company).
        $cid = (int)($lead['contact_id'] ?? 0);
        if ($cid > 0) {
            $cSets = [];
            $cArgs = [];
            foreach (['name' => 'name', 'phone' => 'phone', 'email' => 'email', 'company' => 'company'] as $in => $ccol) {
                if (!array_key_exists($in, $d)) { continue; }
                $cSets[] = "$ccol = ?";
                $cArgs[] = $in === 'phone' ? Notifier::normalizePhone((string)$d[$in]) : trim((string)$d[$in]);
            }
            if ($cSets) {
                $cArgs[] = $cid;
                Db::pdo()->prepare('UPDATE contacts SET ' . implode(', ', $cSets) . ' WHERE id = ?')->execute($cArgs);
            }
        }

        Activities::add('lead', $leadId, 'system', 'Lead details edited', $actorId);
        Log::write('crm', 'lead_updated', 'lead', $leadId, ['by' => $actorId]);
        self::pushSync($leadId);
        return true;
    }

    /**
     * Per-source counts for leads received in one month ('YYYY-MM') — the basis
     * of the monthly partner report (e.g. "leads received from Cashmatic and
     * how they were processed").
     * @return array<int,array{source:string,received:int,converted:int,junk:int,still_open:int}>
     */
    public static function sourceReport(string $ym): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            return [];
        }
        $stmt = Db::pdo()->prepare(
            "SELECT source,
                    COUNT(*)                 AS received,
                    SUM(status='converted')  AS converted,
                    SUM(status='junk')       AS junk,
                    SUM(status='open')       AS still_open
             FROM leads
             WHERE received_at >= CONCAT(?, '-01')
               AND received_at <  CONCAT(?, '-01') + INTERVAL 1 MONTH
             GROUP BY source
             ORDER BY received DESC, source"
        );
        $stmt->execute([$ym, $ym]);
        return $stmt->fetchAll();
    }

    /** Permanently remove a lead plus its timeline, pending reminders and VAT claim (test-data cleanup). */
    public static function delete(int $leadId, ?int $actorId = null): void
    {
        (new Scheduler())->cancelForEntity('lead', $leadId);
        VatLock::releaseForLead($leadId);
        Db::pdo()->prepare("DELETE FROM activities WHERE entity_type='lead' AND entity_id=?")->execute([$leadId]);
        Db::pdo()->prepare('DELETE FROM leads WHERE id=?')->execute([$leadId]);
        Log::write('crm', 'lead_deleted', 'lead', $leadId, ['by' => $actorId]);
    }

    public static function count(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM leads' . ($where ? " WHERE $where" : '');
        return (int)Db::pdo()->query($sql)->fetchColumn();
    }

    private static function agent(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT id, username, full_name, email, phone FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Push to Bitrix only if the optional sync is enabled; never fatal. */
    private static function pushSync(int $leadId): void
    {
        try {
            BitrixSync::pushLeadIfEnabled($leadId);
        } catch (Throwable $e) {
            Log::write('sync', 'lead_push_failed', 'lead', $leadId, ['error' => $e->getMessage()]);
        }
    }
}
