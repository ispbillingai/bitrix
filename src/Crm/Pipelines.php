<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use PDO;

/**
 * Pipelines + their ordered stages — the configurable funnels that replace the
 * hard-coded Bitrix status IDs. A `code` (e.g. NEW, QUOTE, WON) is the stable key
 * the automation and reminders compare against; names/colours are presentation.
 *
 * Stages are editable from Settings, so everything here reads live from the DB
 * (cached per request). Seeded by migrations/008_crm_seed.sql.
 */
final class Pipelines
{
    private static array $cache = [];

    /** Default pipeline id for 'lead' or 'deal' (lowest sort, is_default first). */
    public static function defaultId(string $entityType): int
    {
        $row = Db::pdo()->prepare(
            'SELECT id FROM pipelines WHERE entity_type = ? ORDER BY is_default DESC, sort ASC, id ASC LIMIT 1'
        );
        $row->execute([$entityType]);
        return (int)($row->fetchColumn() ?: 0);
    }

    /** All pipelines (for the Settings editor), grouped is irrelevant — ordered. */
    public static function all(): array
    {
        return Db::pdo()->query(
            'SELECT * FROM pipelines ORDER BY entity_type, sort, id'
        )->fetchAll();
    }

    /** Ordered stages of a pipeline. */
    public static function stages(int $pipelineId): array
    {
        if (!isset(self::$cache[$pipelineId])) {
            $stmt = Db::pdo()->prepare('SELECT * FROM stages WHERE pipeline_id = ? ORDER BY sort, id');
            $stmt->execute([$pipelineId]);
            self::$cache[$pipelineId] = $stmt->fetchAll();
        }
        return self::$cache[$pipelineId];
    }

    /** Ordered stages of the default pipeline for an entity type. */
    public static function stagesForEntity(string $entityType): array
    {
        return self::stages(self::defaultId($entityType));
    }

    public static function stage(int $pipelineId, string $code): ?array
    {
        foreach (self::stages($pipelineId) as $s) {
            if ($s['code'] === $code) {
                return $s;
            }
        }
        return null;
    }

    /** First stage code for an entity type (where new records land). */
    public static function firstStageCode(string $entityType): string
    {
        $stages = self::stagesForEntity($entityType);
        foreach ($stages as $s) {
            if ((int)$s['is_first'] === 1) {
                return $s['code'];
            }
        }
        return $stages[0]['code'] ?? 'NEW';
    }

    /** Won/lost stage code for an entity type, or null if none flagged. */
    public static function wonStageCode(string $entityType): ?string
    {
        return self::flaggedCode($entityType, 'is_won');
    }

    public static function lostStageCode(string $entityType): ?string
    {
        return self::flaggedCode($entityType, 'is_lost');
    }

    private static function flaggedCode(string $entityType, string $flag): ?string
    {
        foreach (self::stagesForEntity($entityType) as $s) {
            if ((int)$s[$flag] === 1) {
                return $s['code'];
            }
        }
        return null;
    }

    /** Human label for a stage code (falls back to the code itself). */
    public static function label(string $entityType, string $code): string
    {
        foreach (self::stagesForEntity($entityType) as $s) {
            if ($s['code'] === $code) {
                return $s['name'];
            }
        }
        return $code;
    }

    /** Invalidate the per-request stage cache (after a Settings edit). */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
