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
 * The portal's assistance request, gated on a support contract.
 *
 * Covered customer (a live SmallPay support subscription, or a gestionale
 * contract whose expiry is still ahead — the same rule the customer page's
 * support tile uses): the request becomes a ticket at once and the technicians
 * are messaged. Uncovered: the request is HELD, a SmallPay support subscription
 * is opened, and Contracts::onStatusChange forwards the held request the moment
 * the first payment lands. Payment is the acceptance; the consent checkbox in
 * the portal records that the customer asked for the contract.
 */
final class AssistRequests
{
    // ---- the gate --------------------------------------------------------------------

    /** @return array{covered:bool, label:string} label = why, for the portal banner */
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
     * The contract on offer, from Settings. Null = no online offer (price not
     * configured, or SmallPay off) — requests then pass with a warning tag so
     * the business keeps flowing until the owner fills the price in.
     *
     * @return array{amount_cents:int, cycles:int, description:string}|null
     */
    public static function offer(): ?array
    {
        if (!\Glue\Pay\SmallPay::enabled()) {
            return null;
        }
        $amount = self::cents((string)Config::get('support.amount', ''));
        if ($amount <= 0) {
            return null;
        }
        $desc = trim((string)Config::get('support.description', ''))
            ?: 'Contratto di assistenza ' . (string)Config::get('app.company_name', '');
        return [
            'amount_cents' => $amount,
            'cycles'       => max(0, (int)Config::get('support.cycles', 0)),
            'description'  => mb_substr(trim($desc), 0, 190),
        ];
    }

    // ---- the customer submits --------------------------------------------------------

    /**
     * @param array|null $attachment ['path','name'] as Tickets::storeUpload returns
     * @return array{status:string, ticket_id?:int, request_id?:int, pay_url?:string}
     *         status: forwarded | awaiting_payment | held_no_contract
     */
    public static function submit(int $contactId, string $subject, string $body, ?array $attachment): array
    {
        $subject = trim($subject) !== '' ? mb_substr(trim($subject), 0, 190) : 'Richiesta di assistenza';

        if (self::cover($contactId)['covered']) {
            $tid = Tickets::open($contactId, $subject, $body, null, $attachment);
            self::notifyTechs($tid, $contactId, $subject);
            return ['status' => 'forwarded', 'ticket_id' => $tid];
        }

        $offer = self::offer();
        if ($offer === null) {
            // No online offer to sell — let the request through, loudly tagged,
            // so staff see the gap instead of the customer hitting a dead end.
            $tid = Tickets::open($contactId, $subject, "[SENZA CONTRATTO DI ASSISTENZA]\n" . $body, null, $attachment);
            self::notifyTechs($tid, $contactId, $subject);
            return ['status' => 'forwarded', 'ticket_id' => $tid];
        }

        // One pending contract per customer: a second request while the first
        // payment is still open must NOT file a second SmallPay position.
        $pcId = self::reusableContractId($contactId);
        $payUrl = '';
        if ($pcId === 0) {
            $c = Contacts::find($contactId) ?: [];
            try {
                $pc = PayContracts::open([
                    'kind'           => 'subscription',
                    'contact_id'     => $contactId,
                    'customer_name'  => (string)($c['name'] ?? ''),
                    'customer_phone' => (string)($c['phone'] ?? ''),
                    'customer_email' => (string)($c['email'] ?? ''),
                    'lang'           => (string)($c['lang'] ?? 'it'),
                    'description'    => $offer['description'],
                    'amount_cents'   => $offer['amount_cents'],
                    'total_cycles'   => $offer['cycles'],
                ], null);
                $pcId = (int)$pc['id'];
                $payUrl = (string)($pc['checkout_url'] ?? '');
                // Put the link on WhatsApp/email too — the portal shows it, but
                // the customer may close the tab and pay from the message later.
                try { PayContracts::sendLink($pcId, 'both', null); } catch (\Throwable $e) {
                    Log::write('assist', 'send_link_failed', 'payment_contract', $pcId, ['error' => $e->getMessage()]);
                }
            } catch (\Throwable $e) {
                // SmallPay refused (config, outage): hold the request with no
                // contract and wake the admins — the customer is told we'll call.
                Log::write('assist', 'contract_open_failed', 'contact', $contactId, ['error' => $e->getMessage()]);
                $rid = self::hold($contactId, $subject, $body, $attachment, null);
                self::notifyAdminsHeld($rid, $contactId, $subject);
                return ['status' => 'held_no_contract', 'request_id' => $rid];
            }
        } else {
            $pc = PayContracts::find($pcId);
            $payUrl = (string)($pc['checkout_url'] ?? '');
        }

        $rid = self::hold($contactId, $subject, $body, $attachment, $pcId);
        return ['status' => 'awaiting_payment', 'request_id' => $rid, 'pay_url' => $payUrl];
    }

