<?php
declare(strict_types=1);

namespace Glue\Pay;

use Glue\Config;
use Glue\Crm\Activities;
use Glue\Db;
use Glue\Event\Log;
use Glue\Reminder\Scheduler;
use Glue\Settings;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Payment contracts: the CRM's side of a SmallPay position.
 *
 * A contract is opened here, filed with SmallPay, and from then on SmallPay is
 * the authority on it — what has been collected, what bounced, what is next.
 * This class exists to keep a local answer to that question current and to turn
 * changes in it into the things the business actually does: message the
 * customer, tell the seller, stop the service.
 *
 * Two things keep the read model honest, and both are needed:
 *   - SmallPay POSTs the status callback on every change (fast, but a callback
 *     that never arrives leaves a defaulting customer looking paid);
 *   - syncIfDue() pulls every live contract on a slow cadence (late, but it
 *     cannot silently not-happen).
 * Both land in applyPayload(), which is written to be idempotent so it does not
 * matter which gets there first or how often.
 */
final class Contracts
{
    /** Reminder rule keys — copy lives in lang/en.php + lang/it.php. */
    public const RULE_LINK        = 'pay_link';
    public const RULE_ACTIVE      = 'pay_active';
    public const RULE_FAILED      = 'pay_failed';
    public const RULE_FAILED_AGENT = 'pay_failed_agent';

    /** SmallPay position status -> ours. */
    private const POSITION_STATUS = [
        'IN ATTIVAZIONE' => 'awaiting_customer',
        'ATTIVO'         => 'active',
        'NON ATTIVO'     => 'failed',
    ];

    /** SmallPay rate status -> ours. */
    private const RATE_STATUS = [
        'DA PAGARE'       => 'pending',
        'IN ELABORAZIONE' => 'processing',
        'PAGATO'          => 'paid',
        'INSOLUTO'        => 'failed',
        'ELIMINATO'       => 'deleted',
    ];

    // ---- opening a contract -------------------------------------------------

    /**
     * Open a contract and file it with SmallPay. Returns the stored row,
     * including checkout_url — the cashier page to put in front of the customer.
     *
     * $in: kind, contact_id, deal_id, lead_id, description, amount_cents,
     *      first_amount_cents, total_cycles, customer_name/phone/email, lang.
     *
     * The row is written BEFORE the API call so the reference we sign with is
     * already durable: if the call times out having actually landed, the retry
     * reuses the same paymentId, SmallPay answers Conflict, and adopt() recovers
     * the position instead of opening a second one against the same customer.
     */
    public static function open(array $in, ?int $userId = null): array
    {
        if (!SmallPay::enabled()) {
            throw new RuntimeException('SmallPay is switched off or not configured (Settings → SmallPay)');
        }
        $kind = in_array($in['kind'] ?? '', ['subscription', 'installments', 'one_off'], true)
            ? (string)$in['kind'] : 'subscription';

        $amount = max(0, (int)($in['amount_cents'] ?? 0));
        $first  = max(0, (int)($in['first_amount_cents'] ?? 0));
        $cycles = max(0, (int)($in['total_cycles'] ?? 0));

        if ($kind === 'one_off') {
            // A single payment is a position with nothing recurring: the whole
            // sale is the first transaction.
            $first  = $amount ?: $first;
            $amount = 0;
            $cycles = 0;
        }
        if ($kind === 'installments' && $cycles < 1) {
            throw new RuntimeException('An instalment plan needs a number of rates');
        }
        if ($amount <= 0 && $first <= 0) {
            throw new RuntimeException('A contract needs an amount');
        }

        $name  = trim((string)($in['customer_name'] ?? ''));
        $desc  = trim((string)($in['description'] ?? '')) ?: $name;
        if ($desc === '') {
            throw new RuntimeException('A contract needs a description — the customer sees it on the cashier page');
        }

        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO payment_contracts
                (kind, reference, contact_id, deal_id, lead_id, assigned_to,
                 customer_name, customer_phone, customer_email, lang, description,
                 currency, amount_cents, first_amount_cents, total_cycles, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $kind,
            // Placeholder: the real reference embeds the row id, which does not
            // exist yet. It has to be unique even so — reference is a UNIQUE key
            // and two agents can be saving at the same instant.
            'tmp-' . bin2hex(random_bytes(12)),
            self::nullableId($in['contact_id'] ?? null),
            self::nullableId($in['deal_id'] ?? null),
            self::nullableId($in['lead_id'] ?? null),
            self::nullableId($in['assigned_to'] ?? null),
            $name ?: null,
            trim((string)($in['customer_phone'] ?? '')) ?: null,
            trim((string)($in['customer_email'] ?? '')) ?: null,
            substr((string)($in['lang'] ?? Config::get('app.default_lang', 'it')), 0, 2),
            $desc,
            (string)Config::get('crm.currency', 'EUR'),
            $amount, $first, $cycles,
            'draft',
            $userId,
        ]);
        $id = (int)$pdo->lastInsertId();

