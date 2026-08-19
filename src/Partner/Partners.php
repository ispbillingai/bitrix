<?php
declare(strict_types=1);

namespace Glue\Partner;

use Glue\Crm\Leads;
use Glue\Crm\Pipelines;
use Glue\Crm\VatLock;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Reminder\Scheduler;
use Throwable;

/**
 * Partners (referrers). A partner brings customers in two ways: by sharing their
 * personal link (?ref=CODE), or by typing the lead into their own area himself
 * (submitLead). Either way the lead carries referred_by_partner_id, and a
 * referred lead that becomes a WON deal earns the partner a commission (a % of
 * the deal value) as a 'pending' accrual an admin later approves and marks paid.
 * Partners are not CRM users — separate table + separate login (partner.php).
 * See migrations 019 and 033.
 *
 * What a partner is told, and when, is deliberately narrow (client's rule): in
 * their area they see the STATUS of their leads and nothing of how the pipeline
 * is being worked, and they are messaged ONLY once a lead reaches the end of the
 * road — closed or lost. notifyOutcome() is the single door for that message.
 */
final class Partners
{
    // ---- accounts -------------------------------------------------------------

    /** @return array<int,array> all partners, newest first. */
    public static function all(): array
    {
        return Db::pdo()->query("SELECT * FROM partners ORDER BY id DESC")->fetchAll() ?: [];
    }

