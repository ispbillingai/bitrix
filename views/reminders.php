<?php
/**
 * Reminders queue — pending/all, with cancel. In scope: $t, $h, $pdo.
 */
$all = ($_GET['f'] ?? 'pending') === 'all';
$sql = "SELECT * FROM reminders " . ($all ? '' : "WHERE status='pending' ") . "ORDER BY due_at DESC LIMIT 300";
$rows = $pdo->query($sql)->fetchAll();
?>
<h2><?= $h($t('rem_title')) ?></h2>
<div class="tabs">
  <a class="<?= $all ? '' : 'on' ?>" href="?tab=reminders&f=pending"><?= $h($t('filter_pending')) ?></a>
  <a class="<?= $all ? 'on' : '' ?>" href="?tab=reminders&f=all"><?= $h($t('filter_all')) ?></a>
</div>
<table><thead><tr>
  <th><?= $h($t('th_due')) ?></th><th><?= $h($t('th_rule')) ?></th><th><?= $h($t('th_recipient')) ?></th>
  <th><?= $h($t('th_channel')) ?></th><th><?= $h($t('th_status')) ?></th><th></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr><td class="small"><?= $h($r['due_at']) ?></td>
    <td><?= $h(code_label($t, 'rk_', $r['rule_key'])) ?> <span class="muted small"><?= $h($r['entity_type']) ?> #<?= $h($r['entity_id']) ?></span></td>
    <td><?= $h(code_label($t, 'rcpt_', $r['recipient_type'])) ?></td><td><?= $h(code_label($t, 'chan_', $r['channel'])) ?></td>
    <td><?= pill($h, $r['status'], $t) ?></td>
    <td><?php if ($r['status'] === 'pending'): ?>
      <form method="post" class="inline"><input type="hidden" name="do" value="cancel_reminder">
      <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
      <button class="btn tiny ghost"><?= $h($t('cancel')) ?></button></form>
    <?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table>
