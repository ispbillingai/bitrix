<?php
declare(strict_types=1);

namespace Glue\Campaign;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Reminder\Templates;
use PDO;

// The photo/document a campaign carries, and who it goes to.
// (Campaign\Media and Campaign\Audience live beside this class.)

/**
 * Mass WhatsApp / email campaigns (requirement part2 #2 marketing).
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
                           string $lang = 'it', ?array $media = null): int
    {
        $channel = $channel === 'email' ? 'email' : 'whatsapp';
        $lang = Templates::lang($lang);
        $stmt = $this->db->prepare(
            'INSERT INTO campaigns (name, channel, lang, subject, body, media_path, media_name, media_mime, media_kind, status, total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "running", ?)'
        );
        $stmt->execute([$name, $channel, $lang, $subject, $body,
            $media['path'] ?? null, $media['name'] ?? null, $media['mime'] ?? null, $media['kind'] ?? null,
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

    /**
     * Send one throttled batch for every running campaign. Call from cron each
     * minute; $batch limits how many go out per invocation (× cron frequency).
     */
    public function runBatch(int $batch = 30): array
    {
        $throttle = (int)Config::get('textmebot.campaign_throttle_seconds', 8);
        $stmt = $this->db->query("SELECT * FROM campaigns WHERE status='running' ORDER BY id ASC");
        $summary = [];

        foreach ($stmt->fetchAll() as $c) {
            $cid = (int)$c['id'];
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

            $sent = $failed = 0;
            foreach ($rows as $r) {
                $vars = ['name' => $r['name'] ?: 'there', 'company' => Config::get('mail.from_name', '')];
                $body = Templates::render((string)$c['body'], $vars);

                $ok = $c['channel'] === 'email'
                    ? $this->notifier->email($r['recipient'], (string)($c['subject'] ?? ''), $body, null, $cid, $attach)
                    : $this->notifier->whatsapp($r['recipient'], $body, null, $cid, $mediaUrl, $mediaKind);

                $this->db->prepare(
                    "UPDATE campaign_recipients SET status=?, sent_at=NOW() WHERE id=?"
                )->execute([$ok ? 'sent' : 'failed', $r['id']]);
                $ok ? $sent++ : $failed++;

                if ($c['channel'] === 'whatsapp' && $throttle > 0) {
                    sleep($throttle);
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
            $summary[$cid] = ['sent' => $sent, 'failed' => $failed];
        }
        return $summary;
    }
}
