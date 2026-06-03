<?php
declare(strict_types=1);

namespace Glue\Tracking;

use Glue\Db;
use PDO;

/**
 * Read/write helper for tracked_entities — the small local mirror of Bitrix
 * leads/deals we watch for timers. Bitrix stays the source of truth; this only
 * holds the anchor timestamps and last-known stage needed to fire reminders.
 */
final class Repo
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Db::pdo();
    }

    public function find(string $entityType, int $bitrixId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM tracked_entities WHERE entity_type=? AND bitrix_id=?'
        );
        $stmt->execute([$entityType, $bitrixId]);
        return $stmt->fetch() ?: null;
    }

    /** Insert or update the mirror. $fields keys map to columns. Returns row id. */
    public function upsert(string $entityType, int $bitrixId, array $fields): int
    {
        $existing = $this->find($entityType, $bitrixId);
        if ($existing) {
            if ($fields) {
                $sets = implode(', ', array_map(static fn($k) => "$k=?", array_keys($fields)));
                $stmt = $this->db->prepare("UPDATE tracked_entities SET $sets WHERE id=?");
                $stmt->execute([...array_values($fields), $existing['id']]);
            }
            return (int)$existing['id'];
        }

        $cols = array_merge(['entity_type', 'bitrix_id'], array_keys($fields));
        $ph   = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $this->db->prepare(
            'INSERT INTO tracked_entities (' . implode(', ', $cols) . ") VALUES ($ph)"
        );
        $stmt->execute([$entityType, $bitrixId, ...array_values($fields)]);
        return (int)$this->db->lastInsertId();
    }

    public function markStage(string $entityType, int $bitrixId, string $stageId): void
    {
        $this->upsert($entityType, $bitrixId, [
            'stage_id' => $stageId,
            'stage_changed_at' => date('Y-m-d H:i:s'),
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function close(string $entityType, int $bitrixId): void
    {
        $this->upsert($entityType, $bitrixId, ['status' => 'closed']);
    }
}