        // The reference embeds the row id, so a position in SmallPay's portal can
        // always be traced back to a contract here by eye.
        $reference = self::reference($id);
        $pdo->prepare('UPDATE payment_contracts SET reference = ? WHERE id = ?')->execute([$reference, $id]);

        $c = self::find($id);
        if ($c === null) {
            throw new RuntimeException('Contract vanished immediately after being created');
        }

        try {
            $res = self::file($c);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE payment_contracts SET last_error = ? WHERE id = ?')
                ->execute([$e->getMessage(), $id]);
            Log::write('pay', 'contract_file_failed', 'payment_contract', $id, ['error' => $e->getMessage()]);
            throw $e;
        }

        Log::write('pay', 'contract_opened', 'payment_contract', $id, [
            'reference' => $reference, 'kind' => $kind,
            'amount_cents' => $amount, 'first_amount_cents' => $first, 'cycles' => $cycles,
        ]);
        if ($c['deal_id']) {
            Activities::add('deal', (int)$c['deal_id'], 'system',
                'Payment contract opened: ' . $desc . ' — ' . self::money($c), $userId);
        }
        return $res;
    }

    /**
     * Hand the position to SmallPay and store what comes back. Split out of
     * open() because a Conflict has to be recoverable, not fatal.
     */
    private static function file(array $c): array
    {
        $api    = new SmallPay();
        $ref    = (string)$c['reference'];
        $kind   = (string)$c['kind'];
        $amount = (int)$c['amount_cents'];
        $first  = (int)$c['first_amount_cents'];
        $cycles = (int)$c['total_cycles'];

        // What SmallPay calls totalAmount is the whole sale: the up-front part
        // plus every recurring quota. For an open-ended FlexPay contract there
        // is no "every", so we send one quota — SmallPay repeats that figure for
        // as long as the mandate lives. See docs/smallpay.md: this is the one
        // number to re-check against a real merchant account, because the spec
        // does not spell out the perpetual case.
        $total = $kind === 'subscription'
            ? $first + $amount
            : $first + $amount * $cycles;

        [$firstName, $lastName] = self::splitName((string)($c['customer_name'] ?? ''));

        try {
            $res = $api->createPosition($ref, [
                'payer' => [
                    'firstName'    => $firstName,
                    'lastName'     => $lastName,
                    'phoneNumber'  => (string)($c['customer_phone'] ?? ''),
                    'eMailAddress' => (string)($c['customer_email'] ?? ''),
                ],
                'totalAmount'        => $total,
                'firstPaymentAmount' => $first,
                'totalRecurrences'   => $kind === 'subscription' ? 0 : $cycles,
                'flexPay'            => $kind === 'subscription',
                'description'        => (string)$c['description'],
                'redirectUrl'        => self::returnUrl($ref),
                'callbackUrl'        => self::callbackUrl(),
                'modifyInstallments' => (bool)Config::get('smallpay.modify_installments', true),
            ]);
        } catch (Throwable $e) {
            // "Payment number {} for domain {} already exists" — we filed this
            // position on an earlier attempt whose answer we never saw (a timeout
            // that in fact landed). Adopt the position rather than opening a
            // second one against the same customer.
            //
            // The row has to leave 'draft' first: sync() refuses a draft on the
            // grounds that there is nothing upstream to ask about, which is true
            // of every draft except this one.
            if (stripos($e->getMessage(), 'already exists') !== false) {
                Db::pdo()->prepare(
                    "UPDATE payment_contracts SET status = 'awaiting_customer' WHERE id = ? AND status = 'draft'"
                )->execute([(int)$c['id']]);
                Log::write('pay', 'contract_adopted', 'payment_contract', (int)$c['id'],
                    ['reference' => $ref]);
                return self::sync((int)$c['id']);
            }
            throw $e;
        }

        Db::pdo()->prepare(
            "UPDATE payment_contracts
                SET operation_id = ?, checkout_url = ?, status = 'awaiting_customer',
                    last_error = NULL, last_sync_at = NOW()
              WHERE id = ?"
        )->execute([
            (string)($res['operationId'] ?? '') ?: null,
            (string)($res['paymentUrl'] ?? '') ?: null,
            (int)$c['id'],
        ]);

        return self::find((int)$c['id']) ?? $c;
    }

    // ---- applying SmallPay's answer ----------------------------------------

    /**
     * Fold a §3.2 retrieve answer or a §3.4 callback — they carry the same
     * shape — into the contract and its rates.
     *
     * Idempotent by construction: rates are upserted on their installmentId and
     * every roll-up is recomputed from the rate rows, never incremented. Replay
     * the same payload ten times and the row lands in the same place.
     *
     * Returns a small summary, including the rates that newly went unpaid, so
     * the caller can decide whether anyone needs telling.
     */
    public static function applyPayload(array $c, array $body, string $origin = 'sync'): array
    {
        $id  = (int)$c['id'];
        $pdo = Db::pdo();

        $before = [
            'status'  => (string)$c['status'],
            'failed'  => self::failedChargeIds($id),
            'paid'    => (int)$c['cycles_paid'],
        ];

        $rates = is_array($body['installments'] ?? null) ? $body['installments'] : [];
        $seq   = 0;
        $seen  = [];
        $up = $pdo->prepare(
            'INSERT INTO payment_charges
                (contract_id, external_id, seq, amount_cents, currency, status, due_date, paid_date, paid_in_cash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                seq = VALUES(seq), amount_cents = VALUES(amount_cents), status = VALUES(status),
                due_date = VALUES(due_date), paid_date = VALUES(paid_date), paid_in_cash = VALUES(paid_in_cash)'
        );
        foreach ($rates as $r) {
            if (!is_array($r)) {
                continue;
            }
            $extId = trim((string)($r['installmentId'] ?? ''));
            if ($extId === '') {
                continue;
            }
            $seen[] = $extId;
            $up->execute([
                $id,
                $extId,
                ++$seq,
                // Rates come back as euros with decimals here, unlike the cents
                // the create call takes. Round on the way in so 12.34 does not
                // become 1233 through float truncation.
                (int)round(((float)($r['amount'] ?? 0)) * 100),
                (string)$c['currency'],
                self::RATE_STATUS[strtoupper(trim((string)($r['transactionStatus'] ?? '')))] ?? 'pending',
                self::date((string)($r['payableBy'] ?? '')),
                self::date((string)($r['transactionDate'] ?? '')),
                !empty($r['paidInCash']) ? 1 : 0,
            ]);
        }

        // A rate SmallPay has stopped reporting was withdrawn upstream. Mark it
        // deleted rather than dropping the row: an agent who saw it last week
        // should be able to see what became of it.
        if ($seen) {
            $in = implode(',', array_fill(0, count($seen), '?'));
            $pdo->prepare(
                "UPDATE payment_charges SET status = 'deleted'
                  WHERE contract_id = ? AND status NOT IN ('paid','deleted') AND external_id NOT IN ($in)"
            )->execute(array_merge([$id], $seen));
        }

        // Roll-ups, recomputed from the rates rather than accumulated.
        $agg = $pdo->prepare(
            "SELECT
                SUM(status = 'paid')                                        AS paid_n,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_cents END), 0) AS paid_cents,
                SUM(status = 'failed')                                      AS failed_n,
                SUM(status IN ('pending','processing'))                     AS open_n,
                MIN(CASE WHEN status IN ('pending','processing') THEN due_date END) AS next_due,
                MAX(CASE WHEN status = 'paid' THEN paid_date END)           AS last_paid
             FROM payment_charges WHERE contract_id = ?"
        );
        $agg->execute([$id]);
        $a = $agg->fetch() ?: [];

        $status = self::rollUpStatus($c, (string)($body['status'] ?? ''), $a);

        $pdo->prepare(
            'UPDATE payment_contracts
                SET status = ?, cycles_paid = ?, paid_cents = ?, failed_cycles = ?,
                    next_charge_date = ?, last_paid_at = ?, contract_url = COALESCE(?, contract_url),
                    operation_id = COALESCE(?, operation_id),
                    activated_at = CASE WHEN ? = 1 AND activated_at IS NULL THEN NOW() ELSE activated_at END,
                    last_sync_at = NOW(), last_error = NULL
              WHERE id = ?'
        )->execute([
            $status,
            (int)($a['paid_n'] ?? 0),
            (int)($a['paid_cents'] ?? 0),
            (int)($a['failed_n'] ?? 0),
            $a['next_due'] ?? null,
            !empty($a['last_paid']) ? $a['last_paid'] . ' 00:00:00' : null,
            (string)($body['urlContract'] ?? '') ?: null,
            (string)($body['operationId'] ?? '') ?: null,
            // Stamp the activation the first time the mandate goes live, and
            // never again — a later re-sync must not move the date.
            $status === 'active' ? 1 : 0,
            $id,
        ]);

        $after = self::find($id) ?? $c;
        $newlyFailed = array_values(array_diff(self::failedChargeIds($id), $before['failed']));

        if ($before['status'] !== $status) {
            Log::write('pay', 'contract_status', 'payment_contract', $id, [
                'from' => $before['status'], 'to' => $status, 'origin' => $origin,
            ]);
            self::onStatusChange($after, $before['status'], $status);
        }
        if ($newlyFailed) {
            self::onChargesFailed($after, $newlyFailed);
        }

        return [
            'status'       => $status,
            'was'          => $before['status'],
            'rates'        => count($seen),
            'newly_failed' => count($newlyFailed),
            'newly_paid'   => max(0, (int)($a['paid_n'] ?? 0) - $before['paid']),
        ];
    }

    /**
     * Our status for the contract, given SmallPay's position status and the
     * rate roll-up.
     *
     * SmallPay only ever says IN ATTIVAZIONE / ATTIVO / NON ATTIVO — it speaks
     * about the FIRST payment, not about the months since. The two states the
     * business actually acts on are ours to derive: past_due (a rate bounced and
     * has not been recovered — the trigger for suspending service) and completed
     * (a fixed plan that has run its course).
     */
    private static function rollUpStatus(array $c, string $positionStatus, array $agg): string
    {
        $current = (string)$c['status'];
        // A deliberate cancellation is final; a stale answer must not revive it.
        if ($current === 'cancelled') {
            return 'cancelled';
        }
        $mapped = self::POSITION_STATUS[strtoupper(trim($positionStatus))] ?? null;
        if ($mapped === null) {
            // No position status in this payload (some callbacks carry only
            // rates). Keep what we had and let the rates speak.
            $mapped = in_array($current, ['draft', 'awaiting_customer'], true) ? $current : 'active';
        }
        if ($mapped !== 'active') {
            return $mapped;
        }
        if ((int)($agg['failed_n'] ?? 0) > 0) {
            return 'past_due';
        }
        // Fixed-length plan with nothing left to collect has finished. An
        // open-ended support contract never reaches this: there is always a next
        // month, so open_n only hits zero for a plan with a last rate.
        if ((string)$c['kind'] !== 'subscription'
            && (int)($agg['open_n'] ?? 0) === 0
            && (int)($agg['paid_n'] ?? 0) > 0) {
            return 'completed';
        }
        return 'active';
    }

    // ---- reacting to change -------------------------------------------------

    /** The customer's first payment went through, or the contract ended. */
    private static function onStatusChange(array $c, string $from, string $to): void
    {
        if ($to === 'active' && in_array($from, ['draft', 'awaiting_customer', 'failed'], true)) {
            self::notifyCustomer($c, self::RULE_ACTIVE, [
                'description' => (string)$c['description'],
                'amount'      => self::money($c),
                'every'       => self::cadenceText($c),
            ]);
            self::activity($c, 'Payment contract active — first payment collected');
        }
        if ($to === 'failed') {
            self::activity($c, 'SmallPay refused the first payment');
        }
    }

    /**
     * One or more rates came back INSOLUTO. The customer is told so they can fix
     * the card, and the seller is told because this is the moment the business
     * decides whether the service keeps running.
     */
    private static function onChargesFailed(array $c, array $chargeIds): void
    {
        $n = count($chargeIds);
        Log::write('pay', 'charges_failed', 'payment_contract', (int)$c['id'], ['installments' => $chargeIds]);
        self::activity($c, $n === 1 ? 'A payment came back unpaid' : "$n payments came back unpaid");

        if ((bool)Config::get('smallpay.notify_customer_on_failure', true)) {
            self::notifyCustomer($c, self::RULE_FAILED, [
                'description' => (string)$c['description'],
                'amount'      => self::money($c),
                'count'       => (string)$n,
            ]);
        }
        self::notifyAgent($c, self::RULE_FAILED_AGENT, [
            'description'   => (string)$c['description'],
            'customer_name' => (string)($c['customer_name'] ?? ''),
            'amount'        => self::money($c),
            'count'         => (string)$n,
        ]);
    }

    // ---- syncing ------------------------------------------------------------

    /** Pull one contract's current state from SmallPay and fold it in. */
    public static function sync(int $id): array
    {
        $c = self::find($id);
        if ($c === null) {
            throw new RuntimeException("No such contract: $id");
        }
        if ((string)$c['status'] === 'draft') {
            return $c; // never filed — there is nothing upstream to ask about
        }
        try {
            $body = (new SmallPay())->retrievePosition((string)$c['reference']);
        } catch (Throwable $e) {
            Db::pdo()->prepare('UPDATE payment_contracts SET last_error = ?, last_sync_at = NOW() WHERE id = ?')
                ->execute([$e->getMessage(), $id]);
            throw $e;
        }
        self::applyPayload($c, $body, 'sync');
        return self::find($id) ?? $c;
    }

    /**
     * Cron pass: refresh the contracts that can still change, oldest first, a
     * bounded number per run.
     *
     * Self-throttling on smallpay.last_sync_at, claimed BEFORE the work so two
     * overlapping runners cannot both decide it is their turn. Never throws:
     * a SmallPay outage must not stop the scheduler getting to the reminders.
     * Returns null when it wasn't due, so the caller can stay quiet.
     */
    public static function syncIfDue(): ?array
    {
        if (!SmallPay::enabled()) {
            return null;
        }
        $everyMin = max(5, (int)Config::get('smallpay.sync_minutes', 60));
        $last = (string)Settings::get('smallpay.last_sync_at', '');
        if ($last !== '' && strtotime($last) > time() - $everyMin * 60) {
            return null;
        }
        Settings::set('smallpay.last_sync_at', date('Y-m-d H:i:s'));

        $limit = max(1, (int)Config::get('smallpay.sync_max_per_run', 25));
        try {
            $rows = Db::pdo()->query(
                "SELECT id FROM payment_contracts
                  WHERE status IN ('awaiting_customer','active','past_due','failed')
                  ORDER BY last_sync_at IS NULL DESC, last_sync_at ASC
                  LIMIT $limit"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            // Table missing (migration not run yet) or DB hiccup. This is the
            // scheduler's last job; it must not cost it the reminders.
            Log::write('pay', 'sync_unavailable', null, null, ['error' => $e->getMessage()]);
            return null;
        }

        $out = ['checked' => 0, 'changed' => 0, 'errors' => 0];
        foreach ($rows as $id) {
            try {
                $c = self::find((int)$id);
                if ($c === null) {
                    continue;
                }
                $before = (string)$c['status'];
                $body = (new SmallPay())->retrievePosition((string)$c['reference']);
                $r = self::applyPayload($c, $body, 'cron');
                $out['checked']++;
                if ($r['status'] !== $before || $r['newly_failed'] > 0 || $r['newly_paid'] > 0) {
                    $out['changed']++;
                }
            } catch (Throwable $e) {
                $out['errors']++;
                Db::pdo()->prepare('UPDATE payment_contracts SET last_error = ?, last_sync_at = NOW() WHERE id = ?')
                    ->execute([$e->getMessage(), (int)$id]);
                Log::write('pay', 'sync_failed', 'payment_contract', (int)$id, ['error' => $e->getMessage()]);
            }
        }
        return $out;
    }

    // ---- operations an agent performs --------------------------------------

    /**
     * End an open-ended contract: SmallPay stops charging the card from now on.
     * Rates already collected are untouched — this is a cancellation, not a refund.
     */
    public static function cancel(int $id, ?int $userId = null): array
    {
        $c = self::mustFind($id);
        if ((string)$c['status'] === 'cancelled') {
            return $c;
        }
        if ((string)$c['kind'] === 'subscription' && (string)$c['status'] !== 'draft') {
            (new SmallPay())->unsubscribeFlexPay((string)$c['reference']);
        }
        Db::pdo()->prepare("UPDATE payment_contracts SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?")
            ->execute([$id]);
        // Nothing further to say to this customer about money.
        (new Scheduler())->cancelForEntity('payment_contract', $id);

        Log::write('pay', 'contract_cancelled', 'payment_contract', $id, ['by' => $userId]);
        self::activity($c, 'Payment contract cancelled', $userId);
        return self::find($id) ?? $c;
    }

    /**
     * The first payment was refused: have SmallPay email the customer a fresh
     * cashier link. SmallPay sends it, to the address the position carries.
     */
    public static function regenerateFirstPayment(int $id, ?int $userId = null): array
    {
        $c = self::mustFind($id);
        (new SmallPay())->regenerateFirstPayment((string)$c['reference']);
        Log::write('pay', 'first_payment_regenerated', 'payment_contract', $id, ['by' => $userId]);
        self::activity($c, 'A new first-payment link was sent by SmallPay', $userId);
        return self::sync($id);
    }

    /** Retry unpaid rates on the card. */
    public static function relaunch(int $id, array $chargeIds = [], ?int $userId = null): array
    {
        $c = self::mustFind($id);
        $ids = $chargeIds ?: self::failedChargeIds($id);
        if (!$ids) {
            throw new RuntimeException('Nothing to retry — no unpaid rates on this contract');
        }
        $res = (new SmallPay())->relaunchInstallments($ids);
        Log::write('pay', 'installments_relaunched', 'payment_contract', $id,
            ['installments' => $ids, 'by' => $userId] + self::batchCounts($res));
        self::activity($c, count($ids) . ' unpaid payment(s) retried', $userId);
        self::sync($id);
        return $res;
    }

    /** Settle rates at the desk so SmallPay stops charging the card for them. */
    public static function payInCash(int $id, array $chargeIds, ?int $userId = null): array
    {
        $c = self::mustFind($id);
        if (!$chargeIds) {
            throw new RuntimeException('Choose which rates were paid in cash');
        }
        $res = (new SmallPay())->payInCash($chargeIds);
        Log::write('pay', 'installments_paid_in_cash', 'payment_contract', $id,
            ['installments' => $chargeIds, 'by' => $userId] + self::batchCounts($res));
        self::activity($c, count($chargeIds) . ' payment(s) marked settled in cash', $userId);
        self::sync($id);
        return $res;
    }

    /**
     * Send (or re-send) the cashier link to the customer. Returns the reminder
     * id; the Scheduler does the actual sending, so this respects the WhatsApp
     * pacing like every other message the CRM sends.
     */
    public static function sendLink(int $id, string $channel = 'both', ?int $userId = null): int
    {
        $c = self::mustFind($id);
        $url = trim((string)($c['checkout_url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('This contract has no cashier link yet');
        }
        $rid = self::notifyCustomer($c, self::RULE_LINK, [
            'description' => (string)$c['description'],
            'amount'      => self::money($c),
            'every'       => self::cadenceText($c),
            'link'        => $url,
        ], $channel, true);
        if ($rid > 0) {
            self::activity($c, 'Payment link sent to the customer', $userId);
        }
        return $rid;
    }

    // ---- reading ------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM payment_contracts WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByReference(string $reference): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM payment_contracts WHERE reference = ?');
        $stmt->execute([$reference]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array> rates of one contract, in schedule order */
    public static function charges(int $contractId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM payment_charges WHERE contract_id = ?
             ORDER BY due_date IS NULL, due_date, seq, id'
        );
        $stmt->execute([$contractId]);
        return $stmt->fetchAll();
    }

    /** Contracts for the list page, newest first, with an optional status filter. */
    public static function all(?string $status = null, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = 'SELECT c.*, d.title AS deal_title
                  FROM payment_contracts c
             LEFT JOIN deals d ON d.id = c.deal_id';
        $args = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE c.status = ?';
            $args[] = $status;
        }
        $sql .= " ORDER BY c.id DESC LIMIT $limit";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** Headline numbers for the page: how many are paying, how many are not. */
    public static function summary(): array
    {
        $row = Db::pdo()->query(
            "SELECT
                COUNT(*)                                          AS total,
                SUM(status = 'active')                            AS active,
                SUM(status = 'past_due')                          AS past_due,
                SUM(status = 'awaiting_customer')                 AS awaiting,
                COALESCE(SUM(CASE WHEN status IN ('active','past_due') AND kind = 'subscription'
                                  THEN amount_cents END), 0)      AS mrr_cents,
                COALESCE(SUM(paid_cents), 0)                      AS collected_cents
             FROM payment_contracts"
        )->fetch() ?: [];
        return array_map('intval', $row);
    }

    /** SmallPay installmentIds of this contract's unpaid rates. */
    public static function failedChargeIds(int $contractId): array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT external_id FROM payment_charges WHERE contract_id = ? AND status = 'failed' ORDER BY due_date"
        );
        $stmt->execute([$contractId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    // ---- helpers ------------------------------------------------------------

    private static function mustFind(int $id): array
    {
        $c = self::find($id);
        if ($c === null) {
            throw new RuntimeException("No such contract: $id");
        }
        return $c;
    }

    /**
     * Our paymentId. Unique within the SmallPay domain and never reused: it
     * carries the row id so a position in SmallPay's portal traces straight back
     * here, plus random bytes so a deleted-and-recreated row can never collide
     * with a position SmallPay still holds under the old number.
     */
    private static function reference(int $id): string
    {
        return sprintf('%s%06d-%s',
            (string)Config::get('smallpay.reference_prefix', 'CRM'),
            $id,
            bin2hex(random_bytes(3))
        );
    }

    /** SmallPay wants a first and last name; the CRM stores one field. */
    private static function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/', ' ', $full) ?? '');
        if ($full === '') {
            return ['', ''];
        }
        $pos = strrpos($full, ' ');
        return $pos === false ? [$full, $full] : [substr($full, 0, $pos), substr($full, $pos + 1)];
    }

    /** SmallPay dates are yyyymmdd; ours are DATEs. */
    private static function date(string $ymd): ?string
    {
        $ymd = preg_replace('/\D+/', '', $ymd) ?? '';
        if (strlen($ymd) !== 8) {
            return null;
        }
        $y = (int)substr($ymd, 0, 4);
        $m = (int)substr($ymd, 4, 2);
        $d = (int)substr($ymd, 6, 2);
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    private static function nullableId(mixed $v): ?int
    {
        $n = (int)$v;
        return $n > 0 ? $n : null;
    }

    /** "EUR 49,00" — the recurring amount, or the one-off total. */
    public static function money(array $c): string
    {
        $cents = (int)$c['amount_cents'] ?: (int)$c['first_amount_cents'];
        return ((string)$c['currency'] ?: 'EUR') . ' ' . number_format($cents / 100, 2, ',', '.');
    }

    /** Human cadence for message copy: "every month", "12 monthly rates", "one payment". */
    public static function cadenceText(array $c, ?string $lang = null): string
    {
        $lang = substr((string)($lang ?? $c['lang'] ?? 'it'), 0, 2);
        $it = $lang === 'it';
        return match ((string)$c['kind']) {
            'subscription' => $it ? 'ogni mese' : 'every month',
            'installments' => $it
                ? ((int)$c['total_cycles'] . ' rate mensili')
                : ((int)$c['total_cycles'] . ' monthly instalments'),
            default        => $it ? 'in un\'unica soluzione' : 'as a single payment',
        };
    }

    private static function batchCounts(array $res): array
    {
        return [
            'submitted'   => (int)($res['totalInstallmentsSubmitted'] ?? 0),
            'processed'   => count((array)($res['installmentsProcessed'] ?? [])),
            'unprocessed' => count((array)($res['installmentsUnprocessed'] ?? [])),
        ];
    }

    /** Timeline line on the deal this contract came from, if any. */
    private static function activity(array $c, string $text, ?int $userId = null): void
    {
        if (!empty($c['deal_id'])) {
            Activities::add('deal', (int)$c['deal_id'], 'system', $text, $userId);
        } elseif (!empty($c['contact_id'])) {
            Activities::add('contact', (int)$c['contact_id'], 'system', $text, $userId);
        }
    }

    /**
     * Queue a message to the customer. Goes through the reminders queue like
     * every other customer contact, so it is paced, logged in the outbox and
     * silenced along with the rest if the contract is cancelled.
     */
    private static function notifyCustomer(
        array $c, string $ruleKey, array $vars, string $channel = 'both', bool $allowRepeat = false
    ): int {
        $phone = trim((string)($c['customer_phone'] ?? ''));
        $email = trim((string)($c['customer_email'] ?? ''));
        if ($phone === '' && $email === '') {
            return 0;
        }
        if ($phone === '') {
            $channel = 'email';
        } elseif ($email === '') {
            $channel = 'whatsapp';
        }

        // One message per contract per rule, unless the agent deliberately asks
        // again (re-sending the link) — then the timestamp makes it a new row.
        $dedupe = 'pay:' . $ruleKey . ':' . (int)$c['id'] . ($allowRepeat ? ':' . date('Y-m-d H:i:s') : '');

        return (new Scheduler())->enqueue([
            'entity_type'    => 'payment_contract',
            'entity_id'      => (int)$c['id'],
            'rule_key'       => $ruleKey,
            'recipient_type' => 'customer',
            'channel'        => $channel,
            'due_at'         => date('Y-m-d H:i:s'),
            'lang'           => $c['lang'] ?? null,
            'dedupe_key'     => $dedupe,
            'payload'        => $vars + [
                'customer_phone' => $phone,
                'customer_email' => $email,
                'name'           => (string)($c['customer_name'] ?? ''),
            ],
        ]);
    }

    /** Same, to the seller who owns the contract. Silent if nobody owns it. */
    private static function notifyAgent(array $c, string $ruleKey, array $vars): int
    {
        if (empty($c['assigned_to'])) {
            return 0;
        }
        return (new Scheduler())->enqueue([
            'entity_type'    => 'payment_contract',
            'entity_id'      => (int)$c['id'],
            'rule_key'       => $ruleKey,
            'recipient_type' => 'agent',
            'channel'        => 'both',
            'due_at'         => date('Y-m-d H:i:s'),
            // A failure can happen again next month and the seller needs to hear
            // it again, so the key carries the day.
            'dedupe_key'     => 'pay:' . $ruleKey . ':' . (int)$c['id'] . ':' . date('Y-m-d'),
            'payload'        => $vars,
        ]);
    }

    /** Where SmallPay sends the customer once the cashier page is done. */
    private static function returnUrl(string $reference): string
    {
        return Config::appBaseUrl() . '/pay-return.php?ref=' . rawurlencode($reference);
    }

    /** Where SmallPay POSTs status changes (§3.4). */
    private static function callbackUrl(): string
    {
        return Config::appBaseUrl() . '/webhooks/smallpay-status.php';
    }
}
