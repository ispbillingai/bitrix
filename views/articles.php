<?php
/**
 * Magazzino — the article registry the gestionale's ARTICO export fills.
 *
 * Read only, and it says so on the page: the export is a full snapshot and the
 * cron re-imports it every fifteen minutes, so anything typed here would be
 * gone by the next file. Staff come here to FIND an article — by code, by
 * barcode, by what it is called, by supplier — and to see what is on the shelf.
 *
 * Cost price and stock value are the office's business, not the sales floor's,
 * so agents see the list and sale prices and admins see everything.
 *
 * In scope: $t, $h, $isAgent.
 */

use Glue\Crm\Articles;

$isAdminHere = empty($isAgent);
$money = fn($n): string => number_format((float)$n, 2, ',', '.');
$qty   = fn($n): string => rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',');
$dash  = '<span class="muted">—</span>';

$artId = (int)($_GET['id'] ?? 0);
$a     = $artId > 0 ? Articles::find($artId) : null;

if ($a !== null):
    // ======================= one article =======================
    $twins = Articles::barcodeTwins($a);
?>
<div class="cu-top">
  <a class="btn ghost tiny" href="?tab=articles">&larr; <?= $h($t('ar_back')) ?></a>
  <h2 style="margin:0"><?= $h($a['description'] ?: $a['code']) ?>
    <span class="pill"><?= $h($a['code']) ?></span>
    <?php if ((float)$a['stock'] > 0): ?>
      <span class="pill pill-up"><?= $h($t('ar_on_hand')) ?> <?= $h($qty($a['stock'])) ?></span>
    <?php elseif ((float)$a['stock'] < 0): ?>
      <span class="pill pill-down"><?= $h($qty($a['stock'])) ?></span>
    <?php else: ?>
      <span class="pill"><?= $h($t('ar_no_stock')) ?></span>
    <?php endif; ?>
  </h2>
</div>

<div class="cu-cols">
<div class="cu-main">
  <div class="card">
    <h3><?= svg('database') ?> <?= $h($t('ar_identity')) ?></h3>
    <table><tbody>
      <tr><td class="muted"><?= $h($t('ar_code')) ?></td><td><strong><?= $h($a['code']) ?></strong></td></tr>
      <tr><td class="muted"><?= $h($t('ar_barcode')) ?></td><td>
        <?= $a['barcode'] ? $h($a['barcode']) : $dash ?>
        <?php if ($twins): ?>
          <div class="muted small" style="margin-top:4px"><?= $h($t('ar_barcode_shared')) ?>
            <?php foreach ($twins as $tw): ?>
              <a href="?tab=articles&id=<?= (int)$tw['id'] ?>"><?= $h($tw['code']) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </td></tr>
      <tr><td class="muted"><?= $h($t('ar_desc')) ?></td><td><?= $a['description'] ? $h($a['description']) : $dash ?></td></tr>
      <tr><td class="muted"><?= $h($t('ar_category')) ?></td><td>
        <?php if ($a['category']): ?>
          <a href="?tab=articles&category=<?= $h(urlencode((string)$a['category'])) ?>"><?= $h($a['category']) ?></a>
          <?php if ($a['subcategory']): ?> · <?= $h($a['subcategory']) ?><?php endif; ?>
        <?php else: ?><?= $dash ?><?php endif; ?>
      </td></tr>
      <tr><td class="muted"><?= $h($t('ar_location')) ?></td><td><?= $a['location'] ? '<strong>' . $h($a['location']) . '</strong>' : $dash ?></td></tr>
      <tr><td class="muted"><?= $h($t('ar_serials')) ?></td><td><?= $h($t((int)$a['has_serials'] === 1 ? 'yes' : 'no')) ?></td></tr>
    </tbody></table>
  </div>

  <div class="card">
    <h3><?= svg('money') ?> <?= $h($t('ar_prices')) ?></h3>
    <table><tbody>
      <tr><td class="muted"><?= $h($t('ar_list')) ?></td><td><strong>EUR <?= $h($money($a['list_price'])) ?></strong></td></tr>
      <tr><td class="muted"><?= $h($t('ar_sale')) ?></td><td>
        <?= $a['sale_price4'] !== null ? 'EUR ' . $h($money($a['sale_price4'])) : $dash ?>
        <?php if ($a['sale_price4_gross'] !== null): ?>
          <span class="muted small">· <?= $h($t('ar_gross')) ?> EUR <?= $h($money($a['sale_price4_gross'])) ?></span>
        <?php endif; ?>
      </td></tr>
      <tr><td class="muted"><?= $h($t('ar_vat')) ?></td><td><?= $a['vat_rate'] !== null ? $h($money($a['vat_rate'])) . '%' : $dash ?></td></tr>
      <?php if ($isAdminHere): ?>
        <tr><td class="muted"><?= $h($t('ar_cost')) ?></td><td>EUR <?= $h($money($a['cost_price'])) ?></td></tr>
        <?php $marg = (float)$a['list_price'] - (float)$a['cost_price']; ?>
        <tr><td class="muted"><?= $h($t('ar_margin')) ?></td><td style="color:<?= $marg >= 0 ? 'var(--green)' : 'var(--red)' ?>">
          EUR <?= $h($money($marg)) ?>
          <?php if ((float)$a['list_price'] > 0): ?>
            <span class="muted small">· <?= (int)round(100 * $marg / (float)$a['list_price']) ?>%</span>
          <?php endif; ?>
        </td></tr>
      <?php endif; ?>
    </tbody></table>
  </div>
