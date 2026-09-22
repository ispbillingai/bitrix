<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Glue\Reminder\Templates;

/**
 * Technical support interventions — the visit a technician books after taking
 * charge of an assistance request.
 *
 * The request flow (Portal\AssistRequests) stopped at "presa in carico": one
 * technician claimed it, the ticket thread carried the conversation, and the
 * actual visit was arranged by phone and written down nowhere. Two technicians
 * could promise the same morning and the office could not see either.
 *
 * An intervention is an appointment with kind = 'intervention'. That is not a
 * shortcut: the appointments table already holds the start, the end, the place
 * and the assigned staff member, and Automation already reminds BOTH sides as
 * it approaches. The technician goes in agent_id, so EntityResolver finds their
 * phone and email with no new plumbing — for the scheduler a technician is a
 * seller with a different job title.
 *
 * Rescheduling is the same call again: the row is updated, the pending
 * reminders for the old time are cancelled, and both sides are told the new one.
 */
final class Interventions
{
    public const KIND = 'intervention';

    /** Default length of a visit, when the technician does not say otherwise. */
    public const DEFAULT_MIN = 60;

    /**
     * Book (or move) the visit for an assistance request. Returns the
     * appointment id, or 0 when the date or the request is unusable.
     *
     * @param array $opts title, location, notes, duration_min
     */
    public static function schedule(int $requestId, int $techId, string $startsAt,
                                    array $opts = [], ?int $actorId = null): int
    {
        $whenTs = strtotime($startsAt);
        if (!$whenTs || $requestId <= 0 || $techId <= 0) {
            return 0;
        }
        $pdo = Db::pdo();
        $req = $pdo->prepare(
            'SELECT r.*, c.name AS customer_name, c.phone AS registry_phone,
                    c.email AS registry_email, c.lang AS registry_lang
               FROM assist_requests r JOIN contacts c ON c.id = r.contact_id
              WHERE r.id = ?'
        );
        $req->execute([$requestId]);
        $r = $req->fetch();
        if (!$r) {
            return 0;
        }

        $durationMin = max(15, min(480, (int)($opts['duration_min'] ?? self::DEFAULT_MIN)));
        $when  = date('Y-m-d H:i:s', $whenTs);
        $ends  = date('Y-m-d H:i:s', $whenTs + $durationMin * 60);
        $title = trim((string)($opts['title'] ?? '')) ?: (string)$r['subject'];
        // An empty address box must not wipe an address a colleague already
        // typed, so every optional field goes in through COALESCE(NULLIF()).
        $location = trim((string)($opts['location'] ?? ''));
        $notes    = trim((string)($opts['notes'] ?? ''));
        $lang = Templates::lang($r['registry_lang'] ?? null);

        // Reuse the booked row only while it is still outstanding. A visit that
        // already happened — or was called off — is history: booking again on
        // the same request is a SECOND visit and gets its own row, or the first
        // one would be quietly overwritten and the record of it lost.
        $existing = (int)($r['appointment_id'] ?? 0);
        if ($existing > 0 && !self::isOpenIntervention($existing)) {
            $existing = 0;
        }

        if ($existing > 0) {
            // A move, not a second visit: drop what was queued for the old time
            // before anything is promised for the new one, or the customer gets
            // reminded about a slot nobody is coming to.
            (new Scheduler())->cancelForEntity('appointment', $existing);
            $pdo->prepare(
                'UPDATE appointments
                    SET agent_id = ?, starts_at = ?, ends_at = ?, status = "confirmed",
                        title = COALESCE(NULLIF(?, ""), title),
                        location = COALESCE(NULLIF(?, ""), location),
                        notes = COALESCE(NULLIF(?, ""), notes)
                  WHERE id = ?'
            )->execute([$techId, $when, $ends, $title, $location, $notes, $existing]);
            $apptId = $existing;
        } else {
            $pdo->prepare(
                'INSERT INTO appointments
                    (kind, contact_id, assist_request_id, ticket_id, agent_id, title, location,
                     starts_at, ends_at, status, notes, customer_name, customer_phone,
                     customer_email, lang)
                 VALUES (:kind, :contact, :req, :ticket, :tech, :title, :loc,
                     :starts, :ends, "confirmed", :notes, :name, :phone, :email, :lang)'
            )->execute([
                ':kind'    => self::KIND,
                ':contact' => (int)$r['contact_id'],
                ':req'     => $requestId,
                ':ticket'  => ($r['ticket_id'] ?? null) ?: null,
                ':tech'    => $techId,
                ':title'   => $title,
                ':loc'     => $location ?: null,
                ':starts'  => $when,
                ':ends'    => $ends,
                ':notes'   => $notes ?: null,
                ':name'    => (string)$r['customer_name'],
                ':phone'   => (string)($r['contact_phone'] ?: $r['registry_phone']),
                ':email'   => (string)($r['registry_email'] ?? ''),
                ':lang'    => $lang,
            ]);
            $apptId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('UPDATE assist_requests SET appointment_id = ? WHERE id = ?')
            ->execute([$apptId, $requestId]);

        $whenLabel = Templates::when($whenTs);
        $sched = new Scheduler();
        // The address rides in the payload — it is not one of the resolved
        // entity vars — and is read back from the row so a reschedule that left
        // the box empty still quotes the address already on file. The templates
        // wrap it in {?location}…{/location}, so a visit without one simply
        // does not mention a place.
        $payload = ['when' => $whenLabel, 'location' => self::storedLocation($apptId)];

        // Immediately: the customer learns who is coming and when...
        $sched->enqueue([
            'entity_type'    => 'appointment',
            'entity_id'      => $apptId,
            'rule_key'       => 'intervention_confirmed',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'lang'           => $lang,
            'payload'        => $payload,
            'dedupe_key'     => "interv_confirm:$apptId:$whenTs",
        ]);
        // ...and the technician gets it in writing, not only on the screen where
        // they typed it. They are in the field; the WhatsApp is what they keep.
        $sched->enqueue([
            'entity_type'    => 'appointment',
            'entity_id'      => $apptId,
            'rule_key'       => 'intervention_tech_set',
            'recipient_type' => 'agent',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'lang'           => $lang,
            'payload'        => $payload,
            'dedupe_key'     => "interv_tech_set:$apptId:$whenTs",
        ]);

        $n = Automation::interventionReminders($apptId, $whenTs, $whenLabel, $payload['location']);

        Activities::add('appointment', $apptId, 'meeting',
            ($existing > 0 ? 'Intervention moved to ' : 'Intervention booked for ') . $whenLabel, $actorId);
        Log::write('crm', 'intervention_scheduled', 'appointment', $apptId,
            ['assist_request_id' => $requestId, 'technician_id' => $techId,
             'when' => $when, 'rescheduled' => $existing > 0, 'reminders' => $n]);
        return $apptId;
    }

    /** Mark the visit done / cancelled / not carried out. Cancelling silences it. */
    public static function setStatus(int $apptId, string $status, ?int $actorId = null): void
    {
        if (!self::isIntervention($apptId)) {
            return;
        }
        Appointments::setStatus($apptId, $status, $actorId);
    }

    // ---- reads ----------------------------------------------------------------

    /** The address as stored, after the insert or the COALESCE'd update. */
    private static function storedLocation(int $apptId): string
    {
        $stmt = Db::pdo()->prepare('SELECT location FROM appointments WHERE id = ?');
        $stmt->execute([$apptId]);
        return trim((string)$stmt->fetchColumn());
    }

    public static function isIntervention(int $apptId): bool
    {
        $stmt = Db::pdo()->prepare('SELECT kind FROM appointments WHERE id = ?');
        $stmt->execute([$apptId]);
        return (string)$stmt->fetchColumn() === self::KIND;
    }

    /** An intervention still to be carried out — the one a reschedule may move. */
    public static function isOpenIntervention(int $apptId): bool
    {
        $stmt = Db::pdo()->prepare('SELECT kind, status FROM appointments WHERE id = ?');
        $stmt->execute([$apptId]);
        $row = $stmt->fetch();
        return $row
            && (string)$row['kind'] === self::KIND
            && in_array((string)$row['status'], ['confirmed', 'requested'], true);
    }

    /** The visit currently booked for a request, technician name included. */
    public static function forRequest(int $requestId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT a.*, u.full_name AS tech_name, u.username AS tech_username
               FROM appointments a
               LEFT JOIN users u ON u.id = a.agent_id
              WHERE a.assist_request_id = ? AND a.kind = ?
              ORDER BY a.id DESC LIMIT 1'
        );
        $stmt->execute([$requestId, self::KIND]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The still-outstanding visit on each request, keyed by request id — what
     * the support queue shows next to "presa in carico".
     *
     * Deliberately not date-filtered: a visit booked for last Tuesday that
     * nobody has marked done is exactly the row the office needs to see, and
     * dropping it would offer to book a second one over the top.
     *
     * @return array<int,array>
     */
    public static function openByRequest(int $limit = 400): array
    {
        $limit = max(1, min(1000, $limit));
        $rows = Db::pdo()->query(
            'SELECT a.*, u.full_name AS tech_name, u.username AS tech_username
               FROM appointments a
               LEFT JOIN users u ON u.id = a.agent_id
              WHERE a.kind = "' . self::KIND . '"
                AND a.status IN ("confirmed", "requested")
                AND a.assist_request_id IS NOT NULL
              ORDER BY a.id ASC
              LIMIT ' . $limit
        )->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['assist_request_id']] = $r; // ASC, so the newest wins
        }
        return $out;
    }

