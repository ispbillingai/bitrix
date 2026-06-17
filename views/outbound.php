<?php
/**
 * Outbound — a single place to see every outgoing email / WhatsApp: what was
 * sent, what failed (with the provider's exact reason), and what is still queued
 * waiting to be dispatched. Admin-only. In scope: $t, $h, $pdo.
 *
 * It unions two tables:
 *   - messages : actual send attempts (status sent|failed, provider_response)
 *   - reminders: pending automations not yet dispatched (status pending) so a
 *     queued welcome/reminder is visible even before anything is sent.
 */
$filter = $_GET['f'] ?? 'all'; // all | failed | queued | whatsapp | email

// --- counts for the summary tiles ---
$cSent   = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE status='sent'")->fetchColumn();
$cFailed = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE status='failed'")->fetchColumn();
$cQueued = (int)$pdo->query("SELECT COUNT(*) FROM reminders WHERE status='pending'")->fetchColumn();

// --- sent / failed attempts (the real outbox) ---
$rows = [];
if ($filter !== 'queued') {
    $where = [];
    if ($filter === 'failed')        { $where[] = "status='failed'"; }
    if ($filter === 'whatsapp')      { $where[] = "channel='whatsapp'"; }
    if ($filter === 'email')         { $where[] = "channel='email'"; }
    $sql = "SELECT id, created_at, channel, recipient, subject, body, status, provider_response
            FROM messages" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY id DESC LIMIT 300";
    foreach ($pdo->query($sql)->fetchAll() as $m) {
        $res = $m['provider_response'] ? json_decode($m['provider_response'], true) : null;
        // Email bodies are HTML — strip tags so the outbox shows readable text;
        // WhatsApp bodies are already plain text.
        $bodyText = (string)($m['body'] ?? '');
        if ($m['channel'] === 'email') {
            $bodyText = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $bodyText)), ENT_QUOTES, 'UTF-8'));
        }
        $rows[] = [
            'kind'      => 'msg',
            'time'      => $m['created_at'],
            'channel'   => $m['channel'],
            'recipient' => $m['recipient'],
            'subject'   => $m['subject'],
            'body'      => $bodyText,
            'status'    => $m['status'],
            'reason'    => ($m['status'] === 'failed' && is_array($res)) ? test_reason($res) : '',
        ];
    }
}

// --- queued (pending reminders not yet sent) ---
$queued = [];
if ($filter === 'all' || $filter === 'queued') {
    $q = $pdo->query("SELECT id, due_at, channel, recipient_type, rule_key, entity_type, entity_id, last_error
                      FROM reminders WHERE status='pending' ORDER BY due_at ASC LIMIT 300")->fetchAll();
    foreach ($q as $r) {
        $queued[] = [
            'kind'      => 'queued',
            'time'      => $r['due_at'],
            'channel'   => $r['channel'],
            'recipient' => code_label($t, 'rcpt_', $r['recipient_type'])
                           . ' · ' . code_label($t, 'rk_', $r['rule_key']),
            'subject'   => $r['entity_type'] . ' #' . $r['entity_id'],
            'body'      => '', // not rendered until sent
            'status'    => 'pending',
            'reason'    => $r['last_error'] ? (string)$r['last_error'] : $t('ob_queued_note'),
        ];
    }
}
?>
<h2><?= $h($t('ob_title')) ?></h2>
<p class="muted small"><?= $h($t('ob_intro')) ?></p>

<div class="grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:18px">
  <?php
  num_card($h, 'check', $t('ob_sent'), $cSent);
  num_card($h, 'alert', $t('ob_failed'), $cFailed);
  num_card($h, 'clock', $t('ob_queued'), $cQueued);
  ?>
</div>

<div class="tabs">
  <?php
  $tabs = ['all' => 'ob_f_all', 'failed' => 'ob_f_failed', 'queued' => 'ob_f_queued',
           'whatsapp' => 'ob_f_whatsapp', 'email' => 'ob_f_email'];
  foreach ($tabs as $key => $label): ?>
    <a class="<?= $filter === $key ? 'on' : '' ?>" href="?tab=outbound&f=<?= $h($key) ?>"><?= $h($t($label)) ?></a>
  <?php endforeach; ?>
</div>

<table><thead><tr>
  <th><?= $h($t('th_time')) ?></th><th><?= $h($t('th_channel')) ?></th><th><?= $h($t('th_recipient')) ?></th>
  <th><?= $h($t('th_message')) ?></th><th><?= $h($t('th_status')) ?></th><th><?= $h($t('th_reason')) ?></th>
</tr></thead><tbody>
<?php
// Queued items first (they're upcoming), then the send history.
$all = ($filter === 'queued') ? $queued : array_merge($queued, $rows);
?>
<?php if (!$all): ?><tr><td colspan="6" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($all as $r): $body = (string)($r['body'] ?? ''); ?>
  <tr>
    <td class="small"><?= $h($r['time']) ?><?php if ($r['kind'] === 'queued'): ?>
      <span class="muted">(<?= $h($t('ob_when_due')) ?>)</span><?php endif; ?></td>
    <td><?= $h(code_label($t, 'chan_', $r['channel'])) ?></td>
    <td class="small"><?= $h($r['recipient']) ?></td>
    <td class="small" style="max-width:420px">
      <?php if ($r['subject'] !== ''): ?><div><?= $h($r['subject']) ?></div><?php endif; ?>
      <?php if ($body !== ''): ?>
        <div class="muted" style="white-space:pre-wrap;margin-top:<?= $r['subject'] !== '' ? '5px' : '0' ?>;line-height:1.5"><?= $h($body) ?></div>
      <?php endif; ?>
    </td>
    <td><?= pill($h, $r['status'], $t) ?></td>
    <td class="small <?= $r['status'] === 'failed' ? 'reason-err' : 'muted' ?>"><?= $h($r['reason']) ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table>