</div>

<div class="cu-side">
  <div class="card">
    <h3><?= svg('database') ?> <?= $h($t('ar_stock')) ?></h3>
    <table><tbody>
      <tr><td class="muted"><?= $h($t('ar_st_now')) ?></td>
        <td style="color:<?= (float)$a['stock'] < 0 ? 'var(--red)' : ((float)$a['stock'] > 0 ? 'var(--green)' : 'var(--muted)') ?>">
          <strong><?= $h($qty($a['stock'])) ?></strong></td></tr>
      <tr><td class="muted"><?= $h($t('ar_st_avail')) ?></td><td><?= $h($qty($a['stock_available'])) ?></td></tr>
      <tr><td class="muted"><?= $h($t('ar_st_ordered')) ?></td><td><?= $h($qty($a['stock_ordered'])) ?></td></tr>
      <tr><td class="muted"><?= $h($t('ar_st_initial')) ?></td><td><?= $h($qty($a['stock_initial'])) ?></td></tr>
      <?php if ($isAdminHere && (float)$a['stock'] != 0.0): ?>
        <tr><td class="muted"><?= $h($t('ar_st_value')) ?></td>
          <td>EUR <?= $h($money((float)$a['stock'] * (float)$a['cost_price'])) ?></td></tr>
      <?php endif; ?>
      <tr><td class="muted"><?= $h($t('ar_last_move')) ?></td>
        <td><?= $a['last_movement'] ? $h(date('d/m/Y', strtotime((string)$a['last_movement']))) : $dash ?></td></tr>
    </tbody></table>
    <?php if ((float)$a['stock'] < 0): ?>
      <p class="muted small" style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line);color:var(--amber)">
        <?= $h($t('ar_negative_note')) ?></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3><?= svg('contacts') ?> <?= $h($t('ar_supplier')) ?></h3>
    <?php if ($a['supplier']): ?>
      <p style="margin:0 0 6px"><a href="?tab=articles&supplier=<?= $h(urlencode((string)$a['supplier'])) ?>"><strong><?= $h($a['supplier']) ?></strong></a></p>
      <?php if ($a['supplier_code1'] || $a['supplier_code2']): ?>
        <div class="muted small"><?= $h($t('ar_supplier_code')) ?>:
          <?= $h(trim(implode(' · ', array_filter([(string)$a['supplier_code1'], (string)$a['supplier_code2']], 'strlen')))) ?></div>
      <?php endif; ?>
    <?php else: ?><p class="muted small"><?= $h($t('ar_no_supplier')) ?></p><?php endif; ?>
  </div>
