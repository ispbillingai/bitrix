<?php
/**
 * Messages — customer conversations (read & reply, no email needed) on top,
 * then the delivery outbox of every WhatsApp/email send below.
 * In scope: $t, $h, $pdo, $scopeId, $isAgent.
 */
$ticketBack = 'messages'; // replies from this page come back here
include __DIR__ . '/tickets.php';
?>

<?php if (empty($isAgent)): // the global delivery outbox is admin-only ?>
<h2 style="margin-top:30px"><?= $h($t('msg_title')) ?></h2>
<?php $rows = $pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 300")->fetchAll(); ?>
<table><thead><tr>
  <th><?= $h($t('th_time')) ?></th><th><?= $h($t('th_channel')) ?></th><th><?= $h($t('th_recipient')) ?></th>
  <th><?= $h($t('th_subject')) ?></th><th><?= $h($t('th_status')) ?></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="5" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr><td class="small"><?= $h($r['created_at']) ?></td><td><?= $h(code_label($t, 'chan_', $r['channel'])) ?></td>
    <td><?= $h($r['recipient']) ?></td><td class="small"><?= $h($r['subject']) ?></td>
    <td><?= pill($h, $r['status'], $t) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
