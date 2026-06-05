<?php
/** Messages outbox — every WhatsApp/email send. In scope: $t, $h, $pdo. */
$rows = $pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 300")->fetchAll();
?>
<h2><?= $h($t('msg_title')) ?></h2>
<table><thead><tr>
  <th><?= $h($t('th_time')) ?></th><th><?= $h($t('th_channel')) ?></th><th><?= $h($t('th_recipient')) ?></th>
  <th><?= $h($t('th_subject')) ?></th><th><?= $h($t('th_status')) ?></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="5" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr><td class="small"><?= $h($r['created_at']) ?></td><td><?= $h($r['channel']) ?></td>
    <td><?= $h($r['recipient']) ?></td><td class="small"><?= $h($r['subject']) ?></td>
    <td><?= pill($h, $r['status']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
