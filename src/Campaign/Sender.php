<?php
declare(strict_types=1);

namespace Glue\Campaign;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Reminder\Templates;
use PDO;

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

    /** Create a campaign and queue its recipients. Returns campaign id. */
    public function create(string $name, string $channel, string $body, ?string $subject, array $recipients): int
    {
        $channel = $channel === 'email' ? 'email' : 'whatsapp';
        $stmt = $this->db->prepare(
            'INSERT INTO campaigns (name, channel, subject, body, status, total)
             VALUES (?, ?, ?, ?, "running", ?)'
        );
        $stmt->execute([$name, $channel, $subject, $body, count($recipients)]);
        $id = (int)$this->db->lastInsertId();

        $ins = $this->db->prepare(
            'INSERT INTO campaign_recipients (campaign_id, recipient, name) VALUES (?, ?, ?)'
        );
        foreach ($recipients as $r) {
            $to = is_array($r) ? ($r['recipient'] ?? '') : $r;
            $rn = is_array($r) ? ($r['name'] ?? null) : null;
            if (trim((string)$to) !== '') {
                $ins->execute([$id, trim((string)$to), $rn]);
            }
        }
        Log::write('campaign', 'campaign_created', null, $id, ['name' => $name, 'total' => count($recipients)]);
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

            $sent = $failed = 0;
            foreach ($rows as $r) {
                $vars = ['name' => $r['name'] ?: 'there', 'company' => Config::get('mail.from_name', '')];
                $body = Templates::render((string)$c['body'], $vars);

                $ok = $c['channel'] === 'email'
                    ? $this->notifier->email($r['recipient'], (string)($c['subject'] ?? ''), $body, null, $cid)
                    : $this->notifier->whatsapp($r['recipient'], $body, null, $cid);

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