    public static function find(int $id): ?array
    {
        $s = Db::pdo()->prepare("SELECT * FROM partners WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function byRefCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $s = Db::pdo()->prepare("SELECT * FROM partners WHERE ref_code = ? AND active = 1");
        $s->execute([$code]);
        return $s->fetch() ?: null;
    }

    /**
     * The existing partner these details would duplicate, or null. Contact details
     * only: an email or a phone already on the roster belongs to one referrer, so
     * reusing one is refused outright.
     *
     * A shared NAME is deliberately NOT a duplicate — two referrers can genuinely
     * be called the same thing, and refusing the second would be wrong. It is only
     * flagged, by sameNameId() below, since the pair that started all this shared
     * nothing else: one row was entered with only an email, the other with only a
     * phone. Blocking is for what cannot legitimately repeat; the name gets a
     * warning and the delete button.
     *
     * Both sides are normalised before comparing (partner phones are stored as
     * typed — migration 031 rewrote leads and contacts, not this table), and the
     * roster is a handful of rows, so it is compared in PHP rather than in SQL.
     *
     * $excludeId: "would this duplicate a partner OTHER than this one?" — asked when
     * editing, whose own row must not count as its own twin.
     */
    public static function duplicateId(array $d, ?int $excludeId = null): ?int
    {
        $email = mb_strtolower(trim((string)($d['email'] ?? '')));
        $phone = Notifier::normalizePhone((string)($d['phone'] ?? ''));
        if ($email === '' && $phone === '') {
            return null;
        }
        foreach (self::all() as $p) {
            if ($excludeId !== null && (int)$p['id'] === $excludeId) {
                continue;
            }
            if ($email !== '' && $email === mb_strtolower(trim((string)($p['email'] ?? '')))) {
                return (int)$p['id'];
            }
            if ($phone !== '' && $phone === Notifier::normalizePhone((string)($p['phone'] ?? ''))) {
                return (int)$p['id'];
            }
        }
        return null;
    }

    /**
     * A partner already on the roster under this name, or null. Never blocks a
     * save — it is what lets the save SAY "there is already a Massimiliano Cioffi
     * Trade, #18", which is the only signal available when the two entries share
     * no contact detail. Whether they are the same referrer is the admin's call.
     */
    public static function sameNameId(array $d, ?int $excludeId = null): ?int
    {
        $name = self::foldName((string)($d['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        foreach (self::all() as $p) {
            if ($excludeId !== null && (int)$p['id'] === $excludeId) {
                continue;
            }
            if ($name === self::foldName((string)$p['name'])) {
                return (int)$p['id'];
            }
        }
        return null;
    }

    /** Case- and spacing-insensitive name, for comparison only. */
    private static function foldName(string $name): string
    {
        return trim(mb_strtolower((string)preg_replace('/\s+/u', ' ', trim($name))));
    }

    /**
     * Delete a partner. Returns ['ok'=>bool, 'error'=>?string, ...counts].
     *
     * Refused while the partner still has referred leads or commission accruals:
     * deleting then would either orphan the attribution or quietly erase money
     * owed. Deactivating is the answer for a partner who has worked — this exists
     * for the roster mistake (a duplicate, a typo'd entry), which by definition has
     * neither. The referral link dies with the row: ?ref=CODE stops resolving.
     */
    public static function delete(int $id, ?int $actorId = null): array
    {
        $p = self::find($id);
        if (!$p) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $pdo  = Db::pdo();
        $refs = (int)$pdo->query('SELECT COUNT(*) FROM leads WHERE referred_by_partner_id = ' . (int)$id)->fetchColumn();
        $accr = (int)$pdo->query('SELECT COUNT(*) FROM partner_accruals WHERE partner_id = ' . (int)$id)->fetchColumn();
        if ($refs > 0 || $accr > 0) {
            return ['ok' => false, 'error' => 'in_use', 'referrals' => $refs, 'accruals' => $accr];
        }
        $pdo->prepare('DELETE FROM partners WHERE id = ?')->execute([$id]);
        Log::write('partner', 'partner_deleted', 'partner', $id,
            ['name' => $p['name'], 'ref' => $p['ref_code'], 'by' => $actorId]);
        return ['ok' => true];
    }

    /**
     * Create a partner. Generates a unique ref_code if none given. Returns id.
     * @param array $d name|email|phone|commission_pct|ref_code|password
     */
    public static function create(array $d): int
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name required');
        }
        $ref = trim((string)($d['ref_code'] ?? '')) ?: self::genCode($name);
        // Ensure uniqueness.
        while (self::refExists($ref)) {
            $ref = self::genCode($name);
        }
        $hash = !empty($d['password']) ? password_hash((string)$d['password'], PASSWORD_BCRYPT) : null;

        $s = Db::pdo()->prepare(
            "INSERT INTO partners (name, email, phone, ref_code, commission_pct, password_hash, active)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $s->execute([
            $name,
            trim((string)($d['email'] ?? '')) ?: null,
            trim((string)($d['phone'] ?? '')) ?: null,
            $ref,
            self::clampPct($d['commission_pct'] ?? 10),
            $hash,
            !empty($d['active']) || !isset($d['active']) ? 1 : 0,
        ]);
        $id = (int)Db::pdo()->lastInsertId();
        Log::write('partner', 'partner_created', 'partner', $id, ['name' => $name, 'ref' => $ref]);
        return $id;
    }

