<?php
declare(strict_types=1);

namespace Glue\Mail;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Pec;
use Glue\Settings;
use Throwable;

/**
 * Collects what comes BACK into the PEC mailbox (migration 079).
 *
 * A sent PEC on its own proves nothing. The proof is the pair of receipts the
 * provider posts into the same mailbox minutes later — ACCETTAZIONE (the
 * provider took the message) and, the one that counts, CONSEGNA (it was put in
 * the recipient's PEC box, with the original message sealed inside). An unpaid
 * customer who says "non ho mai ricevuto niente" is answered with the consegna,
 * not with our own word that we sent something.
 *
 * So this poller reads the mailbox over POP3 — the same protocol and the same
 * php-imap extension as the lead mailbox next door — recognises the receipts by
 * the subject prefixes the law fixes (DM 2/11/2005) and files each against the
 * outbox row it refers to, matched on the Message-ID that Notify\Pec set by hand
 * when sending. Each receipt is also kept whole on disk, signature and all:
 * what has evidential value is the message, not our summary of it.
 *
 * Nothing is ever deleted from the mailbox: the office keeps reading it in
 * webmail. Everything seen is remembered by its uid, so a poll never doubles,
 * and a PEC that is not a receipt (a customer writing to us) is recorded as
 * 'altro' and left alone.
 */
final class PecReceipts
{
    /** Subject prefix => what kind of receipt it is. Longest match first. */
    private const SUBJECTS = [
        'AVVISO DI MANCATA CONSEGNA'      => 'errore',
        'AVVISO DI NON ACCETTAZIONE'      => 'errore',
        'PREAVVISO DI MANCATA CONSEGNA'   => 'preavviso',
        'ERRORE DI CONSEGNA'              => 'errore',
        'ANOMALIA MESSAGGIO'              => 'errore',
        'ACCETTAZIONE'                    => 'accettazione',
        'CONSEGNA'                        => 'consegna',
    ];

    /** daticert.xml <tipo> => the same vocabulary, when the header is there. */
    private const TIPI = [
        'accettazione'            => 'accettazione',
        'non-accettazione'        => 'errore',
        'presa-in-carico'         => 'accettazione',
        'avvenuta-consegna'       => 'consegna',
        'errore-consegna'         => 'errore',
        'preavviso-errore-consegna' => 'preavviso',
        'rilevazione-virus'       => 'errore',
    ];

