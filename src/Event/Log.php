<?php
declare(strict_types=1);

namespace Glue\Event;

use Glue\Db;

/**
 * Append-only audit trail. Every endpoint and the scheduler write here so we can
 * always reconstruct "why did this message go out?". Mirrors parking's logEvent().
 */
final class Log
{
    public static function write(
        string $source,
        string $eventType,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): void {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO events (source, event_type, entity_type, entity_id, details)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $source,
            $eventType,
            $entityType,
            $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
