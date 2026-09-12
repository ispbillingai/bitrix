<?php
declare(strict_types=1);

namespace Glue\Mail;

use Glue\Config;
use Glue\Crm\LeadIntake;
use Glue\Db;
use Glue\Event\Log;
use Glue\Settings;
use Throwable;

/**
 * Polls the company mailbox and turns web-form notification emails into leads.
 *
 * This is the "partner-email parser" that form-intake.php always anticipated:
 * the website form emails info@..., we read the mailbox over POP3 (the only
 * protocol the Aruba plan includes — IMAP is not provisioned) and feed each new
 * message through the same LeadIntake used by the webhooks, so email leads get
 * the identical de-duplication, welcome automation and pipeline placement.
 *
 * Mail is never deleted or altered: staff keep reading the same inbox in
 * webmail. What we have processed is remembered in `mail_intake` (see
 * migration 029), and the very first poll records the mailbox's existing
 * backlog as 'baseline' without importing any of it.
 *
 * The parser is deliberately generic — "Label: value" lines in the body are
 * collected and pushed through LeadIntake::normalize(), whose alias table
 * already understands nome/telefono/messaggio and friends. When the real form's
 * template is known, tuning happens in config (allowed_from) or in parse(),
 * not by touching the pipeline around it.
 *
 * Config: the 'leads_mailbox' block (config.sample.php) — host/user/pass,
 * poll cadence, sender allow/block lists and the sender => source map that files
 * each partner's leads under their own label. Needs the php-imap extension.
 */
