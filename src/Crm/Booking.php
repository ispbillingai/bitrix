<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Glue\Reminder\Templates;

/**
 * Booking a visit straight into the calendar — the half the CRM did not have.
 *
 * Until now an appointment could only be born from something else: a lead's
 * request, or an assistance request a technician had claimed. The office could
 * not simply open the diary and write down "Tuesday, 9:00, install at Rossi".
 * This class is that door, and with it three things the client asked for:
 *
 *   * a visit may have NOBODY on it (`agent_id IS NULL`) and sit in the pool
 *     until someone picks it up — booked with the customer, not yet resourced;
 *   * anyone allowed to may take one out of the pool, and a technician may
 *     hand their own to a colleague who has more room;
 *   * it may be moved — a new time, and the reminders queued for the old one
 *     dropped before anything is promised for the new.
 *
 * Messages follow the label's `kind`, so a SOPRALLUOGO reminds like a
 * technician's visit and an APPUNTAMENTO COMMERCIALE like a seller's, without
 * either being a special case here.
 */
final class Booking
{
    /**
     * Create or update a calendar appointment.
     *
     * @param array $d id (0 = new), contact_id, type_code, agent_id (0/null = pool),
     *                 starts_at, duration_min, zone, location, title, notes
     * @return array{ok:bool, id:int, error:?string, clashes:array}
     */
    public static function save(array $d, ?int $actorId = null): array
    {
        $id      = (int)($d['id'] ?? 0);
        $startTs = strtotime((string)($d['starts_at'] ?? ''));
        if (!$startTs) {
            return ['ok' => false, 'id' => $id, 'error' => 'bad_when', 'clashes' => []];
        }
        $contactId = (int)($d['contact_id'] ?? 0);
        if ($contactId <= 0) {
            return ['ok' => false, 'id' => $id, 'error' => 'no_customer', 'clashes' => []];
        }
        $contact = Contacts::find($contactId);
        if (!$contact) {
            return ['ok' => false, 'id' => $id, 'error' => 'no_customer', 'clashes' => []];
        }
        // The client made the zone mandatory: it is what lets a planner group a
        // day's jobs by where they are, and a blank one silently undoes that.
        $zone = trim((string)($d['zone'] ?? ''));
        if ($zone === '') {
            return ['ok' => false, 'id' => $id, 'error' => 'no_zone', 'clashes' => []];
        }

        $type = (string)($d['type_code'] ?? '') ?: AppointmentTypes::defaultCode();
        if (!AppointmentTypes::find($type)) {
            $type = AppointmentTypes::defaultCode();
        }
        $kind     = AppointmentTypes::kindOf($type);
        $mins     = max(15, min(480, (int)($d['duration_min'] ?? Interventions::DEFAULT_MIN)));
        $agentId  = (int)($d['agent_id'] ?? 0) ?: null;   // null = the pool
        $when     = date('Y-m-d H:i:s', $startTs);
        $ends     = date('Y-m-d H:i:s', $startTs + $mins * 60);
        $title    = trim((string)($d['title'] ?? '')) ?: AppointmentTypes::label($type);
        $lang     = Templates::lang($contact['lang'] ?? null);

        $pdo  = Db::pdo();
        $prev = $id > 0 ? Appointments::find($id) : null;
        if ($id > 0 && !$prev) {
            return ['ok' => false, 'id' => 0, 'error' => 'not_found', 'clashes' => []];
        }

        // Only a real person can clash with themselves; the pool cannot.
        $clashes = $agentId ? Interventions::clashes($agentId, $when, $mins, $id) : [];

        if ($prev) {
            // Anything that changes what people were told has to re-queue the
            // notices, so drop the old ones before writing the new time.
            (new Scheduler())->cancelForEntity('appointment', $id);
            $pdo->prepare(
                'UPDATE appointments
                    SET kind = ?, type_code = ?, contact_id = ?, agent_id = ?, title = ?,
                        `zone` = ?, location = ?, starts_at = ?, ends_at = ?, notes = ?,
                        customer_name = ?, customer_phone = ?, customer_email = ?, lang = ?,
                        status = IF(status IN ("cancelled","no_show"), "confirmed", status)
                  WHERE id = ?'
            )->execute([
                $kind, $type, $contactId, $agentId, $title,
                $zone, trim((string)($d['location'] ?? '')) ?: null, $when, $ends,
                trim((string)($d['notes'] ?? '')) ?: null,
                (string)$contact['name'], (string)($contact['phone'] ?? ''),
                (string)($contact['email'] ?? ''), $lang, $id,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO appointments
                    (kind, type_code, contact_id, agent_id, title, `zone`, location,
                     starts_at, ends_at, status, notes, customer_name, customer_phone,
                     customer_email, lang, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?, "confirmed", ?,?,?,?,?,?)'
            )->execute([
                $kind, $type, $contactId, $agentId, $title, $zone,
                trim((string)($d['location'] ?? '')) ?: null, $when, $ends,
                trim((string)($d['notes'] ?? '')) ?: null,
                (string)$contact['name'], (string)($contact['phone'] ?? ''),
                (string)($contact['email'] ?? ''), $lang, $actorId,
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        // An INSTALLAZIONE carries its form: the checklist the technician has to
        // fill in is created with the job, not hunted for on the day.
        if (AppointmentTypes::opensInstallForm($type)) {
            self::ensureInstallReport($id, $contactId, $agentId, $actorId);
        }

        self::notify($id, $prev === null, $lang);

        Activities::add('appointment', $id, 'meeting',
            ($prev ? 'Rebooked for ' : 'Booked for ') . Templates::when($startTs), $actorId);
        Log::write('crm', $prev ? 'appointment_rebooked' : 'appointment_booked', 'appointment', $id,
            ['type' => $type, 'zone' => $zone, 'agent_id' => $agentId, 'when' => $when]);

        return ['ok' => true, 'id' => $id, 'error' => null, 'clashes' => $clashes];
    }

    /**
     * Put a visit on someone, take it off them, or hand it to a colleague.
     * $techId null returns it to the pool.
     */
    public static function assign(int $apptId, ?int $techId, ?int $actorId = null): bool
    {
        $a = Appointments::find($apptId);
        if (!$a) {
            return false;
        }
        $was = (int)($a['agent_id'] ?? 0) ?: null;
        if ($was === $techId) {
            return true; // already theirs — pressing twice is not an error
        }
        Db::pdo()->prepare('UPDATE appointments SET agent_id = ? WHERE id = ?')
            ->execute([$techId, $apptId]);

        // The queued notices name a person; that person has changed.
        (new Scheduler())->cancelForEntity('appointment', $apptId);
        self::notify($apptId, false, (string)($a['lang'] ?? 'it'));

        // ...and whoever it was taken from should hear it from the CRM, not
        // from the customer on the day.
        if ($was && $was !== $techId) {
            self::tellPreviousOwner($apptId, $was, (string)($a['lang'] ?? 'it'));
        }
        if (AppointmentTypes::opensInstallForm((string)($a['type_code'] ?? ''))) {
            self::reassignInstallReport($apptId, $techId);
        }

        Activities::add('appointment', $apptId, 'system',
            $techId ? "Assigned to user $techId" : 'Returned to the pool', $actorId);
        Log::write('crm', 'appointment_assigned', 'appointment', $apptId,
            ['from' => $was, 'to' => $techId, 'by' => $actorId]);
        return true;
    }

    /** Drag-and-drop, or a new time typed in: move the visit and re-tell everyone. */
    public static function move(int $apptId, string $startsAt, ?int $durationMin = null, ?int $actorId = null): bool
    {
        $a = Appointments::find($apptId);
        $ts = strtotime($startsAt);
        if (!$a || !$ts) {
            return false;
        }
        $mins = $durationMin !== null
            ? max(15, min(480, $durationMin))
            : self::lengthOf($a);

        (new Scheduler())->cancelForEntity('appointment', $apptId);
        Db::pdo()->prepare('UPDATE appointments SET starts_at = ?, ends_at = ? WHERE id = ?')
            ->execute([
                date('Y-m-d H:i:s', $ts),
                date('Y-m-d H:i:s', $ts + $mins * 60),
                $apptId,
            ]);
        self::notify($apptId, false, (string)($a['lang'] ?? 'it'));

        Activities::add('appointment', $apptId, 'meeting', 'Moved to ' . Templates::when($ts), $actorId);
        Log::write('crm', 'appointment_moved', 'appointment', $apptId,
            ['from' => $a['starts_at'], 'to' => date('Y-m-d H:i:s', $ts)]);
        return true;
    }

    /** Minutes a booked visit runs for, defaulting when ends_at was never set. */
    public static function lengthOf(array $a): int
    {
        if (!empty($a['starts_at']) && !empty($a['ends_at'])) {
            $n = (int)round((strtotime((string)$a['ends_at']) - strtotime((string)$a['starts_at'])) / 60);
            if ($n >= 15) {
                return $n;
            }
        }
        return Interventions::DEFAULT_MIN;
    }

    // ---- reads -----------------------------------------------------------------

    /**
     * The "to be assigned" pool: booked, nobody on it, not yet past.
     *
     * @return array<int,array>
     */
    public static function pool(int $limit = 200, string $zone = ''): array
    {
        $limit = max(1, min(500, $limit));
        $sql =
            'SELECT a.*, c.company AS customer_company
               FROM appointments a
               LEFT JOIN contacts c ON c.id = a.contact_id
              WHERE a.agent_id IS NULL AND a.status = "confirmed"
                AND (a.starts_at IS NULL OR a.starts_at >= CURDATE())';
        $args = [];
        if (trim($zone) !== '') {
            $sql .= ' AND a.`zone` = ?';
            $args[] = trim($zone);
        }
        $sql .= " ORDER BY a.starts_at ASC LIMIT $limit";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll() ?: [];
    }

    /** Distinct zones in use, for the filter box. @return array<int,string> */
    public static function zones(): array
    {
        return array_values(array_filter(
            Db::pdo()->query(
                'SELECT DISTINCT `zone` FROM appointments
                  WHERE `zone` IS NOT NULL AND `zone` <> "" ORDER BY `zone`'
            )->fetchAll(\PDO::FETCH_COLUMN) ?: []
        ));
    }

    // ---- internals -------------------------------------------------------------

    /**
     * Queue what this visit owes: the customer always, the assigned person only
     * when there IS one. A pool appointment tells the customer the date and
     * says nothing about who is coming, because nobody knows yet.
     */
    private static function notify(int $apptId, bool $isNew, string $lang): void
    {
        $a = Appointments::find($apptId);
        if (!$a || (string)$a['status'] !== 'confirmed' || empty($a['starts_at'])) {
            return;
        }
        $whenTs    = (int)strtotime((string)$a['starts_at']);
        $whenLabel = Templates::when($whenTs);
        $isInterv  = (string)$a['kind'] === Interventions::KIND;
        $agentId   = (int)($a['agent_id'] ?? 0);
        $payload   = [
            'when'     => $whenLabel,
            'location' => trim((string)($a['location'] ?? '')),
            'zone'     => trim((string)($a['zone'] ?? '')),
        ];
        $sched = new Scheduler();

        // Confirmation to the customer, now. On a move this is the "it changed"
        // message — same words, new date, which is what they need to read.
        $sched->enqueue([
            'entity_type'    => 'appointment',
            'entity_id'      => $apptId,
            'rule_key'       => $isInterv ? 'intervention_confirmed' : 'appointment_confirmed',
            'recipient_type' => 'customer',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            'lang'           => $lang,
            'payload'        => $payload,
            'dedupe_key'     => "bk_cust:$apptId:$whenTs:" . $agentId,
        ]);

        if ($agentId > 0) {
            $sched->enqueue([
                'entity_type'    => 'appointment',
                'entity_id'      => $apptId,
                'rule_key'       => $isInterv ? 'intervention_tech_set' : 'appointment_agent_set',
                'recipient_type' => 'agent',
                'channel'        => 'both',
                'due_at'         => date('Y-m-d H:i:s'),
                'lang'           => $lang,
                'payload'        => $payload,
                'dedupe_key'     => "bk_agent:$apptId:$whenTs:$agentId",
            ]);
        }

        // The cadence before the day itself.
        if ($isInterv) {
            Automation::interventionReminders($apptId, $whenTs, $whenLabel, (string)$payload['location']);
        } else {
            Automation::appointmentReminders($apptId, $whenTs, $whenLabel);
        }
    }

    /** "That job is no longer yours" — to the technician it was taken from. */
    private static function tellPreviousOwner(int $apptId, int $userId, string $lang): void
    {
        $a = Appointments::find($apptId);
        if (!$a) {
            return;
        }
        $u = Db::pdo()->prepare('SELECT full_name, username, phone, email FROM users WHERE id = ?');
        $u->execute([$userId]);
        $prev = $u->fetch();
        if (!$prev) {
            return;
        }
        $when = !empty($a['starts_at']) ? Templates::when((int)strtotime((string)$a['starts_at'])) : '';
        $text = Templates::whatsapp('intervention_taken_over', [
            'name'          => trim((string)($prev['full_name'] ?? '')) ?: (string)$prev['username'],
            'customer_name' => (string)($a['customer_name'] ?? ''),
            'when'          => $when,
            'id'            => $apptId,
        ], $lang);
        $mail = Templates::email('intervention_taken_over', [
            'name'          => trim((string)($prev['full_name'] ?? '')) ?: (string)$prev['username'],
            'customer_name' => (string)($a['customer_name'] ?? ''),
            'when'          => $when,
            'id'            => $apptId,
        ], $lang);

        // Queued, never inline: reassigning happens inside a web request and
        // must not wait on WhatsApp. See Notify\StaffAlert.
        \Glue\Notify\StaffAlert::toUser($userId, 'intervention_taken_over', $text,
            (string)$mail['subject'], (string)$mail['html'], 'appointment', $apptId);
    }

    /** An INSTALLAZIONE gets its report the moment it is booked. */
    private static function ensureInstallReport(int $apptId, int $contactId, ?int $techId, ?int $actorId): void
    {
        $pdo = Db::pdo();
        $has = (int)($pdo->query("SELECT install_report_id FROM appointments WHERE id = $apptId")->fetchColumn() ?: 0);
        if ($has > 0) {
            return;
        }
        try {
            $rid = \Glue\Install\Reports::create($contactId, $techId ?: $actorId);
            if ($rid > 0) {
                $pdo->prepare('UPDATE appointments SET install_report_id = ? WHERE id = ?')
                    ->execute([$rid, $apptId]);
            }
        } catch (\Throwable $e) {
            // A calendar entry must survive a hiccup in the report module: the
            // visit is the thing that was promised to a customer, the checklist
            // can be opened by hand on the day.
            Log::write('crm', 'install_report_autocreate_failed', 'appointment', $apptId,
                ['error' => $e->getMessage()]);
        }
    }

    private static function reassignInstallReport(int $apptId, ?int $techId): void
    {
        $pdo = Db::pdo();
        $rid = (int)($pdo->query("SELECT install_report_id FROM appointments WHERE id = $apptId")->fetchColumn() ?: 0);
        if ($rid > 0 && $techId) {
            $pdo->prepare('UPDATE install_reports SET technician_id = ? WHERE id = ? AND status = "draft"')
                ->execute([$techId, $rid]);
        }
    }
}
