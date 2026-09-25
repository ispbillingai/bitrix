<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Glue\Reminder\Templates;

/**
 * The maintenance contract on a customer's record, and the chase for the ones
 * who have none.
 *
 * The CRM already held two half-answers to "is this customer covered?" and
 * showed neither on the record: a live SmallPay subscription, and the
 * gestionale's own expiry date. This resolves them — plus an override the
 * office can type in — into one answer, and when there is nothing at all the
 * answer is A CHIAMATA: on demand, pay per visit.
 *
 * A customer on demand is a customer nobody is billing monthly, which is
 * exactly the customer worth calling. Three months after their last visit the
 * CRM asks them to book the next one, and keeps asking every three months until
 * somebody actually goes — the moment a visit happens the clock restarts from
 * it, so a booked customer is never chased.
 *
 * The other end of the same question is the customer who DOES have a contract:
 * a set number of days before it runs out, at a set hour, they are told so and
 * asked whether they want it renewed. Both runs are off until the office turns
 * them on, both are capped per day, and both only queue — the scheduler
 * delivers.
 */
final class Maintenance
{
    public const ON_DEMAND  = 'ON_DEMAND';
    public const SUBSCRIPTION = 'SUBSCRIPTION';
    public const GESTIONALE = 'GESTIONALE';
    public const CUSTOM     = 'CUSTOM';

    /** Months between the last visit and the nudge, and between nudges. */
    private const DEFAULT_MONTHS = 3;

    /** Never message more than this many customers in one cron pass. */
    private const BATCH = 25;

    /** How many days before the expiry date the customer is warned, by default. */
    private const DEFAULT_EXPIRY_DAYS = [30, 7];

    /** The hour the expiry notices go out, when the office has not set one. */
    private const DEFAULT_EXPIRY_AT = '09:00';

    /**
     * ...and never more than this many in a day.
     *
     * The per-pass cap alone is not a limit: the scheduler runs every minute,
     * so 25 a pass is 36,000 a day. The registry holds over ten thousand
     * customers with no periodic contract, and the day somebody switches this
     * on, every one of them with an old completed visit becomes due at once.
     * A daily ceiling turns that from a blast into a roll-out, and leaves room
     * to switch it off again after seeing the first day's replies.
     */
    private const DEFAULT_MAX_PER_DAY = 50;

    public static function enabled(): bool
    {
        return (bool)Config::get('maintenance.enabled', false);
    }

    public static function months(): int
    {
        return max(1, min(24, (int)Config::get('maintenance.followup_months', self::DEFAULT_MONTHS)));
    }

    /**
     * What cover this customer has. Always answers — A CHIAMATA is an answer,
     * not a missing one, which is the whole point of showing it on the record.
     *
     * @return array{type:string, label:string, fee_cents:?int, currency:string,
     *               period:?string, expires_at:?string, source:string, note:?string}
     */
    public static function forContact(int $contactId): array
    {
        $none = [
            'type' => self::ON_DEMAND, 'label' => '', 'fee_cents' => null, 'currency' => 'EUR',
            'period' => null, 'expires_at' => null, 'source' => 'none', 'note' => null,
        ];
        if ($contactId <= 0) {
            return $none;
        }
        $c = Contacts::find($contactId);
        if (!$c) {
            return $none;
        }

        // 1. The office's own entry wins: a paper contract, or one billed
        //    outside SmallPay, is still a contract and the CRM cannot infer it.
        $manual = trim((string)($c['maint_type'] ?? ''));
        if ($manual !== '' && strtoupper($manual) !== self::ON_DEMAND) {
            return [
                'type' => self::CUSTOM, 'label' => $manual,
                'fee_cents' => $c['maint_fee_cents'] !== null ? (int)$c['maint_fee_cents'] : null,
                'currency' => 'EUR',
                'period' => (string)($c['maint_period'] ?? '') ?: null,
                'expires_at' => (string)($c['contract_expiry'] ?? '') ?: null,
                'source' => 'manual', 'note' => (string)($c['maint_note'] ?? '') ?: null,
            ];
        }
        // The office may also say "on demand" explicitly, which stops the
        // derived sources from overriding a deliberate decision.
        if (strtoupper($manual) === self::ON_DEMAND) {
            return $none + ['note' => (string)($c['maint_note'] ?? '') ?: null];
        }

        // 2. A live subscription — the one the customer is actually paying.
        $stmt = Db::pdo()->prepare(
            "SELECT id, description, amount_cents, currency, status
               FROM payment_contracts
              WHERE contact_id = ? AND kind = 'subscription' AND status IN ('active','past_due')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$contactId]);
        if ($pc = $stmt->fetch()) {
            return [
                'type' => self::SUBSCRIPTION, 'label' => (string)$pc['description'],
                'fee_cents' => (int)$pc['amount_cents'],
                'currency' => (string)($pc['currency'] ?: 'EUR'),
                'period' => 'monthly', 'expires_at' => null,
                'source' => 'smallpay', 'note' => null,
            ];
        }

