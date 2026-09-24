<?php
/**
 * Archivio sopralluoghi — the ones that have been put away.
 *
 * Same list as the working one, read from the other side of archived_at, with
 * "Ripristina" instead of "Archivia". Nothing here is deleted: a survey the
 * customer signed is evidence, so archiving only ever moves it between two
 * lists and can be undone.
 *
 * Scoped exactly like the working list — a seller sees the ones they opened, a
 * technician theirs and the ones they took in charge, the office everything.
 *
 * In scope: $t, $h, $uid, $isAgent, $isTech.
 */

use Glue\Inspect\Inspections;

$isAdminHere = !$isAgent && !$isTech;
$ownScope  = $isAdminHere ? null : (int)$uid;
$rows = Inspections::all(300, $ownScope, false, true);

$statusColor = ['draft' => 'var(--muted)', 'sent' => 'var(--accent)',
                'signed' => 'var(--amber)', 'reviewed' => 'var(--green)'];
?>
<h2><?= $h($t('nav_insp_archive')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('ispa_sub')) ?></p>

<p><a class="btn ghost tiny" href="?tab=inspections">&larr; <?= $h($t('ispa_back')) ?></a></p>

<?php if (!$rows): ?>
  <div class="empty"><?= $h($t('ispa_none')) ?></div>
<?php else: ?>
<table class="acts-pinned"><thead><tr>
  <th>#</th><th><?= $h($t('th_customer')) ?></th><th><?= $h($t('isp_f_site')) ?></th>
  <th><?= $h($t('th_status')) ?></th><th><?= $h($t('isp_stars')) ?></th>
  <th><?= $h($t('ispa_when')) ?></th><th></th>
</tr></thead><tbody>
<?php foreach ($rows as $row): $st = (string)$row['status']; ?>
  <tr>
    <td class="muted"><?= (int)$row['id'] ?></td>
    <td><b><?= $h($row['customer_name']) ?></b>
      <?php if (!empty($row['company'])): ?><div class="muted small"><?= $h($row['company']) ?></div><?php endif; ?></td>
    <td class="small"><?= $h($row['site_address'] ?: '—') ?></td>
    <td><span class="pill" style="color:<?= $statusColor[$st] ?? 'var(--muted)' ?>"><?= $h($t('isp_st_' . $st)) ?></span>
      <?php if (!empty($row['offer_requested_at'])): ?>
        <div class="small" style="color:<?= empty($row['offer_handled_at']) ? 'var(--amber)' : 'var(--green)' ?>">
          💶 <?= $h($t(empty($row['offer_handled_at']) ? 'isp_offer_badge' : 'isp_offer_badge_done')) ?></div>
      <?php endif; ?></td>
    <td class="small"><?= $h(Inspections::starBar((int)($row['opinion_stars'] ?? 0)) ?: '—') ?></td>
    <td class="small muted" style="white-space:nowrap"><?= $h(date('d/m/Y', strtotime((string)$row['archived_at']))) ?></td>
    <td style="text-align:right;white-space:nowrap">
      <a class="btn ghost tiny" href="?tab=inspections&id=<?= (int)$row['id'] ?>"><?= $h($t('cu_open')) ?></a>
      <form method="post" class="inline">
        <input type="hidden" name="do" value="insp_archive">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <input type="hidden" name="archived" value="0">
        <button class="btn tiny"><?= $h($t('ispa_restore')) ?></button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