    // ---- forwarding ------------------------------------------------------------------

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

    /** Turn a held request into a live ticket. Also the admin's manual button. */
    public static function forward(int $requestId): bool
    {
        $r = self::find($requestId);
        if (!$r || $r['status'] !== 'awaiting_payment') {
            return false;
        }
        $att = !empty($r['attachment_path'])
            ? ['path' => (string)$r['attachment_path'], 'name' => (string)($r['attachment_name'] ?: 'allegato')]
            : null;
        $tid = Tickets::open((int)$r['contact_id'], (string)$r['subject'], (string)$r['body'], null, $att);
        Db::pdo()->prepare(
            "UPDATE assist_requests SET status = 'forwarded', ticket_id = ?, forwarded_at = NOW() WHERE id = ?"
        )->execute([$tid, $requestId]);
        Log::write('assist', 'request_forwarded', 'assist_request', $requestId, ['ticket_id' => $tid]);
        self::notifyTechs($tid, (int)$r['contact_id'], (string)$r['subject']);
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

    /** Every held request, for the admin card on the Tickets tab. */
    public static function pendingAll(): array
    {
        return Db::pdo()->query(
            "SELECT r.*, c.name AS customer_name, c.phone AS customer_phone,
                    pc.status AS pc_status, pc.reference, pc.amount_cents, pc.currency
             FROM assist_requests r
             JOIN contacts c ON c.id = r.contact_id
             LEFT JOIN payment_contracts pc ON pc.id = r.pay_contract_id
             WHERE r.status = 'awaiting_payment' ORDER BY r.id DESC LIMIT 100"
        )->fetchAll();
    }

    // ---- internals -------------------------------------------------------------------

    private static function hold(int $contactId, string $subject, string $body,
                                 ?array $attachment, ?int $contractId): int
    {
        Db::pdo()->prepare(
            'INSERT INTO assist_requests
                (contact_id, subject, body, attachment_path, attachment_name, pay_contract_id)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $contactId, $subject, $body,
            $attachment['path'] ?? null, $attachment['name'] ?? null,
            $contractId ?: null,
        ]);
        $rid = (int)Db::pdo()->lastInsertId();
        Log::write('assist', 'request_held', 'assist_request', $rid,
            ['contact_id' => $contactId, 'pay_contract_id' => $contractId]);
        return $rid;
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
     * "The system will send it to a technician": WhatsApp + email to every
     * active tech-role user — or to the admins when there are no techs yet.
     * Direct sends (Notifier waits out the TextMeBot gap itself).
     */
    private static function notifyTechs(int $ticketId, int $contactId, string $subject): void
    {
        $customer = (string)((Contacts::find($contactId) ?: [])['name'] ?? '');
        $link = Config::appBaseUrl() . '/dashboard.php?tab=tickets&tk=' . $ticketId;
        $text = "🔧 " . (string)Config::get('app.company_name', 'CRM')
            . " — nuova richiesta di assistenza da {$customer}: «{$subject}»\n{$link}";
        $html = '<p>🔧 Nuova richiesta di assistenza da <b>' . htmlspecialchars($customer, ENT_QUOTES)
            . '</b>: «' . htmlspecialchars($subject, ENT_QUOTES) . '»</p><p><a href="' . htmlspecialchars($link, ENT_QUOTES)
            . '">Apri la richiesta nel CRM</a></p>';
        self::sendToStaff('tech', $text, 'Nuova richiesta di assistenza — ' . $customer, $html)
            || self::sendToStaff('admin', $text, 'Nuova richiesta di assistenza — ' . $customer, $html);
    }

    /** SmallPay would not open the contract — a human has to pick this one up. */
    private static function notifyAdminsHeld(int $requestId, int $contactId, string $subject): void
    {
        $customer = (string)((Contacts::find($contactId) ?: [])['name'] ?? '');
        $text = "⚠️ " . (string)Config::get('app.company_name', 'CRM')
            . " — richiesta di assistenza #{$requestId} da {$customer} SENZA contratto attivabile"
            . " (SmallPay ha rifiutato l'apertura): «{$subject}». Gestire a mano dal CRM.";
        self::sendToStaff('admin', $text, 'Richiesta di assistenza da gestire a mano — ' . $customer,
            '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>');
    }

    /** Message every active user of a role. True if at least one channel went out. */
    private static function sendToStaff(string $role, string $text, string $subject, string $html): bool
    {
        $stmt = Db::pdo()->prepare(
            'SELECT phone, email FROM users WHERE role = ? AND active = 1'
        );
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

    /** "25", "25,50", "1.234,56" → cents. Mirrors the dashboard's money_cents. */
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
