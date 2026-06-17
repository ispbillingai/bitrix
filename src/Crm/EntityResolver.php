<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;

/**
 * Bridges the reminder Scheduler to the local CRM tables. Given an
 * (entity_type, entity_id), it returns everything a message template needs —
 * the customer's name/phone/email/lang, the assigned agent's contact details,
 * and the record's current stage_code (for the manual-silence guard).
 *
 * This is what replaced the old Tracking\Repo + live Bitrix REST reads: the
 * Scheduler now answers "who is this for?" and "has the deal moved?" from our
 * own database.
 */
final class EntityResolver
{
    /**
     * @return array{
     *   customer_name:?string, customer_phone:?string, customer_email:?string,
     *   lang:?string, stage_code:?string, assigned_to:?int,
     *   agent_name:?string, agent_phone:?string, agent_email:?string
     * }
     */
    public static function resolve(string $entityType, int $id): array
    {
        $base = [
            'customer_name' => null, 'customer_phone' => null, 'customer_email' => null,
            'lang' => null, 'stage_code' => null, 'assigned_to' => null,
            'agent_name' => null, 'agent_phone' => null, 'agent_email' => null,
        ];

        $row = self::row($entityType, $id);
        if (!$row) {
            return $base;
        }

        // leads/deals/appointments store the customer as customer_*; the contacts
        // table uses plain name/phone/email. Fall back so a 'contact' entity (portal
        // invite, ticket reply, OTP, offer reminders) still resolves a recipient —
        // otherwise the dispatcher finds no phone/email and silently sends nothing.
        $base['customer_name']  = $row['customer_name']  ?? $row['name']  ?? null;
        $base['customer_phone'] = $row['customer_phone'] ?? $row['phone'] ?? null;
        $base['customer_email'] = $row['customer_email'] ?? $row['email'] ?? null;
        $base['lang']           = $row['lang'] ?? null;
        $base['stage_code']     = $row['stage_code'] ?? null;

        // Assigned agent: leads/deals use assigned_to, appointments use agent_id.
        $agentId = (int)($row['assigned_to'] ?? $row['agent_id'] ?? 0);
        $base['assigned_to'] = $agentId ?: null;
        if ($agentId > 0) {
            $agent = self::agent($agentId);
            if ($agent) {
                $base['agent_name']  = trim((string)($agent['full_name'] ?? '')) ?: ($agent['username'] ?? null);
                $base['agent_phone'] = $agent['phone'] ?? null;
                $base['agent_email'] = $agent['email'] ?? null;
            }
        }
        return $base;
    }

    /** Just the current stage code, for the stage-moved silence guard. */
    public static function stageCode(string $entityType, int $id): ?string
    {
        $row = self::row($entityType, $id);
        return $row['stage_code'] ?? null;
    }

    private static function row(string $entityType, int $id): ?array
    {
        $table = match ($entityType) {
            'lead'        => 'leads',
            'deal'        => 'deals',
            'appointment' => 'appointments',
            'contact'     => 'contacts',
            default       => null,
        };
        if ($table === null || $id <= 0) {
            return null;
        }
        $stmt = Db::pdo()->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function agent(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT id, username, full_name, email, phone FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
