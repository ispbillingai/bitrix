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

    /**
     * Is this lead a twin of another lead for the same person that has already
     * been worked?
     *
     * A double-submitted website form — or a seller re-typing a request the form
     * had already delivered — leaves two lead rows for one contact. Working one
     * of them silences that row's nudges, but the twin sits untouched in the
     * first stage and goes on chasing the customer twice a day. The customer
     * sees none of our bookkeeping: they were contacted, so the messages should
     * have stopped. (Seen in the wild: a lead moved to "In Contact" whose
     * 24-seconds-later duplicate went on to send nine more "we haven't heard
     * from you" rounds, on both channels, over the following five days.)
     *
     * True when a sibling lead on the same contact, created within $windowH of
     * this one, has since left the first stage. The window is what keeps a
     * genuinely new request weeks later on its own cadence.
     */
    public static function twinWorked(string $entityType, int $id, int $windowH = 72): bool
    {
        if ($entityType !== 'lead' || $id <= 0) {
            return false;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT 1
               FROM leads a
               JOIN leads b ON b.contact_id = a.contact_id AND b.id <> a.id
              WHERE a.id = ?
                AND a.contact_id IS NOT NULL
                AND b.stage_code <> ?
                AND ABS(TIMESTAMPDIFF(HOUR, b.created_at, a.created_at)) <= ?
              LIMIT 1'
        );
        $stmt->execute([$id, Pipelines::firstStageCode('lead'), max(1, $windowH)]);
        return (bool)$stmt->fetchColumn();
    }

    private static function row(string $entityType, int $id): ?array
    {
        $table = match ($entityType) {
            'lead'        => 'leads',
            'deal'        => 'deals',
            'appointment' => 'appointments',
            'contact'     => 'contacts',
            // Sibill debtors: name/phone/email/lang are named to match the
            // fallbacks above, so a payment chase addresses itself like any
            // other customer message.
            'sibill_customer' => 'sibill_customers',
            // SmallPay contracts carry customer_* and assigned_to for exactly
            // this reason: "your payment failed" reaches the customer and "his
            // payment failed" reaches the seller, with no special case here.
            'payment_contract' => 'payment_contracts',
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
