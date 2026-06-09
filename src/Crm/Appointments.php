<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Glue\Reminder\Templates;

/**
 * Appointments — requirement #5 and the core of the new brief: a customer asks
 * for an appointment with a salesperson; staff assign a seller and confirm the
 * real time; the system reminds BOTH parties as it approaches.
 *
 * Flow: request() (status 'requested', a *preferred* time) -> schedule() (an
 * agent + a confirmed starts_at -> immediate confirmation + reminders at the
 * configured offsets).
 */
final class Appointments
{
    /** Customer-side request (from the public form / webhook). status = requested. */
    public static function request(array $d, ?int $actorId = null): int
    {
        $lang = Templates::lang($d['lang'] ?? null);
        $preferredTs = !empty($d['preferred_at']) ? strtotime((string)$d['preferred_at']) : false;

        $stmt = Db::pdo()->prepare(
            'INSERT INTO appointments
                (contact_id, lead_id, agent_id, title, location, preferred_at, status,
                 notes, customer_name, customer_phone, customer_email, lang)
             VALUES (:contact_id, :lead_id, :agent_id, :title, :location, :preferred, "requested",
                 :notes, :name, :phone, :email, :lang)'
        );
        $stmt->execute([
            ':contact_id' => $d['contact_id'] ?? null,
            ':lead_id'    => $d['lead_id'] ?? null,
            ':agent_id'   => ($d['agent_id'] ?? null) ?: null,
            ':title'      => trim((string)($d['title'] ?? '')) ?: 'Appointment request',
            ':location'   => $d['location'] ?? null,
            ':preferred'  => $preferredTs ? date('Y-m-d H:i:s', $preferredTs) : null,
            ':notes'      => $d['notes'] ?? null,
            ':name'       => $d['name'] ?? null,
            ':phone'      => $d['phone'] ?? null,
            ':email'      => $d['email'] ?? null,
            ':lang'       => $lang,
        ]);
        $id = (int)Db::pdo()->lastInsertId();

        Activities::add('appointment', $id, 'system', 'Appointment requested', $actorId);
        Log::write('crm', 'appointment_requested', 'appointment', $id,
            ['preferred' => $preferredTs ? date('Y-m-d H:i:s', $preferredTs) : null]);
        return $id;
    }

    /**
     * Confirm an appointment: assign an agent, set the real start time, and
     * enqueue the customer + agent reminders (plus an immediate confirmation).
     */
    public static function schedule(int $apptId, int $agentId, string $startsAt, array $opts = [], ?int $actorId = null): int
    {
        $whenTs = strtotime($startsAt);
        if (!$whenTs) {
            return 0;
        }
        $appt = self::find($apptId);
        if (!$appt) {
            return 0;
        }
        $when = date('Y-m-d H:i:s', $whenTs);

        $stmt = Db::pdo()->prepare(
            'UPDATE appointments
             SET agent_id = ?, starts_at = ?, status = "confirmed",
                 title = COALESCE(NULLIF(?, ""), title),
                 location = COALESCE(NULLIF(?, ""), location)
             WHERE id = ?'
        );
        $stmt->execute([
            $agentId, $when,
            (string)($opts['title'] ?? ''), (string)($opts['location'] ?? ''),
            $apptId,
        ]);

        $lang = $appt['lang'] ?? null;
        $whenLabel = (string)($opts['when_label'] ?? date('D j M Y, H:i', $whenTs));

        // Immediate confirmation to the customer.
        (new Scheduler())->enqueue([
            'entity_type'    => 'appointment',
            'entity_id'      => $apptId,
            'rule_key'       => 'appointment_confirmed',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'lang'           => $lang,
            'payload'        => ['when' => $whenLabel],
            'dedupe_key'     => "appt_confirm:$apptId:$whenTs",
        ]);

        // Reminders to both parties as the event approaches.
        $n = Automation::appointmentReminders($apptId, $whenTs, $whenLabel);

        Activities::add('appointment', $apptId, 'meeting', "Confirmed for $whenLabel", $actorId);
        Log::write('crm', 'appointment_scheduled', 'appointment', $apptId,
            ['agent_id' => $agentId, 'when' => $when, 'reminders' => $n]);
        return $n;
    }

    public static function setStatus(int $apptId, string $status, ?int $actorId = null): void
    {
        $allowed = ['requested', 'confirmed', 'done', 'cancelled', 'no_show'];
        if (!in_array($status, $allowed, true)) {
            return;
        }
        Db::pdo()->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$status, $apptId]);
        if ($status === 'cancelled') {
            (new Scheduler())->cancelForEntity('appointment', $apptId);
        }
        Activities::add('appointment', $apptId, 'system', "Status: $status", $actorId);
        Log::write('crm', 'appointment_status', 'appointment', $apptId, ['status' => $status]);
    }

    // ---- reads ----------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM appointments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function all(int $limit = 300, ?int $agentId = null): array
    {
        $limit = max(1, min(1000, $limit));
        $where = $agentId ? ' WHERE a.agent_id = ' . (int)$agentId : '';
        return Db::pdo()->query(
            "SELECT a.*, u.username AS agent_username, u.full_name AS agent_name
             FROM appointments a LEFT JOIN users u ON u.id = a.agent_id
             $where ORDER BY (a.starts_at IS NULL) DESC, a.starts_at ASC, a.id DESC LIMIT $limit"
        )->fetchAll();
    }

    public static function count(string $where = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM appointments' . ($where ? " WHERE $where" : '');
        return (int)Db::pdo()->query($sql)->fetchColumn();
    }
}