</div>
</div>

<?php else:
    // ======================= the list =======================
    $q    = trim((string)($_GET['q'] ?? ''));
    $st   = (string)($_GET['state'] ?? 'all');
    $cat  = trim((string)($_GET['category'] ?? ''));
    $sup  = trim((string)($_GET['supplier'] ?? ''));
    $page = max(1, (int)($_GET['p'] ?? 1));
    $res  = Articles::search(['q' => $q, 'state' => $st, 'category' => $cat, 'supplier' => $sup], $page);
    $cnt  = Articles::counters();
    $val  = Articles::stockValue();
    $imp  = Articles::lastImport();

    // Keep every other filter when one of them changes.
    $keep = fn(array $over = []): string => '?' . http_build_query(array_filter(array_merge(
        ['tab' => 'articles', 'q' => $q, 'state' => $st, 'category' => $cat, 'supplier' => $sup], $over),
        fn($v) => $v !== '' && $v !== null));
    $chip = fn(string $key, string $label, int $n): string =>
        '<a class="btn tiny ' . ($st === $key ? '' : 'ghost') . '" href="' . $h($keep(['state' => $key, 'p' => null])) . '">'
        . $h($label) . ' <span class="muted">' . $n . '</span></a>';
?>
<div class="cu-top">
  <h2 style="margin:0"><?= $h($t('nav_articles')) ?></h2>
  <span class="muted small"><?= $h($t('ar_readonly')) ?></span>
</div>

<div class="grid stats">
  <?php num_card($h, 'database', $t('ar_total'), number_format($cnt['total'], 0, ',', '.'), $t('ar_total_sub')); ?>
  <?php num_card($h, 'check', $t('ar_in_stock'), number_format($cnt['in_stock'], 0, ',', '.'),
        number_format($val['pieces'], 0, ',', '.') . ' ' . $t('ar_pieces')); ?>
  <?php if ($isAdminHere): ?>
    <?php num_card($h, 'money', $t('ar_st_value'), 'EUR ' . number_format($val['on_hand'], 2, ',', '.'), $t('ar_at_cost')); ?>
    <?php num_card($h, 'alert', $t('ar_negative'), number_format($cnt['negative'], 0, ',', '.'),
          'EUR ' . number_format($val['owed'], 2, ',', '.')); ?>
  <?php else: ?>
    <?php num_card($h, 'alert', $t('ar_negative'), number_format($cnt['negative'], 0, ',', '.'), $t('ar_negative_sub')); ?>
  <?php endif; ?>
</div>

<?php if ($imp): ?>
  <p class="muted small" style="margin:-6px 0 12px">
    <?= $h($t('ar_last_import')) ?>: <strong><?= $h($imp['filename']) ?></strong>
    · <?= $h(short_time($imp['imported_at'])) ?>
    · <?= (int)$imp['rows_total'] ?> <?= $h($t('ar_rows')) ?>
    (<?= (int)$imp['created_n'] ?> <?= $h($t('ar_created')) ?>, <?= (int)$imp['updated_n'] ?> <?= $h($t('ar_updated')) ?>)
  </p>
<?php endif; ?>

<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px">
  <?= $chip('all', $t('ar_f_all'), $cnt['total']) ?>
  <?= $chip('in_stock', $t('ar_f_in_stock'), $cnt['in_stock']) ?>
  <?= $chip('negative', $t('ar_f_negative'), $cnt['negative']) ?>
  <?= $chip('ordered', $t('ar_f_ordered'), $cnt['ordered']) ?>
  <?= $chip('serials', $t('ar_f_serials'), $cnt['serials']) ?>
  <form method="get" class="inline" style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="tab" value="articles">
    <input type="hidden" name="state" value="<?= $h($st) ?>">
    <select name="category" onchange="this.form.submit()">
      <option value=""><?= $h($t('ar_all_categories')) ?></option>
      <?php foreach (Articles::categories() as $c): ?>
        <option value="<?= $h($c) ?>"<?= $c === $cat ? ' selected' : '' ?>><?= $h($c) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= $h($q) ?>" placeholder="<?= $h($t('ar_search_ph')) ?>" style="width:min(300px,60vw)">
  </form>