    /** Where the receipts are kept, outside the web root. */
    public static function dir(): string
    {
        $root = dirname(__DIR__, 2);
        $dir  = $root . '/storage/pec';
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            return $dir;
        }
        $fallback = $root . '/public/uploads/pec';   // behind public/uploads/.htaccess (deny all)
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }
        return $fallback;
    }

    /**
     * Scheduler entry point: polls when one is due, else does nothing. Never
     * throws — a mailbox outage must not stall the reminders behind it.
     */
    public static function pollIfDue(): ?array
    {
        if (!Pec::enabled() || trim((string)Config::get('pec.pop_host', '')) === '') {
            return null;
        }
        if (!function_exists('imap_open')) {
            $last = (string)Settings::get('pec.ext_warned_at', '');
            if ($last === '' || time() - (strtotime($last) ?: 0) > 3600) {
                Settings::set('pec.ext_warned_at', date('Y-m-d H:i:s'));
                Log::write('pec', 'php_imap_missing', null, null, []);
            }
            return ['error' => 'php-imap extension missing'];
        }
        $every = max(1, (int)Config::get('pec.poll_minutes', 5));
        $last  = (string)Settings::get('pec.last_poll_at', '');
        if ($last !== '' && (time() - (strtotime($last) ?: 0)) < $every * 60) {
            return null;
        }
        // Claim the slot before the walk: a run that dies waits a full interval
        // instead of hammering the mailbox every minute.
        Settings::set('pec.last_poll_at', date('Y-m-d H:i:s'));

        try {
            return self::poll();
        } catch (Throwable $e) {
            Log::write('pec', 'poll_error', null, null, ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * One full pass over the mailbox.
     * @return array{seen:int,new:int,receipts:int,matched:int,other:int,errors:int}
     */
    public static function poll(): array
    {
        $host = trim((string)Config::get('pec.pop_host', ''));
        $port = (int)Config::get('pec.pop_port', 995);
        $conn = sprintf('{%s:%d/pop3/ssl}INBOX', $host, $port);

        $imap = @imap_open($conn, (string)Config::get('pec.user', ''), (string)Config::get('pec.pass', ''));
        if ($imap === false) {
            throw new \RuntimeException('PEC mailbox connect failed: ' . (imap_last_error() ?: 'unknown'));
        }

        try {
            $total = imap_num_msg($imap);
            $res = ['seen' => $total, 'new' => 0, 'receipts' => 0, 'matched' => 0, 'other' => 0, 'errors' => 0];
            $known = self::knownUids();

            for ($seq = 1; $seq <= $total; $seq++) {
                $ov = imap_fetch_overview($imap, (string)$seq)[0] ?? null;
                if (!$ov) {
                    continue;
                }
                $uid = self::uidOf($ov);
                if (isset($known[$uid])) {
                    continue;
                }
                $res['new']++;
                try {
                    $one = self::ingest($uid, (string)imap_fetchheader($imap, $seq) . "\r\n"
                        . (string)imap_body($imap, $seq), [
                            'subject' => isset($ov->subject) ? imap_utf8($ov->subject) : '',
                            'from'    => isset($ov->from) ? self::addrOf(imap_utf8($ov->from)) : '',
                            'date'    => isset($ov->date) ? (string)$ov->date : '',
                        ]);
                    $one['kind'] === 'altro' ? $res['other']++ : $res['receipts']++;
                    $res['matched'] += $one['matched'] ? 1 : 0;
                } catch (Throwable $e) {
                    $res['errors']++;
                    Log::write('pec', 'receipt_error', null, null, ['uid' => $uid, 'error' => $e->getMessage()]);
                }
            }
            if ($res['new'] > 0) {
                Log::write('pec', 'poll', null, null, $res);
            }
            return $res;
        } finally {
            imap_close($imap);   // no CL_EXPUNGE: the mailbox is left exactly as found
        }
    }

    /**
     * Which receipt this is. The subject is what the law fixes and every
     * provider writes; daticert.xml is consulted first when it is there,
     * because it says the same thing in a form nobody translates.
     */
    public static function kindOf(string $subject, string $raw = ''): string
    {
        if (preg_match('~<tipo>\s*([a-z\-]+)\s*</tipo>~i', $raw, $m)) {
            $tipo = strtolower(trim($m[1]));
            if (isset(self::TIPI[$tipo])) {
                return self::TIPI[$tipo];
            }
        }
        $s = mb_strtoupper(trim($subject));
        foreach (self::SUBJECTS as $prefix => $kind) {
            if (str_starts_with($s, $prefix)) {
                return $kind;
            }
        }
        return 'altro';
    }

    /**
     * The Message-ID this receipt refers to: the dedicated header first (what
     * the rules ask providers to set), then daticert.xml, which carries the
     * same string for those that do not.
     */
    public static function refOf(string $raw): ?string
    {
        if (preg_match('~^X-Riferimento-Message-ID:\s*(.+)$~im', $raw, $m)) {
            return trim($m[1]);
        }
        if (preg_match('~<msgid>\s*(&lt;|<)?([^<&]+?)(&gt;|>)?\s*</msgid>~i', $raw, $m)) {
            $id = trim($m[2]);
            return $id === '' ? null : '<' . trim($id, '<>') . '>';
        }
        return null;
    }

    /**
     * Take in one message from the mailbox: work out what it is, tie it to the
     * PEC it refers to, keep the proof. The mailbox is only the postman —
     * everything that decides anything happens here, which is also what makes
     * it testable without one.
     *
     * @param array $meta subject, from, date as the mailbox reported them
     * @return array{kind:string, matched:bool, message_id:?int}
     */
    public static function ingest(string $uid, string $raw, array $meta = []): array
    {
        $subject = (string)($meta['subject'] ?? '');
        $from    = (string)($meta['from'] ?? '');
        $when    = ($meta['date'] ?? '') !== ''
            ? date('Y-m-d H:i:s', strtotime((string)$meta['date']) ?: time()) : null;
        $kind    = self::kindOf($subject, $raw);
        $ref     = self::refOf($raw);
        $msgRow  = $kind === 'altro' ? null : self::matchMessage($ref, $subject);

        // The receipt itself is the evidence: keep it whole, signature and all.
        $file = null;
        if ($kind !== 'altro') {
            $file = 'pec_' . date('Ymd_His') . '_' . substr(sha1($uid), 0, 10) . '.eml';
            @file_put_contents(self::dir() . '/' . $file, $raw);
        }

        Db::pdo()->prepare(
            'INSERT INTO pec_receipts (message_id, kind, ref_msgid, recipient, subject, from_addr,
                                       received_at, uid, eml_path)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE message_id = VALUES(message_id)'
        )->execute([
            $msgRow['id'] ?? null, $kind, $ref !== null ? substr($ref, 0, 255) : null,
            isset($msgRow['recipient']) ? substr((string)$msgRow['recipient'], 0, 190) : null,
            substr($subject, 0, 255), substr($from, 0, 190), $when, substr($uid, 0, 190), $file,
        ]);
        return ['kind' => $kind, 'matched' => $msgRow !== null,
                'message_id' => isset($msgRow['id']) ? (int)$msgRow['id'] : null];
    }

    /**
     * The sent PEC a receipt belongs to: by Message-ID, which is exact, and
     * failing that by the address named in the receipt's subject — providers
     * write "CONSEGNA: <our subject>", so the newest unproved PEC whose own
     * subject is quoted there is the one. No guess beyond that: a receipt filed
     * against the wrong message is worse than one filed against none.
     */
    private static function matchMessage(?string $ref, string $subject): ?array
    {
        $db = Db::pdo();
        if ($ref !== null && $ref !== '') {
            $q = $db->prepare('SELECT id, recipient FROM messages WHERE channel = "pec" AND provider_ref = ? LIMIT 1');
            $q->execute([$ref]);
            if ($row = $q->fetch()) {
                return $row;
            }
        }
        // "ACCETTAZIONE: Sollecito di pagamento — fattura 123" → the subject we sent.
        $own = trim((string)preg_replace('~^[A-ZÀ-Ù \-]+:\s*~u', '', trim($subject)));
        if ($own === '') {
            return null;
        }
        $q = $db->prepare(
            'SELECT m.id, m.recipient FROM messages m
              WHERE m.channel = "pec" AND m.status = "sent" AND m.subject = ?
                AND NOT EXISTS (SELECT 1 FROM pec_receipts r WHERE r.message_id = m.id AND r.kind = "consegna")
              ORDER BY m.id DESC LIMIT 1'
        );
        $q->execute([$own]);
        return $q->fetch() ?: null;
    }

    /** @return array<string,true> */
    private static function knownUids(): array
    {
        $out = [];
        foreach (Db::pdo()->query('SELECT uid FROM pec_receipts')->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $u) {
            $out[(string)$u] = true;
        }
        return $out;
    }

    /** POP3 has no UID: the Message-ID when there is one, else date+subject. */
    private static function uidOf(object $ov): string
    {
        $id = trim((string)($ov->message_id ?? ''));
        if ($id !== '') {
            return $id;
        }
        return sha1(((string)($ov->date ?? '')) . '|' . ((string)($ov->subject ?? '')) . '|' . ((string)($ov->from ?? '')));
    }

    private static function addrOf(string $from): string
    {
        return preg_match('~<([^>]+)>~', $from, $m) ? trim($m[1]) : trim($from);
    }
}
