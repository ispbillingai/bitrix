<?php
/**
 * Appointments — requests come in (from the public form/webhook); staff assign a
 * seller and confirm the real time, which schedules reminders to both parties.
 * In scope: $t, $h, $pdo, $agents, $uid.
 */
$rows = \Glue\Crm\Appointments::all(300, $scopeId ?? null);
?>
<h2><?= $h($t('nav_appointments')) ?></h2>

<?php if (empty($isAgent)): ?>
<details class="drawer">
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('appointments') ?> <?= $h($t('appt_new')) ?></summary>
  <form method="post" class="card" style="margin-top:12px">
    <input type="hidden" name="do" value="appt_create">
    <div class="row">
      <label class="fld"><span><?= $h($t('f_name')) ?></span><input name="name" required></label>
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone"></label>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('appt_preferred')) ?></span><input type="datetime-local" name="preferred_at"></label>
      <label class="fld"><span><?= $h($t('f_title')) ?></span><input name="title"></label>
    </div>
    <label class="fld"><span><?= $h($t('f_notes')) ?></span><textarea name="notes" rows="2"></textarea></label>
    <button class="btn"><?= $h($t('save')) ?></button>
  </form>
</details>
<?php endif; ?>

<table><thead><tr>
  <th><?= $h($t('th_customer')) ?></th><th><?= $h($t('appt_when')) ?></th><th><?= $h($t('th_agent')) ?></th>
  <th><?= $h($t('th_status')) ?></th><th></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="5" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r):
    $ag = $r['agent_name'] ?: $r['agent_username'];
    $when = $r['starts_at'] ?: $r['preferred_at']; ?>
  <tr>
    <td><b><?= $h($r['customer_name'] ?: ('#' . $r['id'])) ?></b><br>
      <span class="muted small"><?= $h($r['customer_phone']) ?> <?= $h($r['customer_email']) ?></span></td>
    <td><?= $when ? $h(date('D j M Y, H:i', strtotime($when))) : '<span class="muted">—</span>' ?>
      <?php if (!$r['starts_at'] && $r['preferred_at']): ?><br><span class="muted small"><?= $h($t('appt_pref_label')) ?></span><?php endif; ?></td>
    <td><?= $ag ? $h($ag) : '<span class="muted">—</span>' ?></td>
    <td><?= pill($h, $r['status'], $t) ?></td>
    <td style="white-space:nowrap">
      <details class="drawer"><summary class="btn ghost tiny"><?= $h($t('manage')) ?></summary>
        <form method="post" class="card" style="margin-top:10px;min-width:320px">
          <input type="hidden" name="do" value="appt_schedule"><input type="hidden" name="id" value="<?= $h($r['id']) ?>">
          <label class="fld"><span><?= $h($t('assign_seller')) ?></span><?php agent_select($h, $agents, 'agent_id', $r['agent_id'], $t('unassigned')); ?></label>
          <label class="fld"><span><?= $h($t('appt_confirm_time')) ?></span>
            <input type="datetime-local" name="starts_at" value="<?= $h($when ? date('Y-m-d\TH:i', strtotime($when)) : '') ?>" required></label>
          <label class="fld"><span><?= $h($t('appt_location')) ?></span><input name="location" value="<?= $h($r['location']) ?>"></label>
          <button class="btn tiny"><?= svg('check') ?> <?= $h($t('appt_confirm')) ?></button>
        </form>
        <div style="margin-top:8px">
          <?php foreach (['done' => 'appt_done', 'cancelled' => 'appt_cancel', 'no_show' => 'appt_noshow'] as $st => $key): ?>
            <form method="post" class="inline"><input type="hidden" name="do" value="appt_status">
              <input type="hidden" name="id" value="<?= $h($r['id']) ?>"><input type="hidden" name="status" value="<?= $st ?>">
              <button class="btn tiny ghost"><?= $h($t($key)) ?></button></form>
          <?php endforeach; ?>
        </div>
      </details>
    </td>
  </tr>
<?php endforeach; ?>
</tbody></table>