    /**
     * The diary the office coordinates from: every booked visit from today on,
     * soonest first. $techId narrows it to one technician's own round.
     *
     * @return array<int,array>
     */
    public static function upcoming(int $limit = 200, ?int $techId = null): array
    {
        $limit = max(1, min(500, $limit));
        $sql =
            'SELECT a.*, u.full_name AS tech_name, u.username AS tech_username,
                    r.subject AS request_subject, r.priority, c.company AS customer_company
               FROM appointments a
               LEFT JOIN users u ON u.id = a.agent_id
               LEFT JOIN assist_requests r ON r.id = a.assist_request_id
               LEFT JOIN contacts c ON c.id = a.contact_id
              WHERE a.kind = ? AND a.status = "confirmed"
                AND (a.starts_at IS NULL OR a.starts_at >= CURDATE())';
        $args = [self::KIND];
        if ($techId) {
            $sql .= ' AND a.agent_id = ?';
            $args[] = $techId;
        }
        $sql .= " ORDER BY a.starts_at ASC LIMIT $limit";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Is this technician already out on a job across this window? Two
     * technicians may share a slot; one technician may not be in two places.
     * Returns the clashing rows so the caller can name them.
     *
     * @return array<int,array>
     */
    public static function clashes(int $techId, string $startsAt, int $durationMin = self::DEFAULT_MIN,
                                   int $ignoreId = 0): array
    {
        $startTs = strtotime($startsAt);
        if (!$startTs || $techId <= 0) {
            return [];
        }
        $start = date('Y-m-d H:i:s', $startTs);
        $end   = date('Y-m-d H:i:s', $startTs + max(15, $durationMin) * 60);
        // Overlap, not equality: a 14:00–15:00 job clashes with one at 14:30.
        // A sales appointment counts too — the same person cannot do both — and
        // an older row with no ends_at is read as the default hour.
        $stmt = Db::pdo()->prepare(
            'SELECT a.id, a.kind, a.title, a.starts_at, a.ends_at, a.customer_name
               FROM appointments a
              WHERE a.agent_id = ? AND a.id <> ? AND a.status = "confirmed"
                AND a.starts_at IS NOT NULL
                AND a.starts_at < ?
                AND COALESCE(a.ends_at, a.starts_at + INTERVAL 1 HOUR) > ?
              ORDER BY a.starts_at LIMIT 5'
        );
        $stmt->execute([$techId, $ignoreId, $end, $start]);
        return $stmt->fetchAll() ?: [];
    }
}