        // 3. The gestionale's contract, while it still runs. No fee here — the
        //    export carries the expiry date and not the amount.
        $expiry = trim((string)($c['contract_expiry'] ?? ''));
        if ($expiry !== '' && $expiry >= date('Y-m-d')) {
            return [
                'type' => self::GESTIONALE, 'label' => '', 'fee_cents' => null,
                'currency' => 'EUR', 'period' => null, 'expires_at' => $expiry,
                'source' => 'gestionale', 'note' => null,
            ];
        }

        return $none;
    }

    public static function isOnDemand(int $contactId): bool
    {
        return self::forContact($contactId)['type'] === self::ON_DEMAND;
    }

    /**
     * When somebody last actually went out to this customer: the newest visit
     * marked done, or the newest installation report. Not a booked future one —
     * the clock runs from work carried out.
     */
    public static function lastServiceAt(int $contactId): ?string
    {
        $stmt = Db::pdo()->prepare(
            'SELECT MAX(d) FROM (
                 SELECT MAX(a.starts_at) AS d FROM appointments a
                  WHERE a.contact_id = ? AND a.kind = "intervention"
                    AND a.status = "done" AND a.starts_at IS NOT NULL
                 UNION ALL
                 SELECT MAX(r.finished_at) AS d FROM install_reports r
                  WHERE r.contact_id = ? AND r.finished_at IS NOT NULL
             ) x'
        );
        $stmt->execute([$contactId, $contactId]);
        $d = $stmt->fetchColumn();
        return $d ? (string)$d : null;
    }

    /** The chases already sent to this customer, newest first. */
    public static function followUps(int $contactId, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = Db::pdo()->prepare(
            "SELECT * FROM maintenance_followups WHERE contact_id = ?
              ORDER BY sent_at DESC LIMIT $limit"
        );
        $stmt->execute([$contactId]);
        return $stmt->fetchAll() ?: [];
    }

    // ---- the quarterly chase ----------------------------------------------------

    /**
     * Customers due a nudge: on demand, reachable, with a visit on record, and
     * nothing heard from them since the interval ran out.
     *
     * Deliberately requires a PAST VISIT. The registry holds roughly ten
     * thousand imported customers and most have never had one; anchoring on the
     * import date instead would have the first run write to all of them.
     *
     * @return array<int,array>
     */
    public static function due(int $limit = self::BATCH): array
    {
        $months = self::months();
        $limit  = max(1, min(200, $limit));

        // The anchor is the later of "last visit" and "last chase", so the
        // cadence repeats on its own and resets the moment somebody goes out.
        $sql =
            "SELECT c.id, c.name, c.company, c.phone, c.email, c.lang, c.vat_number,
                    c.assigned_to, c.maint_type, c.contract_expiry,
                    v.last_service, f.last_chase,
                    GREATEST(COALESCE(v.last_service, '1000-01-01'),
                             COALESCE(f.last_chase,   '1000-01-01')) AS anchor
               FROM contacts c
               JOIN (
                    SELECT contact_id, MAX(d) AS last_service FROM (
                        SELECT contact_id, MAX(starts_at) AS d FROM appointments
                         WHERE kind = 'intervention' AND status = 'done' AND starts_at IS NOT NULL
                         GROUP BY contact_id
                        UNION ALL
                        SELECT contact_id, MAX(finished_at) AS d FROM install_reports
                         WHERE finished_at IS NOT NULL GROUP BY contact_id
                    ) u GROUP BY contact_id
               ) v ON v.contact_id = c.id
               LEFT JOIN (
                    SELECT contact_id, MAX(sent_at) AS last_chase
                      FROM maintenance_followups GROUP BY contact_id
               ) f ON f.contact_id = c.id
              WHERE c.is_customer = 1
                AND (COALESCE(c.phone,'') <> '' OR COALESCE(c.email,'') <> '')
                -- no live subscription…
                AND NOT EXISTS (SELECT 1 FROM payment_contracts p
                                 WHERE p.contact_id = c.id AND p.kind = 'subscription'
                                   AND p.status IN ('active','past_due'))
                -- …no gestionale contract still running…
                AND (c.contract_expiry IS NULL OR c.contract_expiry < CURDATE())
                -- …and the office has not typed one in
                AND (c.maint_type IS NULL OR c.maint_type = '' OR UPPER(c.maint_type) = '" . self::ON_DEMAND . "')
              HAVING anchor <= (NOW() - INTERVAL $months MONTH)
              ORDER BY anchor ASC
              LIMIT $limit";

        return Db::pdo()->query($sql)->fetchAll() ?: [];
    }

    /**
     * The cron pass. Off until switched on: it writes to real customers.
     *
     * @return int how many were chased
     */
    public static function runFollowUps(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $msgCustomer = (bool)Config::get('maintenance.message_customer', true);
        $makeTask    = (bool)Config::get('maintenance.create_task', true);

        // How much of today's allowance is left. Counted from the rows actually
        // written, so a restarted cron or a second server cannot spend it twice.
        $maxDay = max(1, min(2000, (int)Config::get('maintenance.max_per_day', self::DEFAULT_MAX_PER_DAY)));
        $today  = (int)Db::pdo()->query(
            'SELECT COUNT(*) FROM maintenance_followups WHERE sent_at >= CURDATE()'
        )->fetchColumn();
        $room = $maxDay - $today;
        if ($room <= 0) {
            return 0;
        }

        $n = 0;
        foreach (self::due(min(self::BATCH, $room)) as $c) {
            if (self::chase($c, $msgCustomer, $makeTask)) {
                $n++;
            }
        }
        if ($n > 0) {
            Log::write('crm', 'maintenance_followups_sent', null, null, ['count' => $n]);
        }
        return $n;
    }

    /** One customer: write to them, raise the task, record that it happened. */
    private static function chase(array $c, bool $msgCustomer, bool $makeTask): bool
    {
        $contactId = (int)$c['id'];
        $phone = trim((string)($c['phone'] ?? ''));
        $email = trim((string)($c['email'] ?? ''));
        $channel = $phone !== '' && $email !== '' ? 'both' : ($phone !== '' ? 'whatsapp' : 'email');
        $lang = Templates::lang($c['lang'] ?? null);

        $taskId = null;
        if ($makeTask) {
            // The operator's copy. Assigned to whoever holds the customer, or
            // left unassigned for the office to pick up.
            $taskId = Tasks::create([
                'title'        => 'Manutenzione a chiamata — ricontattare ' . (string)($c['name'] ?? ''),
                'description'  => "Ultimo intervento: " . (($c['last_service'] ?? '') ?: 'n/d')
                    . ". Il cliente non ha un contratto periodico. Proporre un appuntamento di manutenzione.",
                'assigned_to'  => ((int)($c['assigned_to'] ?? 0)) ?: null,
                'related_type' => 'contact',
                'related_id'   => $contactId,
                'due_at'       => date('Y-m-d H:i:s', strtotime('+3 days')),
                'priority'     => 'normal',
            ]);
        }

        $messaged = false;
        if ($msgCustomer && $channel !== '') {
            (new Scheduler())->enqueue([
                'entity_type'    => 'contact',
                'entity_id'      => $contactId,
                'rule_key'       => 'maintenance_due',
                'recipient_type' => 'customer',
                'channel'        => $channel,
                'due_at'         => date('Y-m-d H:i:s'),
                'lang'           => $lang,
                'payload'        => [
                    'link'   => self::bookingLink($c),
                    'months' => (string)self::months(),
                ],
                // The date makes it unique per chase: the same customer three
                // months later is a new message, not a duplicate of this one.
                'dedupe_key'     => 'maint:' . $contactId . ':' . date('Y-m-d'),
            ], false); // queue only — this runs in a cron batch, not a web request
            $messaged = true;
        }

        Db::pdo()->prepare(
            'INSERT INTO maintenance_followups (contact_id, last_service_at, channel, task_id, messaged)
             VALUES (?,?,?,?,?)'
        )->execute([
            $contactId,
            ($c['last_service'] ?? null) ?: null,
            $channel, $taskId, $messaged ? 1 : 0,
        ]);

        Log::write('crm', 'maintenance_followup', 'contact', $contactId, [
            'last_service' => $c['last_service'] ?? null,
            'task_id' => $taskId, 'messaged' => $messaged,
        ]);
        return true;
    }

    // ---- the contract is about to run out -----------------------------------------

    public static function expiryEnabled(): bool
    {
        return (bool)Config::get('maintenance.expiry_enabled', false);
    }

    /**
     * How many days before the expiry the customer hears about it. A list, so
     * the office can warn once early and again close to the date: "30,7" sends
     * a month out and again the week before.
     *
     * @return array<int,int> descending, deduplicated, 1…365
     */
    public static function expiryDays(): array
    {
        $raw = Config::get('maintenance.expiry_days', self::DEFAULT_EXPIRY_DAYS);
        $raw = is_array($raw) ? $raw : (preg_split('/[^0-9]+/', (string)$raw) ?: []);
        $days = [];
        foreach ($raw as $d) {
            $d = (int)$d;
            if ($d >= 1 && $d <= 365) {
                $days[$d] = $d;
            }
        }
        rsort($days);
        return $days ?: self::DEFAULT_EXPIRY_DAYS;
    }

    /**
     * Customers whose contract runs out in exactly $days days.
     *
     * "Exactly" is what makes each step its own message: a customer 30 days out
     * is warned today and is not warned again tomorrow at 29, and the second
     * step catches them again at 7. The dedupe key carries the date and the
     * step, so a cron pass that runs twice, or a server that was down at nine
     * and catches up at half past, cannot send the same warning twice.
     *
     * Skips the ones the contract does not really cover: a live SmallPay
     * subscription (they pay monthly, the gestionale's date is stale) and a
     * record the office has typed A CHIAMATA on (a deliberate "no contract"
     * beats a leftover date).
     *
     * Also skips anyone already warned at this step, by the same dedupe key the
     * queue is keyed on. The UNIQUE key is what actually stops a second message,
     * but reading it here is what makes the day's allowance and the count the
     * scheduler logs mean what they say: without it a pass that queued nothing
     * still reported work and still spent the cap.
     *
     * @return array<int,array>
     */
    public static function expiringIn(int $days, int $limit = self::BATCH): array
    {
        $days  = max(1, min(365, $days));
        $limit = max(1, min(200, $limit));
        $sql =
            "SELECT c.id, c.name, c.company, c.phone, c.email, c.lang, c.vat_number,
                    c.contract_expiry, c.maint_type
               FROM contacts c
              WHERE c.is_customer = 1
                AND c.contract_expiry = DATE_ADD(CURDATE(), INTERVAL $days DAY)
                AND (COALESCE(c.phone,'') <> '' OR COALESCE(c.email,'') <> '')
                AND NOT EXISTS (SELECT 1 FROM payment_contracts p
                                 WHERE p.contact_id = c.id AND p.kind = 'subscription'
                                   AND p.status IN ('active','past_due'))
                AND (c.maint_type IS NULL OR c.maint_type = ''
                     OR UPPER(c.maint_type) <> '" . self::ON_DEMAND . "')
                AND NOT EXISTS (SELECT 1 FROM reminders r
                                 WHERE r.dedupe_key = CONCAT('maintexp:', c.id, ':',
                                                             c.contract_expiry, ':', $days))
              ORDER BY c.id
              LIMIT $limit";
        return Db::pdo()->query($sql)->fetchAll() ?: [];
    }

    /**
     * The cron pass for expiring contracts. Does nothing until the configured
     * hour, then warns whoever is due today at each configured step.
     *
     * Capped per day for the same reason the chase is: contracts do not expire
     * evenly. Ninety-seven of the customers in the registry share a single
     * expiry date, so one step landing on that date is one day's whole run.
     *
     * Only QUEUES — the scheduler's own runDue() delivers on the next tick, at
     * the WhatsApp gateway's pace rather than in a sleeping loop here.
     *
     * @return int how many customers were warned
     */
    public static function runExpiryNotices(): int
    {
        if (!self::expiryEnabled() || !self::pastTime((string)Config::get('maintenance.expiry_at', self::DEFAULT_EXPIRY_AT))) {
            return 0;
        }
        $maxDay = max(1, min(2000, (int)Config::get('maintenance.expiry_max_per_day', self::DEFAULT_MAX_PER_DAY)));
        $today  = (int)Db::pdo()->query(
            "SELECT COUNT(*) FROM reminders
              WHERE rule_key = 'maintenance_expiry' AND created_at >= CURDATE()"
        )->fetchColumn();
        $room = $maxDay - $today;
        if ($room <= 0) {
            return 0;
        }

        $sched = new Scheduler();
        $n = 0;
        foreach (self::expiryDays() as $days) {
            if ($room <= 0) {
                break;
            }
            foreach (self::expiringIn($days, min(self::BATCH, $room)) as $c) {
                $contactId = (int)$c['id'];
                $expiry = (string)$c['contract_expiry'];
                $phone = trim((string)($c['phone'] ?? ''));
                $email = trim((string)($c['email'] ?? ''));
                $sched->enqueue([
                    'entity_type'    => 'contact',
                    'entity_id'      => $contactId,
                    'rule_key'       => 'maintenance_expiry',
                    'recipient_type' => 'customer',
                    'channel'        => $phone !== '' && $email !== '' ? 'both' : ($phone !== '' ? 'whatsapp' : 'email'),
                    'due_at'         => date('Y-m-d H:i:s'),
                    'lang'           => Templates::lang($c['lang'] ?? null),
                    'payload'        => [
                        'expiry' => date('d/m/Y', strtotime($expiry)),
                        'days'   => (string)$days,
                        'link'   => self::bookingLink($c),
                    ],
                    // Contact + the date it runs out + which step: one warning
                    // per customer per step, whatever the cron does.
                    'dedupe_key'     => 'maintexp:' . $contactId . ':' . $expiry . ':' . $days,
                ], false); // queue only — this is a cron batch, not a web request
                $room--;
                $n++;
            }
        }
        if ($n > 0) {
            Log::write('crm', 'maintenance_expiry_notices', null, null, ['count' => $n]);
        }
        return $n;
    }

    /** True once today has passed "HH:MM". An unreadable time never fires. */
    private static function pastTime(string $hhmm): bool
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($hhmm), $m)) {
            return false;
        }
        return time() >= mktime((int)$m[1], (int)$m[2], 0);
    }

    /**
     * Where the customer books. The public assistance form already opens with a
     * VAT number and reaches every technician, so it is the booking page —
     * there is no second front door to build or to keep working.
     */
    private static function bookingLink(array $c): string
    {
        $base = rtrim(Config::appBaseUrl(), '/') . '/support.php';
        $vat  = trim((string)($c['vat_number'] ?? ''));
        return $vat !== '' ? $base . '?vat_number=' . rawurlencode($vat) : $base;
    }

    /** For the office: how many customers are on demand, and how many are due. */
    public static function counters(): array
    {
        $onDemand = (int)Db::pdo()->query(
            "SELECT COUNT(*) FROM contacts c
              WHERE c.is_customer = 1
                AND NOT EXISTS (SELECT 1 FROM payment_contracts p
                                 WHERE p.contact_id = c.id AND p.kind = 'subscription'
                                   AND p.status IN ('active','past_due'))
                AND (c.contract_expiry IS NULL OR c.contract_expiry < CURDATE())
                AND (c.maint_type IS NULL OR c.maint_type = '' OR UPPER(c.maint_type) = '" . self::ON_DEMAND . "')"
        )->fetchColumn();
        return ['on_demand' => $onDemand, 'due' => count(self::due(200))];
    }
}
