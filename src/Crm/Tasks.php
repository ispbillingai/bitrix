<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;

/**
 * Tasks assigned to sellers, with an optional KPI score recorded on completion —
 * the doc's "Score Evaluation (KPI) for the various tasks assigned to employees to
 * monitor performance". agentKpi()/leaderboard() aggregate those scores.
 */
final class Tasks
{
    public static function create(array $d, ?int $actorId = null): int
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO tasks
                (title, description, assigned_to, related_type, related_id, due_at, priority, kpi_weight)
             VALUES (:title, :description, :assigned_to, :related_type, :related_id, :due_at, :priority, :weight)'
        );
        $stmt->execute([
            ':title'        => trim((string)($d['title'] ?? 'Task')) ?: 'Task',
            ':description'  => $d['description'] ?? null,
            ':assigned_to'  => $d['assigned_to'] ?? null,
            ':related_type' => $d['related_type'] ?? null,
            ':related_id'   => $d['related_id'] ?? null,
            ':due_at'       => !empty($d['due_at']) ? date('Y-m-d H:i:s', strtotime((string)$d['due_at'])) : null,
            ':priority'     => in_array($d['priority'] ?? '', ['low', 'normal', 'high'], true) ? $d['priority'] : 'normal',
            ':weight'       => max(1, (int)($d['kpi_weight'] ?? 1)),
        ]);
        $id = (int)Db::pdo()->lastInsertId();
        Log::write('crm', 'task_created', $d['related_type'] ?? null, (int)($d['related_id'] ?? 0) ?: null,
            ['task_id' => $id, 'assigned_to' => $d['assigned_to'] ?? null]);
        return $id;
    }

    /** Complete a task and (optionally) record its KPI score. */
    public static function complete(int $id, ?int $score = null, ?int $actorId = null): void
    {
        Db::pdo()->prepare(
            'UPDATE tasks SET status = "done", completed_at = NOW(), kpi_score = COALESCE(?, kpi_score) WHERE id = ?'
        )->execute([$score, $id]);
        Log::write('crm', 'task_completed', null, null, ['task_id' => $id, 'kpi_score' => $score]);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['open', 'done', 'cancelled'], true)) {
            return;
        }
        $done = $status === 'done' ? ', completed_at = NOW()' : '';
        Db::pdo()->prepare("UPDATE tasks SET status = ?$done WHERE id = ?")->execute([$status, $id]);
    }

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function all(int $limit = 300): array
    {
        $limit = max(1, min(1000, $limit));
        return Db::pdo()->query(
            "SELECT t.*, u.username AS agent_username, u.full_name AS agent_name
             FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to
             ORDER BY (t.status='open') DESC, (t.due_at IS NULL), t.due_at ASC, t.id DESC LIMIT $limit"
        )->fetchAll();
    }

    public static function count(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM tasks' . ($where ? " WHERE $where" : '');
        return (int)Db::pdo()->query($sql)->fetchColumn();
    }

    /**
     * KPI leaderboard: per seller, the number of completed tasks and the
     * weighted average score. Weighted by kpi_weight so important tasks count more.
     */
    public static function leaderboard(): array
    {
        return Db::pdo()->query(
            "SELECT u.id, u.username, u.full_name,
                    COUNT(t.id) AS total,
                    SUM(t.status='done') AS done,
                    SUM(t.status='open' AND t.due_at IS NOT NULL AND t.due_at < NOW()) AS overdue,
                    ROUND(SUM(t.kpi_score * t.kpi_weight) / NULLIF(SUM(CASE WHEN t.kpi_score IS NOT NULL THEN t.kpi_weight END),0), 1) AS kpi
             FROM users u LEFT JOIN tasks t ON t.assigned_to = u.id
             WHERE u.active = 1
             GROUP BY u.id, u.username, u.full_name
             ORDER BY kpi DESC, done DESC"
        )->fetchAll();
    }
}
