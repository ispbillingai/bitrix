<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Glue\Reminder\Templates;
use Glue\Sync\BitrixSync;
use Throwable;

/**
 * Deals — opportunities with a value and a pipeline. Drives requirements #6/#7:
 * entering the quote stage starts the signing-reminder cadence; the won stage
 * sends the thank-you to the customer and notifies logistics, and silences the
 * signing chase. Ported from Bitrix\EventHandler::handleDeal onto local tables.
 */
final class Deals
{
    public static function create(array $d, ?int $actorId = null): int
    {
        $pipelineId = Pipelines::defaultId('deal');
        $firstStage = Pipelines::firstStageCode('deal');
        $lang = Templates::lang($d['lang'] ?? null);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO deals
                (title, contact_id, lead_id, pipeline_id, stage_code, amount, currency,
                 assigned_to, status, expected_close_date, sign_due_date, customer_name,
                 customer_phone, customer_email, lang, stage_changed_at)
             VALUES (:title, :contact_id, :lead_id, :pipeline_id, :stage, :amount, :currency,
                 :assigned_to, "open", :close_date, :sign_due, :name, :phone, :email, :lang, NOW())'
        );
        $stmt->execute([
            ':title'       => trim((string)($d['title'] ?? 'Deal')) ?: 'Deal',
            ':contact_id'  => $d['contact_id'] ?? null,
            ':lead_id'     => $d['lead_id'] ?? null,
            ':pipeline_id' => $pipelineId,
            ':stage'       => $firstStage,
            ':amount'      => (float)($d['amount'] ?? 0),
            ':currency'    => $d['currency'] ?? Config::get('crm.currency', 'EUR'),
            ':assigned_to' => $d['assigned_to'] ?? null,
            ':close_date'  => $d['expected_close_date'] ?? null,
            ':sign_due'    => ($d['sign_due_date'] ?? '') !== '' ? $d['sign_due_date'] : null,
            ':name'        => $d['name'] ?? null,
            ':phone'       => $d['phone'] ?? null,
            ':email'       => $d['email'] ?? null,
            ':lang'        => $lang,
        ]);
        $dealId = (int)Db::pdo()->lastInsertId();

