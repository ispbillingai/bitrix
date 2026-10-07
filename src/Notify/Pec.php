<?php
declare(strict_types=1);

namespace Glue\Notify;

use Glue\Config;
use Glue\Db;

/**
 * Certified email — PEC (migration 079).
 *
 * "Ho bisogno di inviare una mail certificata nel caso il cliente non paghi una
 *  rata di assistenza, ad esempio."
 *
 * Technically a PEC is nothing exotic: SMTP against the provider's server
 * (Aruba, Register, Legalmail, Poste) with the mailbox's own credentials, which
 * is why this class is a thin thing on top of Mailer with its OWN account —
 * `pec.*` in the settings, never the ordinary one. Sending a sollecito from
 * info@ would be an email; sending it from the PEC box is a legal act.
 *
 * What makes it certified is not the sending but the two receipts the provider
 * drops back into that same mailbox: ACCETTAZIONE (we took it) and CONSEGNA
 * (the other PEC box received it, original sealed inside). Those are collected
 * by Mail\PecReceipts and matched to the outbox row through the Message-ID this
 * class sets by hand — a provider-generated one would leave us nothing to match
 * on. See [[Mail\PecReceipts]].
 *
 * The other half of the law is the address: a PEC is only certified between two
 * PEC boxes. Sending one to an ordinary mailbox is at best an email and at
 * worst refused by the provider, so send() refuses an address that is not a PEC
 * rather than let the office believe something was served. Which customer has
 * one is already known — `contacts.pec` arrives with the CLIENTI.xlsx import.
 */
final class Pec
{
    /** Domains that are PEC by construction, for the sanity check in isPec(). */
    private const PEC_HINTS = ['pec.', 'postecert.it', 'legalmail.it', 'pec-email.com',
                               'sicurezzapostale.it', 'cert.legalmail.it'];

    public static function enabled(): bool
    {
        return (bool)Config::get('pec.enabled', false) && self::configured();
    }

    /** A mailbox we could actually send from: host, user, password, address. */
    public static function configured(): bool
    {
        return trim((string)Config::get('pec.smtp_host', '')) !== ''
            && trim((string)Config::get('pec.user', '')) !== ''
            && trim((string)Config::get('pec.pass', '')) !== ''
            && trim((string)Config::get('pec.address', '')) !== '';
    }

    /** The PEC address the CRM sends from — the mailbox, not info@. */
    public static function address(): string
    {
        return trim((string)Config::get('pec.address', ''));
    }

    public static function fromName(): string
    {
        return trim((string)Config::get('pec.from_name', ''))
            ?: trim((string)Config::get('app.company_name', 'CRM'));
    }

    /**
     * Does this look like a PEC address? Nobody can tell for certain without
     * asking INI-PEC, but every Italian provider's domain says so plainly, and
     * the office can override the guess (pec.allow_any) for a domain we do not
     * know yet.
     */
    public static function isPec(string $address): bool
    {
        $address = strtolower(trim($address));
        if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ((bool)Config::get('pec.allow_any', false)) {
            return true;
        }
        $domain = substr(strrchr($address, '@') ?: '', 1);
        foreach (self::PEC_HINTS as $hint) {
            if (str_contains($domain, $hint)) {
                return true;
            }
        }
        // Everything the office has already written off as PEC counts too, one
        // domain per line in the settings: the list of providers is not closed.
        foreach (preg_split('/[\s,;]+/', (string)Config::get('pec.extra_domains', '')) ?: [] as $d) {
            $d = strtolower(trim($d, " \t\n\r.@"));
            if ($d !== '' && ($domain === $d || str_ends_with($domain, '.' . $d))) {
                return true;
            }
        }
        return false;
    }

    /** The SMTP block Mailer wants, built from the pec.* settings. */
    private static function mailerCfg(): array
    {
        $secure = strtolower(trim((string)Config::get('pec.secure', 'ssl')));
        // Settings drop an empty value, so "no encryption" is stored as the word
        // 'none' and turned back into '' here — a blank would read as the default
        // and quietly put SSL back on a server that does not speak it.
        if ($secure === 'none') { $secure = ''; }
        return [
            'from_email' => self::address(),
            'from_name'  => self::fromName(),
            'smtp' => [
                'host'   => trim((string)Config::get('pec.smtp_host', '')),
                'port'   => (int)Config::get('pec.smtp_port', 465),
                'secure' => in_array($secure, ['ssl', 'tls', ''], true) ? $secure : 'ssl',
                'user'   => trim((string)Config::get('pec.user', '')),
                'pass'   => (string)Config::get('pec.pass', ''),
            ],
        ];
    }

