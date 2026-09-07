<?php
declare(strict_types=1);

namespace Glue\Portal;

use Glue\Config;
use Glue\Crm\Contacts;
use Glue\Crm\Tickets;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Pay\Contracts as PayContracts;

/**
 * Assistance requests — the client's spec of 2026-09-07, in full:
 *
 * A customer asks for help (from the portal, or the public /support.php form
 * that opens with their VAT number). Covered customers — a live SmallPay
 * subscription or a gestionale contract still ahead of expiry — go straight
 * through with PRIORITY handling. Uncovered ones choose: activate the one
 * contract on offer (Helpdesk, EUR 9.90/month, paid through SmallPay — the
 * request is held until the first payment lands) or continue without, in which
 * case the request still goes through but flagged for BUSINESS-HOURS handling.
 *
 * Every open request reaches all technicians; exactly one takes charge —
 * claim() is atomic, so two techs pressing together cannot both win — and the
 * take-charge note lands in the ticket thread, which is what the customer sees
 * in their area.
 */
final class AssistRequests
{
    // ---- the gate --------------------------------------------------------------------

    /** @return array{covered:bool, label:string} label = why, for the banners */
    public static function cover(int $contactId): array
    {
        $pdo = Db::pdo();
        // Only subscriptions count: a machine paid in instalments is a live
        // contract too, but it is not support cover.
        $stmt = $pdo->prepare(
            "SELECT description, amount_cents, currency FROM payment_contracts
             WHERE contact_id = ? AND kind = 'subscription' AND status IN ('active','past_due')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$contactId]);
        if ($pc = $stmt->fetch()) {
            return ['covered' => true, 'label' => (string)$pc['description']];
        }
        $c = Contacts::find($contactId);
        $expiry = (string)($c['contract_expiry'] ?? '');
        if ($expiry !== '' && $expiry >= date('Y-m-d')) {
            return ['covered' => true, 'label' => date('d/m/Y', strtotime($expiry))];
        }
        return ['covered' => false, 'label' => ''];
    }

    /**
     * The one contract on offer — "we only offer a Helpdesk contract,
     * EUR 9.90/month". The price and name are the code defaults; Settings can
     * override them, and an explicit 0 amount (or SmallPay off) removes the
     * offer, which turns every uncovered request into a business-hours one.
     *
     * @return array{amount_cents:int, cycles:int, description:string, features:string}|null
     */
    public static function offer(): ?array
    {
        if (!\Glue\Pay\SmallPay::enabled()) {
            return null;
        }
        $amount = self::cents((string)Config::get('support.amount', '9,99'));
        if ($amount <= 0) {
            return null;
        }
        $desc = trim((string)Config::get('support.description', '')) ?: 'Contratto Helpdesk';
        // What the contract includes — shown to the customer on the form, not on
        // the SmallPay page (that keeps the short description). Editable so the
        // owner can restate the offer without a deploy.
        $features = trim((string)Config::get('support.features', ''))
            ?: 'Centralino con operatore H24 e gestione delle problematiche con assistenza '
             . 'di primo livello da remoto via chat, con a disposizione 60 minuti al mese.';
        return [
            'amount_cents' => $amount,
            'cycles'       => max(0, (int)Config::get('support.cycles', 0)),
            'description'  => mb_substr($desc, 0, 190),
            'features'     => $features,
        ];
    }

    // ---- the customer submits --------------------------------------------------------

    /**
     * @param array|null $attachment ['path','name'] as Tickets::storeUpload returns
     * @param array $opts choice: 'activate'|'skip'|null (only read when uncovered),
     *                    phone: WhatsApp-able callback number for THIS request,
     *                    vat: the VAT typed on the public form, source: portal|public
     * @return array{status:string, ticket_id?:int, request_id?:int, pay_url?:string, pay_failed?:bool}
     *         status: forwarded | awaiting_payment
     */
    public static function submit(int $contactId, string $subject, string $body,
                                  ?array $attachment, array $opts = []): array
    {
        $subject = trim($subject) !== '' ? mb_substr(trim($subject), 0, 190) : 'Richiesta di assistenza';
        $phone   = Notifier::normalizePhone((string)($opts['phone'] ?? ''));
        $base    = [
            'subject' => $subject, 'body' => $body, 'attachment' => $attachment,
            'phone'   => $phone !== '' ? $phone : null,
            'vat'     => trim((string)($opts['vat'] ?? '')) ?: null,
            'source'  => ($opts['source'] ?? '') === 'public' ? 'public' : 'portal',
        ];

        if (self::cover($contactId)['covered']) {
            $rid = self::createRow($contactId, $base, 'open', 'priority', null);
            $tid = self::ticketize(self::find($rid));
            return ['status' => 'forwarded', 'ticket_id' => $tid, 'request_id' => $rid, 'priority' => 'priority'];
        }

        $offer  = self::offer();
        $choice = (string)($opts['choice'] ?? 'skip');

        if ($offer !== null && $choice === 'activate') {
            // One pending contract per customer: a second request while the
            // first payment is still open must NOT file a second position.
            $pcId = self::reusableContractId($contactId);
            $payUrl = '';
            if ($pcId === 0) {
                $c = Contacts::find($contactId) ?: [];
                try {
                    $pc = PayContracts::open([
                        'kind'           => 'subscription',
                        'contact_id'     => $contactId,
                        'customer_name'  => (string)($c['name'] ?? ''),
                        'customer_phone' => $phone !== '' ? $phone : (string)($c['phone'] ?? ''),
                        'customer_email' => (string)($c['email'] ?? ''),
                        'lang'           => (string)($c['lang'] ?? 'it'),
                        'description'    => $offer['description'],
                        'amount_cents'   => $offer['amount_cents'],
                        'total_cycles'   => $offer['cycles'],
                    ], null);
                    $pcId = (int)$pc['id'];
                    $payUrl = (string)($pc['checkout_url'] ?? '');
                    try { PayContracts::sendLink($pcId, 'both', null); } catch (\Throwable $e) {
                        Log::write('assist', 'send_link_failed', 'payment_contract', $pcId, ['error' => $e->getMessage()]);
                    }
                } catch (\Throwable $e) {
                    // SmallPay refused: the request must still be processed —
                    // it goes through at business hours, and the admins are
                    // told the payment could not be started.
                    Log::write('assist', 'contract_open_failed', 'contact', $contactId, ['error' => $e->getMessage()]);
                    $rid = self::createRow($contactId, $base, 'open', 'business_hours', null);
                    $tid = self::ticketize(self::find($rid));
                    self::notifyAdminsPayFailed($rid, $contactId, $subject);
                    return ['status' => 'forwarded', 'ticket_id' => $tid, 'request_id' => $rid,
                            'pay_failed' => true, 'priority' => 'business_hours'];
                }
            } else {
                $pc = PayContracts::find($pcId);
                $payUrl = (string)($pc['checkout_url'] ?? '');
            }
            $rid = self::createRow($contactId, $base, 'awaiting_payment', 'priority', $pcId);
            return ['status' => 'awaiting_payment', 'request_id' => $rid, 'pay_url' => $payUrl];
        }

        // Declined (or nothing on offer): "the request must still be processed,
        // but it will be handled during business hours."
        $rid = self::createRow($contactId, $base, 'open', 'business_hours', null);
        $tid = self::ticketize(self::find($rid));
        return ['status' => 'forwarded', 'ticket_id' => $tid, 'request_id' => $rid, 'priority' => 'business_hours'];
    }

    // ---- forwarding + taking charge --------------------------------------------------

    /** Called by Contracts::onStatusChange when a contract's first payment lands. */
    public static function onContractActive(int $contractId): void
    {
        $stmt = Db::pdo()->prepare(
            "SELECT id FROM assist_requests WHERE pay_contract_id = ? AND status = 'awaiting_payment'"
        );
        $stmt->execute([$contractId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $rid) {
            self::forward((int)$rid);
        }
    }

    /** Turn a held request into an open ticket. Also the admin's manual waiver. */
    public static function forward(int $requestId): bool
    {
        $r = self::find($requestId);
        if (!$r || $r['status'] !== 'awaiting_payment') {
            return false;
        }
        self::ticketize($r);
        return true;
    }

    /**
     * One technician takes charge. Atomic: the UPDATE only wins on a row still
     * unclaimed, so the second press returns false and the first name stands.
     * The take-charge note goes into the ticket thread — Tickets::reply also
     * messages the customer, and the thread is what their area shows.
     */
    public static function claim(int $requestId, int $userId): bool
    {
        $stmt = Db::pdo()->prepare(
            "UPDATE assist_requests SET status = 'taken', claimed_by = ?, claimed_at = NOW()
             WHERE id = ? AND status = 'open' AND claimed_by IS NULL"
        );
        $stmt->execute([$userId, $requestId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $r = self::find($requestId);
        $who = self::staffName($userId);
        if (!empty($r['ticket_id'])) {
            Db::pdo()->prepare('UPDATE tickets SET assigned_agent_id = ? WHERE id = ?')
                ->execute([$userId, (int)$r['ticket_id']]);
            Tickets::reply((int)$r['ticket_id'], 'agent', $userId, $who,
                '✅ Richiesta presa in carico da ' . $who . '. Ti ricontattiamo a breve.');
        }
        Log::write('assist', 'request_claimed', 'assist_request', $requestId, ['by' => $userId]);
        return true;
    }

    /** Admin: drop a held request that will never be paid (typo, spam, gave up). */
    public static function cancel(int $requestId): bool
    {
        $n = Db::pdo()->prepare(
            "UPDATE assist_requests SET status = 'cancelled' WHERE id = ? AND status = 'awaiting_payment'"
        );
        $n->execute([$requestId]);
        return $n->rowCount() > 0;
    }

    // ---- reading ---------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM assist_requests WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** The customer's held requests, newest first, with their contract's state. */
    public static function pendingForContact(int $contactId): array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT r.*, pc.status AS pc_status, pc.checkout_url, pc.amount_cents, pc.currency
             FROM assist_requests r
             LEFT JOIN payment_contracts pc ON pc.id = r.pay_contract_id
             WHERE r.contact_id = ? AND r.status = 'awaiting_payment' ORDER BY r.id DESC"
        );
        $stmt->execute([$contactId]);
        return $stmt->fetchAll();
    }

    /** The Support tab: recent requests with customer, contract and claimer. */
    public static function listAll(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        return Db::pdo()->query(
            "SELECT r.*, c.name AS customer_name, c.phone AS registry_phone,
                    u.full_name AS claimer_name, u.username AS claimer_username,
                    pc.status AS pc_status, pc.reference
             FROM assist_requests r
             JOIN contacts c ON c.id = r.contact_id
             LEFT JOIN users u ON u.id = r.claimed_by
             LEFT JOIN payment_contracts pc ON pc.id = r.pay_contract_id
             ORDER BY (r.status = 'open') DESC, (r.status = 'awaiting_payment') DESC, r.id DESC
             LIMIT $limit"
        )->fetchAll();
    }

    // ---- internals -------------------------------------------------------------------

    private static function createRow(int $contactId, array $b, string $status,
                                      string $priority, ?int $contractId): int
    {
        Db::pdo()->prepare(
            'INSERT INTO assist_requests
                (contact_id, vat_number, contact_phone, subject, body,
                 attachment_path, attachment_name, status, priority, source, pay_contract_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $contactId, $b['vat'], $b['phone'], $b['subject'], $b['body'],
            $b['attachment']['path'] ?? null, $b['attachment']['name'] ?? null,
            $status, $priority, $b['source'], $contractId ?: null,
        ]);
        $rid = (int)Db::pdo()->lastInsertId();
        Log::write('assist', 'request_created', 'assist_request', $rid,
            ['contact_id' => $contactId, 'status' => $status, 'priority' => $priority]);
        return $rid;
    }

    /** Open the ticket thread, mark the row open, wake the technicians. */
    private static function ticketize(array $r): int
    {
        $lines = [];
        if (!empty($r['contact_phone'])) {
            $lines[] = '📞 Ricontattare al numero: ' . (string)$r['contact_phone'];
        }
        if ((string)$r['priority'] === 'business_hours') {
            $lines[] = '⏰ Senza contratto di assistenza — gestione in orario lavorativo.';
        }
        $body = ($lines ? implode("\n", $lines) . "\n\n" : '') . (string)$r['body'];
        $att = !empty($r['attachment_path'])
            ? ['path' => (string)$r['attachment_path'], 'name' => (string)($r['attachment_name'] ?: 'allegato')]
            : null;
        $tid = Tickets::open((int)$r['contact_id'], (string)$r['subject'], $body, null, $att);
        Db::pdo()->prepare(
            "UPDATE assist_requests SET status = 'open', ticket_id = ?, forwarded_at = NOW() WHERE id = ?"
        )->execute([$tid, (int)$r['id']]);
        Log::write('assist', 'request_forwarded', 'assist_request', (int)$r['id'], ['ticket_id' => $tid]);
        self::notifyTechs($r, $tid);
        return $tid;
    }

    /** A draft/awaiting contract this customer already has from a previous request. */
    private static function reusableContractId(int $contactId): int
    {
        $stmt = Db::pdo()->prepare(
            "SELECT pc.id FROM assist_requests r
             JOIN payment_contracts pc ON pc.id = r.pay_contract_id
             WHERE r.contact_id = ? AND r.status = 'awaiting_payment'
               AND pc.status IN ('draft','awaiting_customer','failed')
             ORDER BY r.id DESC LIMIT 1"
        );
        $stmt->execute([$contactId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * "The request must reach all the technicians": WhatsApp + email to every
     * active tech-role user — or the admins when there are no techs yet. The
     * claim happens on the Support tab; first press wins.
     */
    private static function notifyTechs(array $r, int $ticketId): void
    {
        $customer = (string)((Contacts::find((int)$r['contact_id']) ?: [])['name'] ?? '');
        $phone = (string)($r['contact_phone'] ?? '') ?: (string)((Contacts::find((int)$r['contact_id']) ?: [])['phone'] ?? '');
        $prio  = (string)$r['priority'] === 'business_hours' ? '⏰ orario lavorativo' : '⚡ prioritaria';
        $link  = Config::appBaseUrl() . '/dashboard.php?tab=support';
        $text = '🔧 ' . (string)Config::get('app.company_name', 'CRM')
            . " — nuova richiesta di assistenza ($prio) da {$customer}"
            . ($phone !== '' ? " ({$phone})" : '')
            . ': «' . (string)$r['subject'] . "»\nPrendila in carico: {$link}";
        $html = '<p>🔧 Nuova richiesta di assistenza (' . htmlspecialchars($prio, ENT_QUOTES) . ') da <b>'
            . htmlspecialchars($customer, ENT_QUOTES) . '</b>'
            . ($phone !== '' ? ' (' . htmlspecialchars($phone, ENT_QUOTES) . ')' : '')
            . ': «' . htmlspecialchars((string)$r['subject'], ENT_QUOTES) . '»</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Prendila in carico nel CRM</a></p>';
        self::sendToStaff('tech', $text, 'Nuova richiesta di assistenza — ' . $customer, $html)
            || self::sendToStaff('admin', $text, 'Nuova richiesta di assistenza — ' . $customer, $html);
    }

    /** SmallPay would not open the Helpdesk contract — the admins should know. */
    private static function notifyAdminsPayFailed(int $requestId, int $contactId, string $subject): void
    {
        $customer = (string)((Contacts::find($contactId) ?: [])['name'] ?? '');
        $text = '⚠️ ' . (string)Config::get('app.company_name', 'CRM')
            . " — il cliente {$customer} voleva attivare il contratto Helpdesk ma SmallPay ha rifiutato "
            . "l'apertura della posizione. La richiesta #{$requestId} («{$subject}») è passata comunque "
            . 'in orario lavorativo. Contattarlo per il contratto.';
        self::sendToStaff('admin', $text, 'Attivazione Helpdesk non riuscita — ' . $customer,
            '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>');
    }

    /** Message every active user of a role. True if at least one channel went out. */
    private static function sendToStaff(string $role, string $text, string $subject, string $html): bool
    {
        $stmt = Db::pdo()->prepare('SELECT phone, email FROM users WHERE role = ? AND active = 1');
        $stmt->execute([$role]);
        $n = new Notifier();
        $any = false;
        foreach ($stmt->fetchAll() as $u) {
            if (trim((string)($u['phone'] ?? '')) !== '') {
                $any = $n->whatsapp((string)$u['phone'], $text) || $any;
            }
            if (trim((string)($u['email'] ?? '')) !== '') {
                $any = $n->email((string)$u['email'], $subject, $html) || $any;
            }
        }
        return $any;
    }

    private static function staffName(int $userId): string
    {
        $stmt = Db::pdo()->prepare('SELECT COALESCE(NULLIF(full_name, ""), username) FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string)($stmt->fetchColumn() ?: 'Staff');
    }

    /** "9,90", "25", "1.234,56" → cents. Mirrors the dashboard's money_cents. */
    private static function cents(string $raw): int
    {
        $s = preg_replace('/[^\d.,]/', '', trim($raw)) ?? '';
        if ($s === '') {
            return 0;
        }
        $lastSep = max(strrpos($s, ',') ?: -1, strrpos($s, '.') ?: -1);
        if ($lastSep >= 0 && strlen($s) - $lastSep - 1 >= 1 && strlen($s) - $lastSep - 1 <= 2) {
            $int = preg_replace('/\D/', '', substr($s, 0, $lastSep)) ?? '';
            $dec = str_pad(preg_replace('/\D/', '', substr($s, $lastSep + 1)) ?? '', 2, '0');
        } else {
            $int = preg_replace('/\D/', '', $s) ?? '';
            $dec = '00';
        }
        return (int)($int === '' ? '0' : $int) * 100 + (int)substr($dec, 0, 2);
    }
}
