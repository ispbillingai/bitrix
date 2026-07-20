<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Throwable;

/**
 * View counter for the PUBLIC forms (#16). Each time a shareable form is opened
 * one row is written, so the office can compare a fair link's reach with the
 * leads it produced. Recording is best-effort — a counter failure must never
 * stop the form from rendering.
 */
final class FormViews
{
    /** Log one view of $formKey ('fair', 'request', …). $ref is optional context, e.g. the fair name. */
    public static function record(string $formKey, ?string $ref = null): void
    {
        try {
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null;
            Db::pdo()->prepare('INSERT INTO form_views (form_key, ref, ip) VALUES (?, ?, ?)')
                ->execute([$formKey, $ref !== null && $ref !== '' ? mb_substr($ref, 0, 120) : null, $ip]);
        } catch (Throwable) {
            // table not migrated yet, or a transient DB issue — never block the form
        }
    }

    /**
     * Totals for a form: ['total' => int, 'month' => int, 'last' => ?string].
     * 'month' counts the current calendar month.
     */
    public static function stats(string $formKey): array
    {
        try {
            $stmt = Db::pdo()->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS month,
                        MAX(created_at) AS last
                 FROM form_views WHERE form_key = ?"
            );
            $stmt->execute([$formKey]);
            $r = $stmt->fetch() ?: [];
            return [
                'total' => (int)($r['total'] ?? 0),
                'month' => (int)($r['month'] ?? 0),
                'last'  => $r['last'] ?? null,
            ];
        } catch (Throwable) {
            return ['total' => 0, 'month' => 0, 'last' => null];
        }
    }
}
