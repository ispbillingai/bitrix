<?php
/**
 * Support — the assistance requests from the portal and the public form.
 * Every open request reached all the technicians by WhatsApp/email; this is
 * where exactly one of them takes charge (first press wins — the claim is
 * atomic). Admins additionally see the held-for-payment queue with the
 * forward-now waiver and the cancel button.
 *
 * In scope: $t, $h, $uid, $isAgent, $isTech.
 */

use Glue\Portal\AssistRequests;

$isAdminHere = !$isAgent && !$isTech;
$asRows = AssistRequests::listAll(200);

$stColor = ['open' => 'var(--accent)', 'taken' => 'var(--green)',
            'awaiting_payment' => 'var(--amber)', 'cancelled' => 'var(--muted)'];
?>
<h2><?= $h($t('nav_support')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('as_sub')) ?></p>

<?php if (!$asRows): ?><div class="empty"><?= $h($t('as_none')) ?></div><?php else: ?>
<table>
  <thead><tr>
    <th>#</th><th><?= $h($t('th_customer')) ?></th><th><?= $h($t('tk_subject_l')) ?></th>
    <th><?= $h($t('as_priority')) ?></th><th><?= $h($t('th_status')) ?></th>
    <th><?= $h($t('as_taken_by')) ?></th><th><?= $h($t('th_created')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($asRows as $ar): $st = (string)$ar['status']; ?>
    <tr>
      <td class="muted"><?= (int)$ar['id'] ?></td>
      <td><b><?= $h($ar['customer_name']) ?></b>
        <div class="muted small">
          <?= $h($ar['contact_phone'] ?: ($ar['registry_phone'] ?: '')) ?>
          <?php if (!empty($ar['vat_number'])): ?> · <?= $h($t('cu_vat')) ?> <?= $h($ar['vat_number']) ?><?php endif; ?>
        </div></td>
      <td><?= $h($ar['subject']) ?>
        <?php if (!empty($ar['body'])): ?>
          <div class="muted small" style="max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= $h(mb_substr((string)$ar['body'], 0, 160)) ?></div>
        <?php endif; ?></td>
      <td><span class="pill" style="color:<?= $ar['priority'] === 'business_hours' ? 'var(--muted)' : 'var(--green)' ?>">
        <?= $h($t($ar['priority'] === 'business_hours' ? 'as_p_hours' : 'as_p_priority')) ?></span></td>
      <td><span class="pill" style="color:<?= $stColor[$st] ?? 'var(--muted)' ?>"><?= $h($t('as_st_' . $st)) ?></span>
        <?php if ($st === 'awaiting_payment' && !empty($ar['reference'])): ?>
          <div class="small"><a href="?tab=payments&q=<?= urlencode((string)$ar['reference']) ?>"><?= $h($ar['reference']) ?></a></div>
        <?php endif; ?></td>
      <td class="small"><?= $h($ar['claimer_name'] ?: ($ar['claimer_username'] ?: '—')) ?>
        <?php if (!empty($ar['claimed_at'])): ?>
          <div class="muted small"><?= $h(short_time($ar['claimed_at'])) ?></div>
        <?php endif; ?></td>
      <td class="small muted"><?= $h(short_time($ar['created_at'])) ?></td>
      <td style="text-align:right;white-space:nowrap">
        <?php if ($st === 'open'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= $h($t('as_claim_confirm')) ?>')">
            <input type="hidden" name="do" value="assist_claim"><input type="hidden" name="id" value="<?= (int)$ar['id'] ?>">
            <button class="btn tiny"><?= $h($t('as_claim')) ?></button>
          </form>
        <?php endif; ?>
        <?php if ($isAdminHere && $st === 'awaiting_payment'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= $h($t('as_forward_confirm')) ?>')">
            <input type="hidden" name="do" value="assist_forward"><input type="hidden" name="id" value="<?= (int)$ar['id'] ?>">
            <button class="btn ghost tiny"><?= $h($t('as_forward')) ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= $h($t('as_cancel_confirm')) ?>')">
            <input type="hidden" name="do" value="assist_cancel"><input type="hidden" name="id" value="<?= (int)$ar['id'] ?>">
            <button class="btn ghost tiny" style="color:var(--red)"><?= $h($t('as_cancel')) ?></button>
          </form>
        <?php endif; ?>
        <?php if (!empty($ar['ticket_id']) && ($isAdminHere || ($isTech && (int)$ar['claimed_by'] === (int)$uid))): ?>
          <a class="btn ghost tiny" href="?tab=tickets&tk=<?= (int)$ar['ticket_id'] ?>"><?= $h($t('cu_open')) ?></a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