    /** Update a partner. Blank password keeps the existing one. */
    public static function update(int $id, array $d): void
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            return;
        }
        $ref = trim((string)($d['ref_code'] ?? ''));
        if ($ref === '') {
            $cur = self::find($id);
            $ref = $cur['ref_code'] ?? self::genCode($name);
        }
        $active = !empty($d['active']) ? 1 : 0;
        $pct = self::clampPct($d['commission_pct'] ?? 10);

        if (!empty($d['password'])) {
            Db::pdo()->prepare(
                "UPDATE partners SET name=?, email=?, phone=?, ref_code=?, commission_pct=?, active=?, password_hash=? WHERE id=?"
            )->execute([$name, trim((string)($d['email'] ?? '')) ?: null, trim((string)($d['phone'] ?? '')) ?: null,
                $ref, $pct, $active, password_hash((string)$d['password'], PASSWORD_BCRYPT), $id]);
        } else {
            Db::pdo()->prepare(
                "UPDATE partners SET name=?, email=?, phone=?, ref_code=?, commission_pct=?, active=? WHERE id=?"
            )->execute([$name, trim((string)($d['email'] ?? '')) ?: null, trim((string)($d['phone'] ?? '')) ?: null,
                $ref, $pct, $active, $id]);
        }
        Log::write('partner', 'partner_updated', 'partner', $id, ['name' => $name]);
    }

    /** Email/phone + password login for the partner area. Returns the row or null. */
    public static function login(string $loginId, string $password): ?array
    {
        $loginId = trim($loginId);
        if ($loginId === '' || $password === '') {
            return null;
        }
        $s = Db::pdo()->prepare(
            "SELECT * FROM partners WHERE active = 1 AND password_hash IS NOT NULL
               AND (email = :e OR (phone <> '' AND phone = :p)) LIMIT 1"
        );
        $s->execute([':e' => $loginId, ':p' => $loginId]);
        $row = $s->fetch();
        if ($row && password_verify($password, (string)$row['password_hash'])) {
            unset($row['password_hash']);
            return $row;
        }
        return null;
    }

    // ---- referrals ------------------------------------------------------------

    /** Attribute a lead to a partner (called from the public form when ?ref= is set). */
    public static function attributeLead(int $leadId, int $partnerId): void
    {
        Db::pdo()->prepare("UPDATE leads SET referred_by_partner_id = ? WHERE id = ?")
            ->execute([$partnerId, $leadId]);
        Log::write('partner', 'lead_referred', 'lead', $leadId, ['partner_id' => $partnerId]);
    }

    /**
     * Set — or clear — a lead's partner by hand. attributeLead() above is the
     * automatic door (the ?ref= link, the partner's own area); this is the
     * deliberate one, used by the owner to file a lead on behalf of a partner who
     * phoned it in, and to correct an attribution that landed on the wrong one.
     *
     * Deliberately NOT bound by the "no claiming by re-typing" rule submitLead()
     * enforces: that rule exists to stop a partner awarding themselves someone
     * else's customer, and the owner overriding attribution is the remedy for it,
     * not an instance of it. So it leaves a trace on the lead's own timeline —
     * who moved the credit, and off whom.
     *
     * Commission follows attribution at won-time (accrueForDeal reads this same
     * column when the deal closes), so re-pointing a lead that has not been won
     * yet re-points the commission with it.
     *
     * @param int|null $partnerId null (or 0) clears the attribution
     * @return bool false when the id names no partner — nothing is changed
     */
    public static function setReferrer(int $leadId, ?int $partnerId, ?int $actorId = null): bool
    {
        $partner = null;
        if ($partnerId !== null && $partnerId > 0) {
            $partner = self::find($partnerId);
            if (!$partner) {
                return false;
            }
        } else {
            $partnerId = null;
        }

        $current = self::ownerIdOfLead($leadId);
        if ($current === $partnerId) {
            return true; // already where it should be — no timeline noise
        }
        Db::pdo()->prepare("UPDATE leads SET referred_by_partner_id = ? WHERE id = ?")
            ->execute([$partnerId, $leadId]);

        $was = $current !== null ? (string)(self::find($current)['name'] ?? "#$current") : null;
        \Glue\Crm\Activities::add('lead', $leadId, 'system',
            ($partner ? 'Lead attributed to partner ' . $partner['name'] : 'Partner attribution removed')
            . ($was !== null ? " (was $was)" : ''), $actorId);
        Log::write('partner', 'lead_referrer_set', 'lead', $leadId,
            ['partner_id' => $partnerId, 'was' => $current, 'by' => $actorId]);
        return true;
    }

    /**
     * A partner's leads — the ones they referred through their link and the ones
     * they typed in themselves. Carries the internal stage/status (the admin view
     * shows them) plus `deal_status`, the last word on how the lead ended, which
     * outcome() below turns into the one thing the PARTNER is shown.
     */
    public static function referrals(int $partnerId): array
    {
        $s = Db::pdo()->prepare(
            "SELECT l.id, l.customer_name, l.stage_code, l.status, l.received_at,
                    (SELECT d.status FROM deals d WHERE d.lead_id = l.id
                      ORDER BY d.id DESC LIMIT 1) AS deal_status
               FROM leads l WHERE l.referred_by_partner_id = ? ORDER BY l.id DESC"
        );
        $s->execute([$partnerId]);
        return $s->fetchAll() ?: [];
    }

    /**
     * The one word a partner is shown about a lead. Still only a status — never
     * who is working it, which seller, or what it is worth — but a status that
     * MOVES, which is the whole point of showing one:
     *
     *   new         just arrived, nobody has picked it up yet
     *   contacted   we have been in touch
     *   qualified   it is a real opportunity
     *   working     ditto, for any other stage a custom pipeline may add
     *   negotiation a deal is live on it but the lead was never marked converted
     *   won         CLOSED — the lead reached the converted stage, or its deal won
     *   lost        discarded, or the deal fell through
     *
     * The first cut of this collapsed everything except "discarded" into a single
     * "in progress", so a partner watched their lead be contacted, qualified and
     * converted without one word changing on screen — reported, fairly, as "the
     * status is not updated". Every stage the office moves a lead through now
     * shows up here.
     *
     * CONVERTED IS CLOSED. That is the office's own reading of its board — the
     * cards sitting in that column say "Completato - Consegnato" — so a partner
     * whose lead reaches it is told it closed, without waiting on the deal
     * pipeline behind it. A deal explicitly marked LOST still overrides: that is
     * a deliberate "it fell through after all", and the partner should hear the
     * truth rather than keep a win that stopped being one.
     *
     * What a partner is MESSAGED about stays deliberately narrow — closed or
     * lost only, never the rungs in between. See notifyOutcome().
     *
     * @param array $row a row from referrals() (or any lead row plus deal_status)
     */
    public static function status(array $row): string
    {
        if (($row['status'] ?? '') === 'junk') {
            return 'lost';
        }
        $deal = (string)($row['deal_status'] ?? '');
        if ($deal === 'lost') { return 'lost'; }   // an explicit reversal wins
        if ($deal === 'won')  { return 'won'; }
        if (($row['status'] ?? '') === 'converted') {
            return 'won';
        }
        if ($deal === 'open') {
            return 'negotiation';
        }

        $stage = strtoupper(trim((string)($row['stage_code'] ?? '')));
        if ($stage === '' || $stage === strtoupper((string)Pipelines::firstStageCode('lead'))) {
            return 'new';
        }
        // The seeded pipeline's own stages get their own word; anything an
        // operator adds later falls back to the honest generic one.
        return match ($stage) {
            'CONTACTED' => 'contacted',
            'QUALIFIED' => 'qualified',
            default     => 'working',
        };
    }

    /**
     * The coarse bucket behind status(): 'open', 'won' or 'lost'. What the
     * overview tiles count, and the vocabulary the closed/lost notification
     * speaks — neither wants the full ladder.
     */
    public static function outcome(array $row): string
    {
        return match (self::status($row)) {
            'won'   => 'won',
            'lost'  => 'lost',
            default => 'open',
        };
    }

    // ---- partner-entered leads ------------------------------------------------

    /**
     * A partner files a lead from their own area. Same door as everyone else
     * (Leads::create), so the duplicate check, the welcome message and the
     * automations behave exactly as they do for a lead off the public form —
     * plus the two rules that are specific to partner entries:
     *
     *   - 90-day VAT exclusivity. A partita IVA another associate already claimed
     *     is refused here rather than filed, and the partner is told when it frees
     *     up (same treatment as the ?ref= form gives, see public/request.php).
     *   - No claiming by re-typing. If the entry merely duplicates a lead that
     *     already exists, the request is still recorded on that lead's timeline
     *     (Leads::create groups it) but attribution is never touched. Otherwise a
     *     partner could take over a colleague's customer — or harvest the office's
     *     own inbound leads — simply by typing their name in. Only a genuinely new
     *     lead is attributed; the partner is told which of the two happened.
     *
     * @param array $d name|company|email|phone|vat_number|comments|lang
     * @return array{ok:bool,error?:string,lead_id?:int,duplicate?:string,available_at?:string}
     */
    public static function submitLead(int $partnerId, array $d): array
    {
        $partner = self::find($partnerId);
        if (!$partner || (int)$partner['active'] !== 1) {
            return ['ok' => false, 'error' => 'inactive'];
        }

        $name  = trim((string)($d['name'] ?? ''));
        $email = trim((string)($d['email'] ?? ''));
        $phone = trim((string)($d['phone'] ?? ''));
        if ($name === '' || ($email === '' && $phone === '')) {
            return ['ok' => false, 'error' => 'required'];
        }

        $fields = [
            'name'       => $name,
            'email'      => $email,
            'phone'      => $phone,
            'company'    => trim((string)($d['company'] ?? '')),
            'comments'   => trim((string)($d['comments'] ?? '')),
            'vat_number' => (string)($d['vat_number'] ?? ''),
            'source'     => 'partner',
            'lang'       => $d['lang'] ?? null,
        ];

        // VAT exclusivity first: a blocked number must not create a lead at all.
        $vat = VatLock::normalize((string)($d['vat_number'] ?? ''));
        $claim = ['ok' => true, 'fresh' => false];
        if ($vat !== '') {
            $claim = VatLock::claim($vat, 'partner', $partnerId);
            if (!$claim['ok']) {
                VatLock::notifyTaken('partner', $partnerId, $vat, (string)$claim['available_at']);
                if (!empty($claim['lead_id'])) {
                    \Glue\Crm\Activities::add('lead', (int)$claim['lead_id'], 'system',
                        "Blocked duplicate entry of VAT $vat via partner {$partner['name']} (locked until "
                        . date('d/m/Y', strtotime((string)$claim['available_at'])) . ')');
                }
                Log::write('partner', 'vat_blocked', 'lead', (int)($claim['lead_id'] ?? 0),
                    ['vat' => $vat, 'partner_id' => $partnerId]);
                return ['ok' => false, 'error' => 'vat_taken',
                    'available_at' => date('d/m/Y', strtotime((string)$claim['available_at']) ?: time())];
            }
        }

        // Ask BEFORE creating whether this is a duplicate: create() answers with the
        // existing lead's id in that case, and we need to know which id we got.
        $dupId  = Leads::duplicateId($fields);
        $leadId = Leads::create($fields);
        if ($dupId !== null && $leadId === $dupId) {
            $ownerId = (int)(self::ownerIdOfLead($leadId) ?? 0);
            Log::write('partner', 'lead_duplicate', 'lead', $leadId,
                ['partner_id' => $partnerId, 'owner_id' => $ownerId]);
            return ['ok' => true, 'lead_id' => $leadId,
                'duplicate' => $ownerId === $partnerId ? 'own' : 'other'];
        }

        self::attributeLead($leadId, $partnerId);
        if ($vat !== '' && !empty($claim['fresh'])) {
            VatLock::attachLead($vat, $leadId);
            VatLock::notifyThanks('partner', $partnerId, $vat, $name);
        }
        Log::write('partner', 'lead_entered', 'lead', $leadId, ['partner_id' => $partnerId]);
        return ['ok' => true, 'lead_id' => $leadId];
    }

    // ---- end-of-road notification ---------------------------------------------

    /**
     * Tell the owning partner their lead is finished — closed ('won') or 'lost'.
     * The ONLY message a partner ever gets about a lead's progress. Three callers,
     * all of them a finish line:
     *   Leads::moveStage  -> 'won'  when the lead reaches the converted stage,
     *                        which is where this office considers a lead closed;
     *   Leads::moveStage  -> 'lost' when it is discarded instead;
     *   Deals::moveStage  -> 'won'/'lost' when a deal is settled either way.
     *
     * Dedupe is keyed on the LEAD, not the entity, so one customer journey yields
     * at most one "closed" and one "lost" however many records it passed through —
     * converting a lead and then winning its deal is one message, not two.
     * Never fatal, and never enqueued at all when no (active) partner owns it.
     */
    public static function notifyOutcome(string $entityType, int $entityId, string $outcome): void
    {
        try {
            if (!in_array($outcome, ['won', 'lost'], true)) {
                return;
            }
            $leadId  = $entityType === 'deal' ? self::leadIdOfDeal($entityId) : $entityId;
            $partner = $leadId > 0 ? self::forLead($leadId) : null;
            if (!$partner) {
                return;
            }
            (new Scheduler())->enqueue([
                'entity_type'    => $entityType,
                'entity_id'      => $entityId,
                'rule_key'       => $outcome === 'won' ? 'partner_lead_won' : 'partner_lead_lost',
                'recipient_type' => 'partner',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s'),
                'dedupe_key'     => "partner_$outcome:lead:$leadId",
            ]);
            Log::write('partner', 'lead_outcome_notified', $entityType, $entityId,
                ['partner_id' => (int)$partner['id'], 'outcome' => $outcome, 'lead_id' => $leadId]);
        } catch (Throwable $e) {
            // A partner notification must never break a stage change.
            Log::write('partner', 'lead_outcome_failed', $entityType, $entityId, ['error' => $e->getMessage()]);
        }
    }

    /** The ACTIVE partner behind a lead, or null. */
    public static function forLead(int $leadId): ?array
    {
        $id = self::ownerIdOfLead($leadId);
        if ($id === null) {
            return null;
        }
        $p = self::find($id);
        return $p && (int)$p['active'] === 1 ? $p : null;
    }

    /**
     * The ACTIVE partner behind a lead or a deal, or null — the Scheduler's way of
     * answering "who is this partner-addressed reminder actually for?".
     */
    public static function forEntity(string $entityType, int $entityId): ?array
    {
        $leadId = $entityType === 'deal' ? self::leadIdOfDeal($entityId)
                : ($entityType === 'lead' ? $entityId : 0);
        return $leadId > 0 ? self::forLead($leadId) : null;
    }

    // ---- accruals -------------------------------------------------------------

    /**
     * Create the commission accrual for a WON deal, if it came from a partner
     * referral and no accrual exists yet. Idempotent via UNIQUE(deal_id).
     * Called from Deals::moveStage() on the won transition.
     */
    public static function accrueForWonDeal(int $dealId): void
    {
        try {
            $pdo = Db::pdo();
            $deal = $pdo->prepare("SELECT id, lead_id, amount FROM deals WHERE id = ?");
            $deal->execute([$dealId]);
            $d = $deal->fetch();
            if (!$d || empty($d['lead_id'])) {
                return;
            }
            // Which partner referred the originating lead?
            $lead = $pdo->prepare("SELECT referred_by_partner_id FROM leads WHERE id = ?");
            $lead->execute([(int)$d['lead_id']]);
            $partnerId = (int)($lead->fetchColumn() ?: 0);
            if ($partnerId <= 0) {
                return;
            }
            $partner = self::find($partnerId);
            if (!$partner) {
                return;
            }
            $pct  = (float)$partner['commission_pct'];
            $base = (float)($d['amount'] ?? 0);
            $amount = round($base * $pct / 100, 2);

            // INSERT IGNORE so a re-won / double-fire never duplicates (UNIQUE deal_id).
            $pdo->prepare(
                "INSERT IGNORE INTO partner_accruals
                    (partner_id, lead_id, deal_id, base_amount, commission_pct, amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            )->execute([$partnerId, (int)$d['lead_id'], $dealId, $base, $pct, $amount]);

            Log::write('partner', 'accrual_created', 'deal', $dealId,
                ['partner_id' => $partnerId, 'amount' => $amount, 'pct' => $pct]);
        } catch (Throwable $e) {
            Log::write('partner', 'accrual_failed', 'deal', $dealId, ['error' => $e->getMessage()]);
        }
    }

    /** Accruals for a partner (optionally filtered by status). */
    public static function accruals(int $partnerId, ?string $status = null): array
    {
        $sql = "SELECT a.*, d.title AS deal_title, l.customer_name
                  FROM partner_accruals a
                  LEFT JOIN deals d ON d.id = a.deal_id
                  LEFT JOIN leads l ON l.id = a.lead_id
                 WHERE a.partner_id = ?";
        $args = [$partnerId];
        if ($status !== null) {
            $sql .= " AND a.status = ?";
            $args[] = $status;
        }
        $sql .= " ORDER BY a.id DESC";
        $s = Db::pdo()->prepare($sql);
        $s->execute($args);
        return $s->fetchAll() ?: [];
    }

    /** Totals per status for a partner: ['pending'=>x,'approved'=>y,'paid'=>z,'total'=>...]. */
    public static function totals(int $partnerId): array
    {
        $s = Db::pdo()->prepare(
            "SELECT status, COALESCE(SUM(amount),0) amt FROM partner_accruals
              WHERE partner_id = ? AND status <> 'cancelled' GROUP BY status"
        );
        $s->execute([$partnerId]);
        $out = ['pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0];
        foreach ($s->fetchAll() as $r) {
            $out[$r['status']] = (float)$r['amt'];
        }
        $out['total'] = $out['pending'] + $out['approved'] + $out['paid'];
        return $out;
    }

    /** Admin: move an accrual to a new state, stamping the time. */
    public static function setAccrualStatus(int $accrualId, string $status): void
    {
        if (!in_array($status, ['pending', 'approved', 'paid', 'cancelled'], true)) {
            return;
        }
        $stamp = $status === 'approved' ? ', approved_at = NOW()'
               : ($status === 'paid' ? ', paid_at = NOW()' : '');
        Db::pdo()->prepare("UPDATE partner_accruals SET status = ?$stamp WHERE id = ?")
            ->execute([$status, $accrualId]);
        Log::write('partner', 'accrual_' . $status, 'accrual', $accrualId, []);
    }

    // ---- helpers --------------------------------------------------------------

    /** referred_by_partner_id of a lead: an id, or null when nobody owns it. */
    private static function ownerIdOfLead(int $leadId): ?int
    {
        $s = Db::pdo()->prepare('SELECT referred_by_partner_id FROM leads WHERE id = ?');
        $s->execute([$leadId]);
        $id = (int)($s->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    }

    /** The lead a deal grew out of (0 when it was created standalone). */
    private static function leadIdOfDeal(int $dealId): int
    {
        $s = Db::pdo()->prepare('SELECT lead_id FROM deals WHERE id = ?');
        $s->execute([$dealId]);
        return (int)($s->fetchColumn() ?: 0);
    }

    private static function refExists(string $code): bool
    {
        $s = Db::pdo()->prepare("SELECT 1 FROM partners WHERE ref_code = ?");
        $s->execute([$code]);
        return (bool)$s->fetchColumn();
    }

    private static function genCode(string $name): string
    {
        $slug = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name) ?: 'PART', 0, 4));
        return $slug . strtoupper(bin2hex(random_bytes(2)));
    }

    private static function clampPct($v): float
    {
        $v = (float)$v;
        if ($v < 0) { $v = 0; }
        if ($v > 100) { $v = 100; }
        return round($v, 2);
    }
}
