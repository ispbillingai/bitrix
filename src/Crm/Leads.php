<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;
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
     * @param array $d name|phone|email|source|title|comments|company|lang
     * @return int new lead id
     */
    public static function create(array $d, ?int $actorId = null): int
    {
        $name   = trim((string)($d['name'] ?? ''));
        $phone  = trim((string)($d['phone'] ?? ''));
        $email  = trim((string)($d['email'] ?? ''));
        // Lowercase so "Cashmatic" and "cashmatic" count as one source in reports.
        $source = mb_strtolower(trim((string)($d['source'] ?? 'website'))) ?: 'website';
        $lang   = Templates::lang($d['lang'] ?? null);
        $title  = trim((string)($d['title'] ?? '')) ?: ($name !== '' ? "Request: $name" : 'New request');

        $contactId = Contacts::findOrCreate([
            'name' => $name ?: 'Unknown', 'phone' => $phone, 'email' => $email,
            'company' => $d['company'] ?? null, 'lang' => $lang, 'source' => $source,
        ]);

        $pipelineId = Pipelines::defaultId('lead');
        $firstStage = Pipelines::firstStageCode('lead');

        $stmt = Db::pdo()->prepare(
            'INSERT INTO leads
                (contact_id, title, source, pipeline_id, stage_code, status,
                 customer_name, customer_phone, customer_email, comments, lang,
                 received_at, stage_changed_at)
             VALUES (:contact_id, :title, :source, :pipeline_id, :stage, "open",
                 :name, :phone, :email, :comments, :lang, NOW(), NOW())'
        );
        $stmt->execute([
            ':contact_id' => $contactId, ':title' => $title, ':source' => $source,
            ':pipeline_id' => $pipelineId, ':stage' => $firstStage,
            ':name' => $name ?: null, ':phone' => $phone ?: null, ':email' => $email ?: null,
            ':comments' => $d['comments'] ?? null, ':lang' => $lang,
        ]);
        $leadId = (int)Db::pdo()->lastInsertId();

        Automation::welcome('lead', $leadId, $lang);
        Automation::inactivity('lead', $leadId, $firstStage);

        Activities::add('lead', $leadId, 'system', "Lead created from $source", $actorId);
        Log::write('crm', 'lead_created', 'lead', $leadId,
            ['source' => $source, 'name' => $name, 'phone' => $phone, 'email' => $email]);

        self::pushSync($leadId);
        return $leadId;
    }

    /** Assign the lead to a seller and message the customer the seller's profile (#3). */
    public static function assign(int $leadId, int $agentId, ?int $actorId = null): void
    {
        $agent = self::agent($agentId);
        if (!$agent) {
            return;
        }
        Db::pdo()->prepare('UPDATE leads SET assigned_to = ? WHERE id = ?')->execute([$agentId, $leadId]);

        Automation::agentAssigned('lead', $leadId, $agent);
        $label = trim((string)($agent['full_name'] ?? '')) ?: $agent['username'];
        Activities::add('lead', $leadId, 'system', "Assigned to $label", $actorId);
        Log::write('crm', 'lead_assigned', 'lead', $leadId, ['agent_id' => $agentId]);
        self::pushSync($leadId);
    }

    /** Move the lead to a new stage; silences the inactivity timer once it leaves NEW. */
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

        if ($oldStage === $firstStage && $stageCode !== $firstStage) {
            (new Scheduler())->cancelForEntity('lead', $leadId, ['lead_inactivity']);
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
        if (!$lead) {
            return 0;
        }
        $dealId = Deals::create([
            'title'    => $lead['title'] ?: ('Deal: ' . ($lead['customer_name'] ?? '')),
            'contact_id' => $lead['contact_id'],
            'lead_id'  => $leadId,
            'name'     => $lead['customer_name'],
            'phone'    => $lead['customer_phone'],
            'email'    => $lead['customer_email'],
            'lang'     => $lead['lang'],
            'assigned_to' => $lead['assigned_to'],
        ], $actorId);

        $won = Pipelines::wonStageCode('lead') ?? 'CONVERTED';
        self::moveStage($leadId, $won, $actorId);
        Activities::add('lead', $leadId, 'system', "Converted to deal #$dealId", $actorId);
        return $dealId;
    }

    // ---- reads ----------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM leads WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array> recent leads with agent label ($assignedTo scopes to one seller, $source to one origin) */
    public static function all(int $limit = 300, ?int $assignedTo = null, ?string $source = null): array
    {
        $limit = max(1, min(1000, $limit));
        $conds = [];
        if ($assignedTo) {
            $conds[] = 'l.assigned_to = ' . (int)$assignedTo;
        }
        if ($source !== null && $source !== '') {
            $conds[] = 'l.source = ' . Db::pdo()->quote($source);
        }
        $where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';
        return Db::pdo()->query(
            "SELECT l.*, u.username AS agent_username, u.full_name AS agent_name
             FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
             $where ORDER BY l.id DESC LIMIT $limit"
        )->fetchAll();
    }

    /**
     * Leads grouped by stage_code for the kanban board. $assignedTo scopes to one
     * seller. Shows OPEN leads plus leads that were DISCARDED (status 'junk') so
     * the Discarded/lost column actually populates — moving a lead to the lost
     * stage sets status='junk', and previously those vanished from the board.
     * Converted leads leave the board (they live on as deals).
     */
    public static function byStage(?int $assignedTo = null): array
    {
        $where = "WHERE l.status IN ('open', 'junk')" . ($assignedTo ? ' AND l.assigned_to = ' . (int)$assignedTo : '');
        $rows = Db::pdo()->query(
            "SELECT l.*, u.username AS agent_username, u.full_name AS agent_name
             FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
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

    /** Permanently remove a lead plus its timeline and pending reminders (test-data cleanup). */
    public static function delete(int $leadId, ?int $actorId = null): void
    {
        (new Scheduler())->cancelForEntity('lead', $leadId);
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