        Activities::add('deal', $dealId, 'system', 'Deal created', $actorId);
        Log::write('crm', 'deal_created', 'deal', $dealId, ['title' => $d['title'] ?? '']);
        self::pushSync($dealId);
        return $dealId;
    }

    public static function assign(int $dealId, int $agentId, ?int $actorId = null): void
    {
        $agent = self::agent($agentId);
        if (!$agent) {
            return;
        }
        Db::pdo()->prepare('UPDATE deals SET assigned_to = ? WHERE id = ?')->execute([$agentId, $dealId]);
        Automation::agentAssigned('deal', $dealId, $agent);
        $label = trim((string)($agent['full_name'] ?? '')) ?: $agent['username'];
        Activities::add('deal', $dealId, 'system', "Assigned to $label", $actorId);
        Log::write('crm', 'deal_assigned', 'deal', $dealId, ['agent_id' => $agentId]);
        self::pushSync($dealId);
    }

    /**
     * Move stage and fire the quote/won/lost automations. $signDueDate (optional,
     * 'Y-m-d') is the signature due date the agent sets when sending the quote; it
     * is persisted and anchors the signing cadence. Passing only a due date while
     * already in the quote stage re-anchors the reminders to the new date.
     */
    public static function moveStage(int $dealId, string $stageCode, ?int $actorId = null, ?string $signDueDate = null): void
    {
        $deal = self::find($dealId);
        if (!$deal) {
            return;
        }
        $oldStage   = (string)$deal['stage_code'];
        $quoteStage = (string)Config::get('crm.deal_quote_stage', 'QUOTE');
        $oldDue     = ($deal['sign_due_date'] ?? '') !== '' ? (string)$deal['sign_due_date'] : null;

        // Persist a signature due date if the agent supplied one with this action.
        $dueProvided = $signDueDate !== null && trim($signDueDate) !== '';
        $dueChanged  = $dueProvided && $signDueDate !== $oldDue;
        if ($dueChanged) {
            Db::pdo()->prepare('UPDATE deals SET sign_due_date = ? WHERE id = ?')->execute([$signDueDate, $dealId]);
            $deal['sign_due_date'] = $signDueDate;
        }

        $stageChanged = $oldStage !== $stageCode;
        if (!$stageChanged && !$dueChanged) {
            return; // nothing to do
        }

        $stage  = Pipelines::stage((int)$deal['pipeline_id'], $stageCode);
        $isWon  = $stage && (int)$stage['is_won'] === 1;
        $isLost = $stage && (int)$stage['is_lost'] === 1;
        $status = $isWon ? 'won' : ($isLost ? 'lost' : 'open');
        $sched  = new Scheduler();

        if ($stageChanged) {
            Db::pdo()->prepare(
                'UPDATE deals SET stage_code = ?, status = ?, stage_changed_at = NOW() WHERE id = ?'
            )->execute([$stageCode, $status, $dealId]);
        }

        $dueDate = ($deal['sign_due_date'] ?? '') !== '' ? (string)$deal['sign_due_date'] : null;

        // #6 Quote sent -> start the signing cadence; or re-anchor it if the agent
        // updated the due date while the deal is already in the quote stage.
        $enteringQuote = $stageCode === $quoteStage && $oldStage !== $quoteStage;
        $reAnchor      = !$stageChanged && $dueChanged && $stageCode === $quoteStage;
        if ($enteringQuote || $reAnchor) {
            if ($reAnchor) {
                $sched->cancelForEntity('deal', $dealId, ['sign_due', 'sign_overdue']);
            }
            Automation::signCadence($dealId, $quoteStage, $dueDate);
        }
        // #7 Won -> thank-you + logistics, stop chasing the signature.
        if ($isWon) {
            $sched->cancelForEntity('deal', $dealId, ['sign_due', 'sign_overdue']);
            Automation::closing($dealId);
        }
        // Lost -> just stop chasing.
        if ($isLost) {
            $sched->cancelForEntity('deal', $dealId, ['sign_due', 'sign_overdue']);
        }

        if ($stageChanged) {
            Activities::add('deal', $dealId, 'stage',
                'Stage: ' . Pipelines::label('deal', $oldStage) . ' → ' . Pipelines::label('deal', $stageCode), $actorId);
            Log::write('crm', 'deal_stage_changed', 'deal', $dealId, ['from' => $oldStage, 'to' => $stageCode]);
        }
        self::pushSync($dealId);
    }

    /**
     * Record the customer's electronic signature (after OTP verification) and move
     * the deal to its won stage, which fires the thank-you + logistics automations.
     * Idempotent: a deal already signed is left untouched.
     */
    public static function markSigned(int $dealId, string $signerName, string $ip): bool
    {
        $deal = self::find($dealId);
        if (!$deal || !empty($deal['signed_at'])) {
            return false;
        }
        Db::pdo()->prepare(
            'UPDATE deals SET signed_at = NOW(), signed_name = ?, signed_ip = ? WHERE id = ?'
        )->execute([$signerName, $ip, $dealId]);

        Activities::add('deal', $dealId, 'system', 'Contract signed by customer (OTP)');
        Log::write('crm', 'deal_signed', 'deal', $dealId, ['name' => $signerName, 'ip' => $ip]);

        $won = Pipelines::wonStageCode('deal') ?? 'WON';
        self::moveStage($dealId, $won);
        return true;
    }

    // ---- reads ----------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM deals WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function all(int $limit = 300, ?int $assignedTo = null): array
    {
        $limit = max(1, min(1000, $limit));
        $where = $assignedTo ? ' WHERE d.assigned_to = ' . (int)$assignedTo : '';
        return Db::pdo()->query(
            "SELECT d.*, u.username AS agent_username, u.full_name AS agent_name
             FROM deals d LEFT JOIN users u ON u.id = d.assigned_to
             $where ORDER BY d.id DESC LIMIT $limit"
        )->fetchAll();
    }

    /** Open deals grouped by stage_code (for the kanban board). $assignedTo scopes to one seller. */
    public static function byStage(?int $assignedTo = null): array
    {
        $where = "WHERE d.status = 'open'" . ($assignedTo ? ' AND d.assigned_to = ' . (int)$assignedTo : '');
        $rows = Db::pdo()->query(
            "SELECT d.*, u.username AS agent_username, u.full_name AS agent_name
             FROM deals d LEFT JOIN users u ON u.id = d.assigned_to
             $where ORDER BY d.id DESC"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['stage_code']][] = $r;
        }
        return $out;
    }

    /** Total value of open deals (pipeline weight for the overview). $assignedTo scopes to one seller. */
    public static function openValue(?int $assignedTo = null): float
    {
        $where = "WHERE status='open'" . ($assignedTo ? ' AND assigned_to = ' . (int)$assignedTo : '');
        return (float)Db::pdo()->query("SELECT COALESCE(SUM(amount),0) FROM deals $where")->fetchColumn();
    }

    public static function count(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM deals' . ($where ? " WHERE $where" : '');
        return (int)Db::pdo()->query($sql)->fetchColumn();
    }

    private static function agent(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT id, username, full_name, email, phone FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function pushSync(int $dealId): void
    {
        try {
            BitrixSync::pushDealIfEnabled($dealId);
        } catch (Throwable $e) {
            Log::write('sync', 'deal_push_failed', 'deal', $dealId, ['error' => $e->getMessage()]);
        }
    }
}
