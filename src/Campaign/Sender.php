<?php
declare(strict_types=1);

namespace Glue\Campaign;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Notify\Skebby;
use Glue\Reminder\Templates;
use PDO;

// The photo/document a campaign carries, and who it goes to.
// (Campaign\Media and Campaign\Audience live beside this class.)

/**
 * Mass WhatsApp / email / SMS campaigns (requirement part2 #2 marketing).
 *
 * Recipients are stored per-campaign and sent in throttled batches by the cron
 * runner, so a huge list never blocks one request and we respect TextMeBot's
 * rate limit. Each send is logged to the messages outbox via Notifier.
 *
 * NOTE on "unlimited contacts": TextMeBot drives a single WhatsApp number, so
 * true unlimited mass marketing risks WhatsApp banning that number. This sends
 * reliably and throttled, but for compliant bulk marketing use an official
 * WhatsApp Business API template instead. See README.
 */
final class Sender
{
    private PDO $db;
    private Notifier $notifier;

    public function __construct()
    {
        $this->db = Db::pdo();
        $this->notifier = new Notifier();
    }

    /**
     * Create a campaign and queue its recipients. Returns campaign id.
     *
     * @param array      $recipients strings, or ['recipient'=>, 'name'=>, 'contact_id'=>]
     * @param array|null $media      Campaign\Media::store() — the photo or document
     */
    public function create(string $name, string $channel, string $body, ?string $subject, array $recipients,
                           string $lang = 'it', ?array $media = null, ?int $throttle = null): int
    {
        $channel = in_array($channel, self::CHANNELS, true) ? $channel : 'whatsapp';
        $lang = Templates::lang($lang);
        $stmt = $this->db->prepare(
            'INSERT INTO campaigns (name, channel, lang, subject, body, media_path, media_name, media_mime, media_kind,
                                    throttle_seconds, status, total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "running", ?)'
        );
        $stmt->execute([$name, $channel, $lang, $subject, $body,
            $media['path'] ?? null, $media['name'] ?? null, $media['mime'] ?? null, $media['kind'] ?? null,
            $throttle !== null ? self::clampThrottle($throttle) : null,
            count($recipients)]);
        $id = (int)$this->db->lastInsertId();

