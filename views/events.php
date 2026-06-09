<?php
/** Activity / audit log (events table). In scope: $t, $h, $pdo. */
$rows = $pdo->query("SELECT * FROM events ORDER BY id DESC LIMIT 300")->fetchAll();
?>
<h2><?= $h($t('ev_title')) ?></h2>
<table><thead><tr>
  <th><?= $h($t('th_time')) ?></th><th><?= $h($t('th_source')) ?></th><th><?= $h($t('th_event')) ?></th><th><?= $h($t('th_entity')) ?></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="4" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr><td class="small"><?= $h($r['created_at']) ?></td><td><?= $h(code_label($t, 'src_', $r['source'])) ?></td>
    <td><?= $h(code_label($t, 'evt_', $r['event_type'])) ?></td><td class="small"><?= $h($r['entity_type']) ?> <?= $h($r['entity_id']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
