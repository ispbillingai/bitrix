<?php
/** Mass WhatsApp/email campaigns. In scope: $t, $h, $pdo. */
$rows = $pdo->query("SELECT * FROM campaigns ORDER BY id DESC LIMIT 100")->fetchAll();
?>
<h2><?= $h($t('camp_title')) ?></h2>
<div class="warn"><?= $h($t('camp_warn')) ?></div>
<form method="post" class="card">
  <input type="hidden" name="do" value="create_campaign">
  <h3><?= $h($t('camp_new')) ?></h3>
  <div class="row">
    <label class="fld"><span><?= $h($t('camp_name')) ?></span><input name="name"></label>
    <label class="fld"><span><?= $h($t('camp_channel')) ?></span>
      <select name="channel"><option value="whatsapp">WhatsApp</option><option value="email">Email</option></select></label>
    <label class="fld"><span><?= $h($t('camp_subject')) ?></span><input name="subject"></label>
  </div>
  <label class="fld"><span><?= $h($t('camp_body')) ?></span><textarea name="body" rows="3" required></textarea></label>
  <label class="fld"><span><?= $h($t('camp_recipients')) ?></span><textarea name="recipients" rows="4" required></textarea></label>
  <button class="btn"><?= $h($t('camp_create')) ?></button>
</form>
<table><thead><tr>
  <th><?= $h($t('camp_name')) ?></th><th><?= $h($t('th_channel')) ?></th><th><?= $h($t('th_total')) ?></th>
  <th><?= $h($t('th_sent')) ?></th><th><?= $h($t('th_failed')) ?></th><th><?= $h($t('th_status')) ?></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr><td><?= $h($r['name']) ?></td><td><?= $h($r['channel']) ?></td><td><?= $h($r['total']) ?></td>
    <td><?= $h($r['sent']) ?></td><td><?= $h($r['failed']) ?></td><td><?= pill($h, $r['status']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