        $ins = $this->db->prepare(
            'INSERT INTO campaign_recipients (campaign_id, contact_id, recipient, name) VALUES (?, ?, ?, ?)'
        );
        foreach ($recipients as $r) {
            $to = is_array($r) ? ($r['recipient'] ?? '') : $r;
            $rn = is_array($r) ? ($r['name'] ?? null) : null;
            $cid = is_array($r) ? (int)($r['contact_id'] ?? 0) : 0;
            if (trim((string)$to) !== '') {
                $ins->execute([$id, $cid ?: null, trim((string)$to), $rn]);
            }
        }
        Log::write('campaign', 'campaign_created', null, $id,
            ['name' => $name, 'total' => count($recipients), 'media' => $media['kind'] ?? null]);
        return $id;
    }

    /** What the office may set as the pace: 0 (only the gateway's own gap) to an hour. */
    public static function clampThrottle(int $seconds): int
    {
        return max(0, min(3600, $seconds));
    }

    /** What a campaign waits between messages when nobody has said otherwise. */
    public const DEFAULT_THROTTLE = 120;

    /** The pace a campaign sends at: its own if it has one, otherwise the setting. */
    public static function throttleFor(?array $campaign = null): int
    {
        if ($campaign !== null && ($campaign['throttle_seconds'] ?? null) !== null) {
            return self::clampThrottle((int)$campaign['throttle_seconds']);
        }
        return self::clampThrottle((int)Config::get('textmebot.campaign_throttle_seconds', self::DEFAULT_THROTTLE));
    }

    /**
     * The three ways a campaign can go out. SMS is the one that has to be
     * switched on first (Impostazioni → SMS → "Campagne pubblicitarie"): it is
     * a paid gateway, so nobody discovers it by accident.
     */
    public const CHANNELS = ['whatsapp', 'email', 'sms'];

    /** True when this office may send a campaign by SMS at all. */
    public static function smsAvailable(): bool
    {
        return Skebby::uses('campaigns');
    }

    /**
     * Only WhatsApp is paced. Email and SMS leave through a gateway built for
     * volume: the waiting is there to keep one WhatsApp number from being
     * banned, and nothing else.
     */
    public static function paced(string $channel): bool
    {
        return $channel === 'whatsapp';
    }

    /**
     * Send one throttled batch for every running campaign. Call from cron each
     * minute; $batch limits how many go out per invocation (× cron frequency).
     *
     * $maxSeconds stops the run once that much wall clock has gone — for the
     * "run now" button, which is a web request and must not sit sleeping
     * through a long campaign. 0 = no limit, which is what cron uses (its own
     * flock keeps the next minute's run from overlapping).
     */
    public function runBatch(int $batch = 30, int $maxSeconds = 0): array
    {
        $started = time();
        $outOfTime = false;
        $stmt = $this->db->query("SELECT * FROM campaigns WHERE status='running' ORDER BY id ASC");
        $summary = [];

        foreach ($stmt->fetchAll() as $c) {
            $cid = (int)$c['id'];
            // The tick was taken away after this campaign was made: leave the
            // rest of it queued rather than burn credit — or fail every line —
            // behind the office's back. Ticking it again resumes the campaign.
            if ($c['channel'] === 'sms' && !self::smsAvailable()) {
                $summary[$cid] = ['sent' => 0, 'failed' => 0, 'held' => 'sms_off'];
                continue;
            }
            $recs = $this->db->prepare(
                "SELECT * FROM campaign_recipients WHERE campaign_id=? AND status='pending' ORDER BY id ASC LIMIT ?"
            );
            $recs->bindValue(1, $cid, PDO::PARAM_INT);
            $recs->bindValue(2, $batch, PDO::PARAM_INT);
            $recs->execute();
            $rows = $recs->fetchAll();

            // The attachment travels the same way for everyone on this campaign:
            // WhatsApp fetches it by URL, email carries the bytes.
            $mediaUrl  = !empty($c['media_path']) ? Media::url((string)$c['media_path']) : null;
            $mediaKind = (string)($c['media_kind'] ?? '') === 'document' ? 'document' : 'image';
            $attach    = !empty($c['media_path'])
                ? [['path' => Media::fullPath((string)$c['media_path']),
                    'name' => (string)($c['media_name'] ?: basename((string)$c['media_path'])),
                    'mime' => (string)($c['media_mime'] ?: 'application/octet-stream'),
                    'url'  => $mediaUrl]]   // too big to post? then it goes as a link
                : [];

            // This campaign's own pace, or the one set in Impostazioni.
            $throttle = self::throttleFor($c);

            // The pace has to hold ACROSS runs too, not only inside one batch.
            // Cron calls this every minute with a budget, so a batch that ends
            // on a message would otherwise be followed a minute later by the
            // next one — a campaign set to two minutes would send two of them
            // sixty seconds apart, which is the kind of burst that gets the
            // number blocked. Too soon: leave this campaign for a later tick.
            if ($throttle > 0 && self::paced((string)$c['channel']) && $rows) {
                $since = $this->db->prepare(
                    "SELECT UNIX_TIMESTAMP(MAX(sent_at)) FROM campaign_recipients
                      WHERE campaign_id = ? AND sent_at IS NOT NULL"
                );
                $since->execute([$cid]);
                $lastAt = (int)($since->fetchColumn() ?: 0);
                if ($lastAt > 0 && time() - $lastAt < $throttle) {
                    continue;
                }
            }

            $sent = $failed = 0;
            $last = count($rows) - 1;
            foreach ($rows as $i => $r) {
                $vars = ['name' => trim((string)($r['name'] ?? '')), 'company' => Config::get('mail.from_name', '')];
                $body = Templates::render((string)$c['body'], $vars);
                // A number typed by hand carries no name, and "Ciao {name}," then
                // reaches the customer as "Ciao ," — or, as it did, as the English
                // "Ciao there,". Close the gap the empty placeholder leaves.
                $body = trim((string)preg_replace(
                    ['/[ \t]{2,}/', '/[ \t]+([,.;:!?])/', '/[ \t]+$/m'], [' ', '$1', ''], $body));

                // An SMS carries text and nothing else — no subject, no
                // attachment — which is why the form hides both for it.
                if ($c['channel'] === 'email') {
                    $ok = $this->notifier->email($r['recipient'], (string)($c['subject'] ?? ''), $body, null, $cid, $attach);
                } elseif ($c['channel'] === 'sms') {
                    $ok = $this->notifier->sms($r['recipient'], $body, null, $cid);
                } else {
                    $ok = $this->notifier->whatsapp($r['recipient'], $body, null, $cid, $mediaUrl, $mediaKind);
                }

                $this->db->prepare(
                    "UPDATE campaign_recipients SET status=?, sent_at=NOW() WHERE id=?"
                )->execute([$ok ? 'sent' : 'failed', $r['id']]);
                $ok ? $sent++ : $failed++;

                // Waiting AFTER the last message of the batch buys nothing: the
                // gateway's own gap already spaces whatever comes next.
                if ($i === $last) {
                    break;
                }
                $wait = self::paced((string)$c['channel']) ? $throttle : 0;
                // Count the wait we are about to take, not only the time already
                // spent: a 5-minute pace must not hold the "send now" request
                // open for five minutes. The rest stays pending for the next run.
                if ($maxSeconds > 0 && (time() - $started) + $wait >= $maxSeconds) {
                    $outOfTime = true;
                    break;
                }
                if ($wait > 0) {
                    sleep($wait);
                }
            }

            $this->db->prepare(
                'UPDATE campaigns SET sent=sent+?, failed=failed+? WHERE id=?'
            )->execute([$sent, $failed, $cid]);

            // Mark done when nothing pending remains.
            $left = $this->db->prepare(
                "SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id=? AND status='pending'"
            );
            $left->execute([$cid]);
            if ((int)$left->fetchColumn() === 0) {
                $this->db->prepare("UPDATE campaigns SET status='done' WHERE id=?")->execute([$cid]);
            }
            $summary[$cid] = ['sent' => $sent, 'failed' => $failed, 'throttle' => $throttle];
            if ($outOfTime || ($maxSeconds > 0 && time() - $started >= $maxSeconds)) {
                break;   // out of time: the other campaigns wait for the next run
            }
        }
        return $summary;
    }
}