</div>

<?php if ($sup !== ''): ?>
  <p class="muted small" style="margin:-6px 0 10px"><?= $h($t('ar_supplier')) ?>:
    <strong><?= $h($sup) ?></strong> · <a href="<?= $h($keep(['supplier' => null, 'p' => null])) ?>"><?= $h($t('clear')) ?></a></p>
<?php endif; ?>

<h3 style="margin:0 0 10px"><?= $h($t('ar_results')) ?> · <?= number_format($res['total'], 0, ',', '.') ?></h3>

<?php if (!$res['rows']): ?>
  <div class="card"><div class="empty"><?= $h($t('ar_none')) ?></div></div>
<?php else: ?>
<table>
  <thead><tr>
    <th><?= $h($t('ar_code')) ?></th><th><?= $h($t('ar_desc')) ?></th>
    <th><?= $h($t('ar_category')) ?></th><th><?= $h($t('ar_location')) ?></th>
    <th style="text-align:right"><?= $h($t('ar_st_now')) ?></th>
    <th style="text-align:right"><?= $h($t('ar_list')) ?></th>
    <?php if ($isAdminHere): ?><th style="text-align:right"><?= $h($t('ar_cost')) ?></th><?php endif; ?>
    <th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($res['rows'] as $r): $s = (float)$r['stock']; ?>
    <tr>
      <td><a href="?tab=articles&id=<?= (int)$r['id'] ?>"><strong><?= $h($r['code']) ?></strong></a>
        <?php if (!empty($r['barcode'])): ?><div class="muted small"><?= $h($r['barcode']) ?></div><?php endif; ?></td>
      <td><?= $r['description'] ? $h($r['description']) : $dash ?>
        <?php if (!empty($r['supplier'])): ?><div class="muted small"><?= $h($r['supplier']) ?></div><?php endif; ?></td>
      <td class="small"><?= $r['category'] ? $h($r['category']) : $dash ?></td>
      <td class="small"><?= $r['location'] ? $h($r['location']) : $dash ?></td>
      <td style="text-align:right;color:<?= $s < 0 ? 'var(--red)' : ($s > 0 ? 'var(--green)' : 'var(--muted)') ?>">
        <strong><?= $h($qty($s)) ?></strong></td>
      <td style="text-align:right"><?= $h($money($r['list_price'])) ?></td>
      <?php if ($isAdminHere): ?><td style="text-align:right" class="muted"><?= $h($money($r['cost_price'])) ?></td><?php endif; ?>
      <td style="text-align:right"><a class="btn ghost tiny" href="?tab=articles&id=<?= (int)$r['id'] ?>"><?= $h($t('cu_open')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($res['pages'] > 1): ?>
  <div style="display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap">
    <?php if ($res['page'] > 1): ?>
      <a class="btn ghost tiny" href="<?= $h($keep(['p' => $res['page'] - 1])) ?>">&larr;</a>
    <?php endif; ?>
    <span class="muted small"><?= (int)$res['page'] ?> / <?= (int)$res['pages'] ?></span>
    <?php if ($res['page'] < $res['pages']): ?>
      <a class="btn ghost tiny" href="<?= $h($keep(['p' => $res['page'] + 1])) ?>">&rarr;</a>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

<style>
.cu-top{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap}
.cu-cols{display:flex;gap:16px;align-items:flex-start}
.cu-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:16px}
.cu-main .card{margin:0}
.cu-side{width:340px;flex-shrink:0;display:flex;flex-direction:column;gap:16px}
.cu-side .card{margin:0}
.pill-up{background:rgba(62,207,142,.15);color:#3ecf8e}
.pill-down{background:rgba(240,82,82,.15);color:#f05252}
@media (max-width:1000px){.cu-cols{flex-direction:column}.cu-side{width:100%}}
</style>
<?php endif; ?>
