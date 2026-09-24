<?php
/**
 * Richieste di offerta — where every request a customer sends off the back of a
 * survey opinion arrives.
 *
 * Open ones first, oldest first within that: the request that has been waiting
 * longest is the one somebody should pick up. Marking one dealt with is what
 * makes this a queue rather than a log, and it is reversible — somebody ticks
 * the wrong row eventually.
 *
 * In scope: $t, $h, $uid, $isAgent, $isTech.
 */

use Glue\Inspect\Inspections;

$showAll = ($_GET['all'] ?? '') === '1';
$rows = Inspections::offerRequests(200, !$showAll);
$open = Inspections::openOfferCount();
?>
<h2><?= $h($t('nav_offers')) ?><?= $open > 0 ? ' <span class="pill" style="color:var(--amber)">' . (int)$open . '</span>' : '' ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('off_sub')) ?></p>

<div style="margin-bottom:12px">
  <a class="btn <?= $showAll ? 'ghost' : '' ?> tiny" href="?tab=offers"><?= $h($t('off_open_only')) ?></a>
  <a class="btn <?= $showAll ? '' : 'ghost' ?> tiny" href="?tab=offers&all=1"><?= $h($t('off_all')) ?></a>
</div>

<?php if (!$rows): ?>
  <div class="empty"><?= $h($t($showAll ? 'off_none' : 'off_none_open')) ?></div>
<?php else: ?>
<table class="acts-pinned"><thead><tr>
  <th><?= $h($t('off_when')) ?></th><th><?= $h($t('th_customer')) ?></th>
  <th><?= $h($t('isp_stars')) ?></th><th><?= $h($t('off_note')) ?></th>
  <th><?= $h($t('th_status')) ?></th><th></th>
</tr></thead><tbody>
<?php foreach ($rows as $r):
    $isOpen = empty($r['offer_handled_at']); ?>
  <tr<?= $isOpen ? ' style="background:color-mix(in srgb,var(--amber) 8%,transparent)"' : '' ?>>
    <td class="small" style="white-space:nowrap">
      <?= $h(date('d/m/Y H:i', strtotime((string)$r['offer_requested_at']))) ?>
      <div class="muted small">#<?= (int)$r['id'] ?></div></td>
    <td><b><?= $h($r['customer_name']) ?></b>
      <div class="muted small">
        <?php if (!empty($r['company']) && $r['company'] !== $r['customer_name']): ?><?= $h($r['company']) ?> · <?php endif; ?>
        <?= phone_link($h, $r['phone']) ?>
        <?php if (!empty($r['city'])): ?> · <?= $h($r['city']) ?><?php endif; ?>
      </div></td>
    <td class="small" style="white-space:nowrap"><?= $h(Inspections::starBar((int)($r['opinion_stars'] ?? 0)) ?: '—') ?></td>
    <td class="small" style="max-width:320px"><?= $h($r['offer_note'] ?: '—') ?></td>
    <td>
      <?php if ($isOpen): ?>
        <span class="pill" style="color:var(--amber)"><?= $h($t('off_st_open')) ?></span>
      <?php else: ?>
        <span class="pill" style="color:var(--green)"><?= $h($t('off_st_done')) ?></span>
        <div class="muted small"><?= $h(date('d/m/Y', strtotime((string)$r['offer_handled_at']))) ?>
          <?= $h($r['handler_name'] ?: ($r['handler_username'] ?: '')) ?></div>
      <?php endif; ?>
    </td>
    <td style="text-align:right;white-space:nowrap">
      <a class="btn ghost tiny" href="?tab=inspections&id=<?= (int)$r['id'] ?>"><?= $h($t('off_open_survey')) ?></a>
      <form method="post" class="inline">
        <input type="hidden" name="do" value="offer_handled">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="handled" value="<?= $isOpen ? '1' : '0' ?>">
        <input type="hidden" name="back_all" value="<?= $showAll ? '1' : '0' ?>">
        <button class="btn tiny <?= $isOpen ? '' : 'ghost' ?>">
          <?= $h($t($isOpen ? 'off_mark_done' : 'off_reopen')) ?></button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