final class LeadMailImporter
{
    /**
     * Scheduler entry point: runs a poll when one is due, else does nothing.
     * Never throws — a mailbox outage must not stall reminders.
     */
    public static function pollIfDue(): ?array
    {
        $cfg = Config::section('leads_mailbox');
        if (!(bool)($cfg['enabled'] ?? false)
            || (string)($cfg['user'] ?? '') === '' || (string)($cfg['pass'] ?? '') === '') {
            return null;
        }
        if (!function_exists('imap_open')) {
            // Once an hour, not every minute: the fix is an apt install, not a retry.
            $last = (string)Settings::get('leads_mailbox.ext_warned_at', '');
            if ($last === '' || time() - (strtotime($last) ?: 0) > 3600) {
                Settings::set('leads_mailbox.ext_warned_at', date('Y-m-d H:i:s'));
                Log::write('mail_intake', 'php_imap_missing', null, null, []);
            }
            return ['error' => 'php-imap extension missing'];
        }

        $every = max(1, (int)($cfg['poll_minutes'] ?? 5));
        $last  = (string)Settings::get('leads_mailbox.last_poll_at', '');
        if ($last !== '' && (time() - (strtotime($last) ?: 0)) < $every * 60) {
            return null;
        }
        // Claim the slot before the walk (Sibill pattern): a run that dies still
        // waits a full interval instead of hammering the mailbox every minute.
        Settings::set('leads_mailbox.last_poll_at', date('Y-m-d H:i:s'));

        try {
            return self::poll();
        } catch (Throwable $e) {
            Log::write('mail_intake', 'poll_error', null, null, ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * One full pass: list the mailbox, import what is new.
     * @return array{seen:int,new:int,imported:int,skipped:int,errors:int,baseline:bool}
     */
    public static function poll(): array
    {
        $cfg  = Config::section('leads_mailbox');
        $conn = sprintf('{%s:%d/pop3/ssl}INBOX',
            (string)($cfg['host'] ?? 'pop3s.aruba.it'), (int)($cfg['port'] ?? 995));

        $imap = @imap_open($conn, (string)$cfg['user'], (string)$cfg['pass']);
        if ($imap === false) {
            throw new \RuntimeException('mailbox connect failed: ' . (imap_last_error() ?: 'unknown'));
        }

        try {
            $total = imap_num_msg($imap);
            $known = self::knownKeys();
            // First contact with THIS mailbox: swallow the backlog. Keyed on the
            // configured user, so pointing the config at a different mailbox
            // re-arms the baseline instead of importing years of its old mail.
            $baseline = $known === []
                || (string)Settings::get('leads_mailbox.baseline_user', '') !== (string)$cfg['user'];

            $res = ['seen' => $total, 'new' => 0, 'imported' => 0,
                    'skipped' => 0, 'errors' => 0, 'baseline' => $baseline];

            for ($seq = 1; $seq <= $total; $seq++) {
                $ov = imap_fetch_overview($imap, (string)$seq)[0] ?? null;
                if (!$ov) {
                    continue;
                }
                $key = self::messageKey($ov);
                if (isset($known[$key])) {
                    continue;
                }
                $res['new']++;

                $from    = isset($ov->from) ? imap_utf8($ov->from) : '';
                $subject = isset($ov->subject) ? imap_utf8($ov->subject) : '';
                $sentAt  = isset($ov->date) ? date('Y-m-d H:i:s', strtotime($ov->date) ?: time()) : null;
                $row     = [
                    'message_key' => $key,
                    'message_id'  => isset($ov->message_id) ? substr($ov->message_id, 0, 255) : null,
                    'from_addr'   => substr(self::addrOf($from), 0, 190),
                    'subject'     => substr($subject, 0, 255),
                    'sent_at'     => $sentAt,
                ];

                if ($baseline) {
                    self::record($row, 'baseline', null, null);
                    continue;
                }

                try {
                    [$status, $reason, $leadId] = self::handle($imap, $seq, $from, $subject, $cfg, $key);
                    self::record($row, $status, $reason, $leadId);
                    $status === 'imported' ? $res['imported']++ : $res['skipped']++;
                } catch (Throwable $e) {
                    self::record($row, 'error', substr($e->getMessage(), 0, 190), null);
                    $res['errors']++;
                }
            }

            if ($baseline) {
                // Only after the walk completed: a baseline that died half-way
                // re-runs on the next poll and picks up the rest.
                Settings::set('leads_mailbox.baseline_user', (string)$cfg['user']);
            }
            // Quiet ticks stay out of the event log; anything that moved is worth a line.
            if ($baseline || $res['new'] > 0) {
                Log::write('mail_intake', $baseline ? 'baseline' : 'poll', null, null, $res);
            }
            return $res;
        } finally {
            imap_close($imap); // no CL_EXPUNGE: the inbox is left exactly as found
        }
    }

    /**
     * Decide what one new message becomes.
     * @return array{0:string,1:?string,2:?int} [status, reason, lead_id]
     */
    private static function handle($imap, int $seq, string $from, string $subject, array $cfg, string $key): array
    {
        $fromAddr = self::addrOf($from);
        $fromName = self::nameOf($from);

        // An explicit allow beats the generic blocks: Cashmatic's lead summaries
        // come from a noreply@ address, which the block list would otherwise eat.
        $allowed = array_filter((array)($cfg['allowed_from'] ?? []), 'strlen');
        if (!($allowed !== [] && self::senderAllowed($fromAddr, $allowed))) {
            foreach ((array)($cfg['blocked_from'] ?? []) as $bad) {
                if ($bad !== '' && stripos($fromAddr, (string)$bad) !== false) {
                    return ['skipped', 'blocked_from:' . $bad, null];
                }
            }
            if ($allowed !== []) {
                return ['skipped', 'sender_not_allowed', null];
            }
        }

        $body = self::bodyText($imap, $seq);
        $lead = self::parse($subject, $body, $fromAddr, $fromName, $cfg);
        if ($lead['phone'] === '' && $lead['email'] === '') {
            return ['skipped', 'no_contact', null];
        }

        // Message identity as external_id: a re-poll or a re-delivered email maps
        // back to the lead it already created (LeadIntake dedups on source+external_id).
        $lead['external_id'] = 'mail:' . $key;
        $out = LeadIntake::submit($lead);
        return ['imported', $out['duplicate'] ? 'duplicate_of_existing' : null, $out['lead_id']];
    }

    /**
     * Turn one email into the array Leads::create wants. Public and pure-ish so
     * the mapping can be tuned and re-tested against a saved sample without a
     * live mailbox.
     */
    public static function parse(string $subject, string $body, string $fromAddr, string $fromName, array $cfg = []): array
    {
        // Forwarding prefixes (Gmail "Fwd:", Italian clients "I:"/"R:") are
        // transport noise, not part of the request's title.
        $subject = trim((string)preg_replace('/^(\s*(fwd|fw|re|r|i)\s*:\s*)+/i', '', trim($subject)));

        // Template pass wins over the generic one where both find a value.
        $cashmatic = self::cashmaticPairs($body);
        $pairs     = array_merge(self::labelPairs($body), $cashmatic);
        if (!self::hasAny($pairs, ['title', 'subject', 'oggetto', 'titolo']) && $subject !== '') {
            $pairs['subject'] = $subject;
        }

        $lead = LeadIntake::normalize($pairs);
        // Who sent the mail decides which partner the lead is filed under, so the
        // monthly per-source report separates Cashmatic's leads from Berkel's
        // instead of piling every email lead under one label. A summary the
        // partner forwards from their own address is still a Cashmatic lead — it
        // is filed wherever mail from Cashmatic itself is filed.
        $lead['source'] = self::sourceForSender($fromAddr, $cfg)
            ?? ($cashmatic !== [] ? self::sourceForSender(self::CASHMATIC_SENDER, $cfg) : null)
            ?? ((string)($cfg['source'] ?? '') !== '' ? (string)$cfg['source'] : 'email');

        // Forms mail their fields; humans just write. When no contact was found in
        // the body, the sender IS the customer — take the From address/name.
        if ($lead['phone'] === '' && $lead['email'] === ''
            && (bool)($cfg['fallback_sender_email'] ?? true) && $fromAddr !== '') {
            $lead['email'] = mb_strtolower($fromAddr);
        }
        if ($lead['name'] === '' && $fromName !== '') {
            $lead['name'] = $fromName;
        }
        if ($lead['comments'] === '' && trim($body) !== '') {
            $lead['comments'] = mb_substr(trim($body), 0, 2000);
        }
        $lead['phone'] = self::internationalize($lead['phone']);
        return $lead;
    }

    /**
     * The partner a sender's mail is filed under: the first `source_by_sender`
     * entry whose pattern matches the address — a full address, or a bare domain
     * covering its subdomains, exactly as in `allowed_from`. null when no entry
     * matches, so the caller can fall back to the generic label.
     */
    private static function sourceForSender(string $addr, array $cfg): ?string
    {
        if ($addr === '') {
            return null;
        }
        foreach ((array)($cfg['source_by_sender'] ?? []) as $pattern => $source) {
            $source = trim((string)$source);
            if ($source !== '' && self::senderAllowed($addr, [(string)$pattern])) {
                return $source;
            }
        }
        return null;
    }

    /**
     * Store email-lead phones in full international form: Cashmatic summaries
     * carry bare local numbers ("3516353750"), and unlike a number an agent
     * types in, nobody reviews these before the automations dial them.
     * LeadIntake::normalize() already turned 00-prefixes into +, so what is
     * left is a national number: prefix app.default_country_code (Italy keeps
     * the trunk 0 of landlines, so nothing is stripped). A number that already
     * starts with the country code and is longer than any national number is
     * taken as one the sender prefixed themselves.
     */
    private static function internationalize(string $phone): string
    {
        $cc = preg_replace('/\D+/', '', (string)Config::get('app.default_country_code', '39')) ?? '';
        if ($phone === '' || str_starts_with($phone, '+') || $cc === '') {
            return $phone;
        }
        if (str_starts_with($phone, $cc) && strlen($phone) > 10) {
            return '+' . $phone;
        }
        return '+' . $cc . $phone;
    }

    // ---------------------------------------------------------------- parsing

    /** Cashmatic's own sender — the map entry a forwarded summary is filed under. */
    private const CASHMATIC_SENDER = 'noreply@cashmatic.eu';

    /**
     * Cashmatic's "Riepilogo Lead" summary lays its fields out as a table, and
     * no mail client agrees on how to flatten one into text — only knowing the
     * template's label set makes the fields recoverable either way.
     * Label => the alias LeadIntake::normalize() already understands; null =
     * context worth keeping, folded into one note (aliased to comments).
     */
    private const CASHMATIC_LABELS = [
        'nome contatto'                  => 'nominativo',
        'azienda'                        => 'azienda',
        'indirizzo'                      => 'zona',
        'email'                          => 'email',
        'telefono'                       => 'telefono',
        'fonte'                          => null,
        'sistema di cassa'               => null,
        'fornitore del sistema di cassa' => null,
        'note'                           => null,
        'nota di richiesta'              => null,
    ];

    /** @return array<string,string> [] when the body is not a Cashmatic summary */
    private static function cashmaticPairs(string $body): array
    {
        if (stripos($body, 'Riepilogo Lead') === false) {
            return [];
        }
        // The same template reaches us in two shapes. A copy the partner forwards
        // arrives with every row flattened onto one line ("Nome Contatto Mario
        // Rossi"); the mail Cashmatic sends us directly keeps the table, so its
        // text/plain part is a pipe grid in which the label is a cell of its own,
        // separated from its value. Read the grid first — a flattened body has no
        // cells to find, so the line pass answers there.
        $found = self::cashmaticCells($body) + self::cashmaticLines($body);

        // Walked in template order, so the folded note reads like the email.
        $out = [];
        $ctx = [];
        foreach (self::CASHMATIC_LABELS as $label => $key) {
            $value = $found[$label] ?? '';
            if ($value === '') {
                continue;
            }
            $key === null ? $ctx[] = ucfirst($label) . ': ' . $value : $out[$key] = $value;
        }
        if ($ctx !== []) {
            $out['note'] = implode("\n", $ctx);
        }
        return $out;
    }

    /**
     * Grid layout — `| Telefono | 3371194993 |`, each cell usually wrapped over
     * lines of its own. The first cell after a label cell is that field's value;
     * a label arriving where a value was due means the field was left blank.
     * @return array<string,string> label => value
     */
    private static function cashmaticCells(string $body): array
    {
        $out   = [];
        $label = null;
        foreach (explode('|', $body) as $cell) {
            $cell = trim($cell);
            if ($cell === '') {
                continue; // padding, or a row's leading/trailing pipe
            }
            // Whitespace inside a cell is the renderer wrapping it, not the label.
            $key = mb_strtolower((string)preg_replace('/\s+/u', ' ', $cell));
            if (array_key_exists($key, self::CASHMATIC_LABELS)) {
                $label = $key;
                continue;
            }
            if ($label !== null) {
                $value = self::cashmaticValue($cell);
                if ($value !== '') {
                    $out[$label] = $value;
                }
                $label = null;
            }
        }
        return $out;
    }

    /**
     * Flattened layout — "Label value" with NO separator, which only the known
     * label set can split unambiguously.
     * @return array<string,string> label => value
     */
    private static function cashmaticLines(string $body): array
    {
        // Longest label first, so "nota di richiesta" is never read as "note".
        $labels = array_keys(self::CASHMATIC_LABELS);
        usort($labels, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            foreach ($labels as $label) {
                if ($line === '' || stripos($line, $label) !== 0) {
                    continue;
                }
                $rest = mb_substr($line, mb_strlen($label));
                if ($rest !== '' && !preg_match('/^\s/', $rest)) {
                    continue; // label is a prefix of a longer word, not this field
                }
                $value = self::cashmaticValue($rest);
                if ($value !== '') {
                    $out[$label] = $value;
                }
                continue 2;
            }
        }
        return $out;
    }

    /** The template writes an unfilled field as "-"; a grid's rule row is all dashes. */
    private static function cashmaticValue(string $raw): string
    {
        $value = trim($raw);
        return preg_match('/^-+$/', $value) === 1 ? '' : $value;
    }

    /**
     * "Label: value" lines of a form email as [label => value], labels
     * lowercased. A value continues over following lines until a blank line or
     * the next label, so "Messaggio:" followed by a paragraph stays together.
     */
    private static function labelPairs(string $body): array
    {
        $pairs = [];
        $open  = null;
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if (preg_match('/^\s*[*\->•]?\s*([\p{L}][\p{L}\d \'._\/()-]{1,39}?)\s*[:：]\s*(.*)$/u', $line, $m)
                && !str_contains($m[1], '//')) {
                $label = mb_strtolower(trim($m[1]));
                $pairs[$label] = trim($m[2]);
                $open = $label;
            } elseif (trim($line) === '') {
                $open = null;
            } elseif ($open !== null) {
                $pairs[$open] .= ($pairs[$open] === '' ? '' : "\n") . trim($line);
            }
        }
        return $pairs;
    }

    /** Plain text of the message: text/plain part, else de-tagged HTML, else raw. */
    private static function bodyText($imap, int $seq): string
    {
        $s = imap_fetchstructure($imap, $seq);
        if (!isset($s->parts) || !$s->parts) {
            return self::toUtf8(self::decodePart(imap_body($imap, $seq), (int)($s->encoding ?? 0)));
        }
        $plain = self::findPart($imap, $seq, $s->parts, 'PLAIN');
        if ($plain !== null) {
            return self::toUtf8($plain);
        }
        $html = self::findPart($imap, $seq, $s->parts, 'HTML');
        if ($html !== null) {
            // <br> and block ends become newlines first, or the label lines of an
            // HTML-only form email would all run together into one.
            $html = preg_replace('/<(br|\/p|\/div|\/tr|\/li|\/h[1-6])[^>]*>/i', "$0\n", $html) ?? $html;
            return self::toUtf8(trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        return self::toUtf8(imap_body($imap, $seq));
    }

    private static function findPart($imap, int $seq, array $parts, string $want, string $prefix = ''): ?string
    {
        foreach ($parts as $idx => $p) {
            $sec = $prefix === '' ? (string)($idx + 1) : $prefix . '.' . ($idx + 1);
            if (($p->type ?? 1) === 0 && strtoupper($p->subtype ?? '') === $want) {
                return self::decodePart(imap_fetchbody($imap, $seq, $sec), (int)($p->encoding ?? 0));
            }
            if (!empty($p->parts)) {
                $found = self::findPart($imap, $seq, $p->parts, $want, $sec);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private static function decodePart(string $data, int $encoding): string
    {
        return match ($encoding) {
            3       => base64_decode($data) ?: $data,
            4       => quoted_printable_decode($data),
            default => $data,
        };
    }

    /** Italian mail is often latin-1; everything downstream expects UTF-8. */
    private static function toUtf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : (mb_convert_encoding($s, 'UTF-8', 'ISO-8859-15') ?: $s);
    }

    // ------------------------------------------------------------- addressing

    /** Bare address out of '"Mario Rossi" <mario@x.it>' (or just 'mario@x.it'). */
    private static function addrOf(string $from): string
    {
        if (preg_match('/<([^<>]+@[^<>]+)>/', $from, $m)) {
            return trim($m[1]);
        }
        return preg_match('/\S+@\S+/', $from, $m) ? trim($m[0], " \t\"'") : '';
    }

    private static function nameOf(string $from): string
    {
        $name = trim((string)preg_replace('/<[^>]*>/', '', $from), " \t\"'");
        return str_contains($name, '@') ? '' : $name;
    }

    private static function senderAllowed(string $addr, array $allowed): bool
    {
        foreach ($allowed as $ok) {
            $ok = mb_strtolower(trim((string)$ok));
            if ($ok === '') {
                continue;
            }
            // Full address, exact; bare domain matches the domain and subdomains.
            if (str_contains($ok, '@')
                ? strcasecmp($addr, $ok) === 0
                : (str_ends_with(mb_strtolower($addr), '@' . $ok) || str_ends_with(mb_strtolower($addr), '.' . $ok))) {
                return true;
            }
        }
        return false;
    }

    // ----------------------------------------------------------- bookkeeping

    /** Stable identity of a message across polls (POP3 has no server-side flags). */
    private static function messageKey(object $ov): string
    {
        $id = trim((string)($ov->message_id ?? ''));
        return sha1($id !== '' ? $id : (($ov->date ?? '') . '|' . ($ov->from ?? '')
            . '|' . ($ov->subject ?? '') . '|' . ($ov->size ?? '')));
    }

    /** @return array<string,true> every message_key already recorded */
    private static function knownKeys(): array
    {
        $keys = Db::pdo()->query('SELECT message_key FROM mail_intake')->fetchAll(\PDO::FETCH_COLUMN);
        return array_fill_keys($keys ?: [], true);
    }

    private static function record(array $row, string $status, ?string $reason, ?int $leadId): void
    {
        Db::pdo()->prepare(
            'INSERT INTO mail_intake
                (message_key, message_id, from_addr, subject, sent_at, status, reason, lead_id)
             VALUES (:message_key, :message_id, :from_addr, :subject, :sent_at, :status, :reason, :lead_id)
             ON DUPLICATE KEY UPDATE status = VALUES(status), reason = VALUES(reason),
                                     lead_id = COALESCE(VALUES(lead_id), lead_id)'
        )->execute($row + ['status' => $status, 'reason' => $reason, 'lead_id' => $leadId]);
    }

    private static function hasAny(array $pairs, array $keys): bool
    {
        foreach ($keys as $k) {
            if (isset($pairs[$k]) && trim((string)$pairs[$k]) !== '') {
                return true;
            }
        }
        return false;
    }
}
