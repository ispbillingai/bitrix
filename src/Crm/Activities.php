<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;

/**
 * Per-record timeline (notes, calls, stage moves, system lines) shown on the
 * lead/deal/appointment detail drawer. Human-readable history, as opposed to the
 * machine `events` audit log written by Glue\Event\Log.
 */
final class Activities
{
    public static function add(string $entityType, int $entityId, string $type, string $body, ?int $userId = null): void
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO activities (entity_type, entity_id, user_id, type, body) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$entityType, $entityId, $userId, $type, $body]);
    }

    /** @return array<int,array> newest first */
    public static function forEntity(string $entityType, int $entityId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = Db::pdo()->prepare(
            "SELECT a.*, u.username, u.full_name
             FROM activities a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity_type = ? AND a.entity_id = ?
             ORDER BY a.id DESC LIMIT $limit"
        );
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll();
    }
}
