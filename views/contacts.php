<?php
/**
 * Contacts — the people behind leads/deals. Create + recent list.
 * In scope: $t, $h, $pdo.
 */
$rows = \Glue\Crm\Contacts::all(300);
?>
<h2><?= $h($t('nav_contacts')) ?></h2>

<details class="drawer">
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('contacts') ?> <?= $h($t('contact_new')) ?></summary>
  <form method="post" class="card" style="margin-top:12px">
    <input type="hidden" name="do" value="contact_create">
    <div class="row">
      <label class="fld"><span><?= $h($t('f_name')) ?></span><input name="name" required></label>
      <label class="fld"><span><?= $h($t('f_company')) ?></span><input name="company"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone"></label>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email"></label>
      <label class="fld"><span><?= $h($t('f_lang')) ?></span>
        <select name="lang"><option value="it">IT</option><option value="en">EN</option></select></label>
    </div>
    <label class="fld"><span><?= $h($t('f_notes')) ?></span><textarea name="notes" rows="2"></textarea></label>
    <button class="btn"><?= $h($t('save')) ?></button>
  </form>
</details>

<table><thead><tr>
  <th><?= $h($t('f_name')) ?></th><th><?= $h($t('f_company')) ?></th><th><?= $h($t('f_phone')) ?></th>
  <th><?= $h($t('f_email')) ?></th><th><?= $h($t('f_lang')) ?></th><th><?= $h($t('th_created')) ?></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr>
    <td><?= avatar($h, $r['name']) ?> <?= $h($r['name']) ?></td>
    <td><?= $h($r['company']) ?></td><td><?= phone_link($h, $r['phone']) ?></td><td><?= $h($r['email']) ?></td>
    <td><span class="pill"><?= $h($r['lang']) ?></span></td><td class="small muted"><?= $h(short_time($r['created_at'])) ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table>