    /**
     * The Message-ID this PEC will carry. Ours, not the provider's: the receipts
     * name it in X-Riferimento-Message-ID, and that is how a consegna finds the
     * row it proves.
     */
    public static function newMessageId(): string
    {
        $domain = substr(strrchr(self::address(), '@') ?: '@crm.local', 1);
        return '<pec.' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain . '>';
    }

    /**
     * Send one PEC and write it to the outbox.
     *
     * @param array $attachments as Mailer wants them — the invoice, usually
     * @return array{ok:bool, error:?string, message_id:?string, row:?int}
     */
    public function send(string $to, string $subject, string $htmlBody, array $attachments = []): array
    {
        $to = trim($to);
        if (!self::enabled()) {
            return self::refuse($to, $subject, $htmlBody, 'pec_disabled');
        }
        if (!self::isPec($to)) {
            // Not a refusal of taste: a PEC to an ordinary mailbox carries no
            // delivery receipt, so nothing would be proved by sending it.
            return self::refuse($to, $subject, $htmlBody, 'not_a_pec');
        }

        $msgId = self::newMessageId();
        $res = (new Mailer(self::mailerCfg()))->send($to, $subject, $htmlBody, $attachments, [
            'Message-ID: ' . $msgId,
            'Date: ' . date('r'),
        ]);
        $row = self::record($to, $subject, $htmlBody, (bool)$res['ok'], [
            'ok'    => (bool)$res['ok'],
            'error' => $res['error'] ?? null,
        ], $msgId);

        return ['ok' => (bool)$res['ok'], 'error' => $res['error'] ?? null,
                'message_id' => $msgId, 'row' => $row];
    }

    /** @return array{ok:bool, error:?string, message_id:?string, row:?int} */
    private static function refuse(string $to, string $subject, string $body, string $why): array
    {
        $row = self::record($to, $subject, $body, false, ['ok' => false, 'error' => $why, 'skipped' => $why], null);
        return ['ok' => false, 'error' => $why, 'message_id' => null, 'row' => $row];
    }

    /** Every PEC, sent or refused, is in the outbox beside the rest. */
    private static function record(string $to, string $subject, string $body, bool $ok,
                                   array $response, ?string $msgId): ?int
    {
        $db = Db::pdo();
        $db->prepare(
            'INSERT INTO messages (reminder_id, campaign_id, channel, recipient, subject, body, status,
                                   provider_response, provider_ref)
             VALUES (NULL, NULL, "pec", ?, ?, ?, ?, ?, ?)'
        )->execute([
            $to, $subject, $body, $ok ? 'sent' : 'failed',
            json_encode($response, JSON_UNESCAPED_UNICODE), $msgId,
        ]);
        return (int)$db->lastInsertId() ?: null;
    }

    /**
     * What the office has sent to this customer by PEC, newest first, each with
     * the receipts that came back.
     */
    public static function forContact(int $contactId, int $limit = 20): array
    {
        $pec = Db::pdo()->prepare('SELECT pec FROM contacts WHERE id = ?');
        $pec->execute([$contactId]);
        $addr = trim((string)($pec->fetchColumn() ?: ''));
        if ($addr === '') {
            return [];
        }
        $q = Db::pdo()->prepare(
            'SELECT * FROM messages WHERE channel = "pec" AND recipient = ? ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $q->execute([$addr]);
        $rows = $q->fetchAll() ?: [];
        foreach ($rows as &$r) {
            $r['receipts'] = self::receiptsOf((int)$r['id']);
        }
        return $rows;
    }

    /** @return array<int,array> the receipts filed against one sent PEC */
    public static function receiptsOf(int $messageId): array
    {
        $q = Db::pdo()->prepare('SELECT * FROM pec_receipts WHERE message_id = ? ORDER BY id');
        $q->execute([$messageId]);
        return $q->fetchAll() ?: [];
    }

    /**
     * The one word that says where a sent PEC stands: delivered, accepted,
     * refused, or still on its way. 'consegna' is the one with legal weight.
     */
    public static function stateOf(array $message): string
    {
        if ((string)$message['status'] !== 'sent') {
            return 'failed';
        }
        $kinds = array_column(self::receiptsOf((int)$message['id']), 'kind');
        foreach (['errore', 'consegna', 'accettazione'] as $k) {
            if (in_array($k, $kinds, true)) {
                return $k;
            }
        }
        return 'sent';
    }
}
