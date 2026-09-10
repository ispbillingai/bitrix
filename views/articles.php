<?php
/**
 * Magazzino — the article registry the gestionale's ARTICO export fills.
 *
 * Staff come here to FIND an article — by code, by barcode, by what it is
 * called, by supplier — and to see what is on the shelf. Admins can also add
 * products, edit them and remove them.
 *
 * Two ownerships, and the page is explicit about which it is looking at (see
 * Crm\Articles): a gestionale row is refreshed from the export every fifteen
 * minutes, while a CRM row is kept here and the import leaves it alone. Editing
 * a gestionale row takes it over, and the form says so before you save.
 *
 * Cost price, margin and stock value are the office's business, not the sales
 * floor's, so agents see list and sale prices and admins see everything. Only
 * admins get the add/edit/remove controls.
 *
 * In scope: $t, $h, $isAgent.
 */

use Glue\Crm\Articles;

/** The add/edit form. $ed is the article being edited, or null for a new one. */
function article_form(array $cats, array $sups, callable $h, callable $t, ?array $ed = null): void { ?>
  <form method="post" class="card" style="margin-top:10px">
    <input type="hidden" name="do" value="<?= $ed ? 'article_edit' : 'article_create' ?>">
    <?php if ($ed): ?><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>"><?php endif; ?>
    <?php if ($ed && (string)$ed['origin'] === 'gestionale'): ?>
      <p class="muted small" style="margin:0 0 14px;color:var(--amber)"><?= $h($t('ar_detach_warn')) ?></p>
    <?php endif; ?>
    <div class="row">
      <label class="fld"><span><?= $h($t('ar_code')) ?> *</span>
        <input name="code" value="<?= $h($ed['code'] ?? '') ?>" required></label>
      <label class="fld"><span><?= $h($t('ar_barcode')) ?></span>
        <input name="barcode" value="<?= $h($ed['barcode'] ?? '') ?>"></label>
    </div>
    <label class="fld"><span><?= $h($t('ar_desc')) ?> *</span>
      <input name="description" value="<?= $h($ed['description'] ?? '') ?>" required></label>
    <div class="row">
      <label class="fld"><span><?= $h($t('ar_list')) ?></span>
        <input name="list_price" value="<?= $ed ? $h(rtrim(rtrim(number_format((float)$ed['list_price'], 4, ',', ''), '0'), ',')) : '' ?>" placeholder="0,00"></label>
      <label class="fld"><span><?= $h($t('ar_cost')) ?></span>
        <input name="cost_price" value="<?= $ed ? $h(rtrim(rtrim(number_format((float)$ed['cost_price'], 4, ',', ''), '0'), ',')) : '' ?>" placeholder="0,00"></label>
      <label class="fld"><span><?= $h($t('ar_sale')) ?></span>
        <input name="sale_price4" value="<?= $ed && $ed['sale_price4'] !== null ? $h(rtrim(rtrim(number_format((float)$ed['sale_price4'], 4, ',', ''), '0'), ',')) : '' ?>" placeholder="0,00"></label>
      <label class="fld"><span><?= $h($t('ar_vat')) ?></span>
        <input name="vat_rate" value="<?= $ed && $ed['vat_rate'] !== null ? $h(rtrim(rtrim(number_format((float)$ed['vat_rate'], 2, ',', ''), '0'), ',')) : '22' ?>" placeholder="22"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('ar_category')) ?></span>
        <input name="category" list="ar-cats" value="<?= $h($ed['category'] ?? '') ?>"></label>
      <label class="fld"><span><?= $h($t('ar_subcategory')) ?></span>
        <input name="subcategory" value="<?= $h($ed['subcategory'] ?? '') ?>"></label>
      <label class="fld"><span><?= $h($t('ar_location')) ?></span>
        <input name="location" value="<?= $h($ed['location'] ?? '') ?>"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('ar_supplier')) ?></span>
        <input name="supplier" list="ar-sups" value="<?= $h($ed['supplier'] ?? '') ?>"></label>
      <label class="fld"><span><?= $h($t('ar_supplier_code')) ?> 1</span>
        <input name="supplier_code1" value="<?= $h($ed['supplier_code1'] ?? '') ?>"></label>
      <label class="fld"><span><?= $h($t('ar_supplier_code')) ?> 2</span>
        <input name="supplier_code2" value="<?= $h($ed['supplier_code2'] ?? '') ?>"></label>
    </div>
    <?php if (!$ed): // edits go through the stock box on the product page instead ?>
    <div class="row">
      <label class="fld"><span><?= $h($t('ar_stock_initial')) ?></span>
        <input name="stock" placeholder="0" inputmode="decimal"></label>
      <label class="fld"><span><?= $h($t('ar_threshold')) ?></span>
        <input name="reorder_threshold" placeholder="<?= $h($t('ar_threshold_ph')) ?>" inputmode="decimal"></label>
    </div>
    <?php endif; ?>
    <label class="fld" style="flex-direction:row;align-items:center;gap:8px">
      <input type="checkbox" name="has_serials" value="1" style="width:auto"<?= $ed && (int)$ed['has_serials'] === 1 ? ' checked' : '' ?>>
      <span style="margin:0"><?= $h($t('ar_serials')) ?></span></label>
    <p class="muted small" style="margin:6px 0 12px"><?= $h($t('ar_stock_note')) ?></p>
    <button class="btn"><?= $h($t('save')) ?></button>
  </form>
  <datalist id="ar-cats"><?php foreach ($cats as $c): ?><option value="<?= $h($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="ar-sups"><?php foreach ($sups as $c): ?><option value="<?= $h($c) ?>"><?php endforeach; ?></datalist>
<?php }

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
    <?php if ((string)$a['origin'] === 'crm'): ?>
      <span class="pill" title="<?= $h($t('ar_origin_crm_h')) ?>"><?= $h($t('ar_origin_crm')) ?></span>
    <?php endif; ?>
    <?php if ((int)$a['archived'] === 1): ?>
      <span class="pill pill-down"><?= $h($t('ar_archived')) ?></span>
    <?php endif; ?>
  </h2>
  <?php if ($isAdminHere): ?>
  <span class="cu-acts">
    <?php if ((int)$a['archived'] === 1): ?>
      <form method="post" style="display:inline"><input type="hidden" name="do" value="article_restore">
        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <button class="btn tiny"><?= $h($t('ar_restore')) ?></button></form>
    <?php else: ?>
      <form method="post" style="display:inline"
            onsubmit="return confirm('<?= $h((string)$a['origin'] === 'crm' ? $t('ar_del_confirm') : $t('ar_archive_confirm')) ?>')">
        <input type="hidden" name="do" value="article_delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <button class="btn ghost tiny" style="color:var(--red)">
          <?= $h((string)$a['origin'] === 'crm' ? $t('delete') : $t('ar_archive')) ?></button></form>
    <?php endif; ?>
  </span>
  <?php endif; ?>
</div>

<?php if ($isAdminHere && (int)$a['archived'] === 0): ?>
  <details class="drawer" style="margin-bottom:14px">
    <summary class="btn ghost"><?= svg('pen') ?> <?= $h($t('ar_edit')) ?></summary>
    <?php article_form(Articles::categories(), Articles::suppliers(), $h, $t, $a); ?>
  </details>
<?php endif; ?>

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
      <tr><td class="muted"><?= $h($t('ar_origin')) ?></td><td>
        <?= $h((string)$a['origin'] === 'crm' ? $t('ar_origin_crm') : $t('ar_origin_gest')) ?>
        <?php if (!empty($a['crm_edited_at'])): ?>
          <div class="muted small"><?= $h($t('ar_edited')) ?> <?= $h(short_time($a['crm_edited_at'])) ?></div>
        <?php endif; ?>
        <div class="muted small"><?= $h((string)$a['origin'] === 'crm' ? $t('ar_origin_crm_h') : $t('ar_origin_gest_h')) ?></div>
      </td></tr>
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
      <tr><td class="muted"><?= $h($t('ar_threshold')) ?></td><td>
        <?php if ($a['reorder_threshold'] !== null): ?>
          <?= $h($qty($a['reorder_threshold'])) ?>
          <?php if ((float)$a['stock'] <= (float)$a['reorder_threshold']): ?>
            <span class="pill pill-down"><?= $h($t('ar_low')) ?></span>
          <?php endif; ?>
        <?php else: ?><?= $dash ?><?php endif; ?>
      </td></tr>
      <tr><td class="muted"><?= $h($t('ar_last_move')) ?></td>
        <td><?= $a['last_movement'] ? $h(date('d/m/Y', strtotime((string)$a['last_movement']))) : $dash ?></td></tr>
    </tbody></table>
    <?php if ((float)$a['stock'] < 0): ?>
      <p class="muted small" style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line);color:var(--amber)">
        <?= $h($t('ar_negative_note')) ?></p>
    <?php endif; ?>
    <p class="muted small" style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
      <?= $h((string)$a['stock_owner'] === 'crm' || (string)$a['origin'] === 'crm'
            ? $t('ar_stock_owner_crm') : $t('ar_stock_owner_gest')) ?></p>
  </div>

  <?php if ($isAdminHere && (int)$a['archived'] === 0): ?>
  <div class="card">
    <h3><?= svg('pen') ?> <?= $h($t('ar_move')) ?></h3>
    <form method="post">
      <input type="hidden" name="do" value="article_stock">
      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <div class="row">
        <label class="fld"><span><?= $h($t('ar_move_mode')) ?></span>
          <select name="mode">
            <option value="load"><?= $h($t('ar_mode_load')) ?></option>
            <option value="unload"><?= $h($t('ar_mode_unload')) ?></option>
            <option value="set"><?= $h($t('ar_mode_set')) ?></option>
          </select></label>
        <label class="fld" style="max-width:120px"><span><?= $h($t('ar_qty')) ?></span>
          <input name="qty" required inputmode="decimal" placeholder="0"></label>
      </div>
      <label class="fld"><span><?= $h($t('f_notes')) ?></span>
        <input name="note" placeholder="<?= $h($t('ar_move_note_ph')) ?>"></label>
      <?php if ((string)$a['origin'] === 'gestionale' && (string)$a['stock_owner'] === 'gestionale'): ?>
        <p class="muted small" style="margin:-4px 0 10px;color:var(--amber)"><?= $h($t('ar_stock_takeover')) ?></p>
      <?php endif; ?>
      <button class="btn tiny"><?= $h($t('save')) ?></button>
    </form>
    <?php if ((string)$a['origin'] === 'gestionale' && (string)$a['stock_owner'] === 'crm'): ?>
      <form method="post" style="margin-top:10px"
            onsubmit="return confirm('<?= $h($t('ar_stock_release_confirm')) ?>')">
        <input type="hidden" name="do" value="article_stock_release">
        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <button class="btn ghost tiny"><?= $h($t('ar_stock_release')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3><?= svg('alert') ?> <?= $h($t('ar_threshold')) ?></h3>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="do" value="article_threshold">
      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <label class="fld" style="margin:0;max-width:140px"><span><?= $h($t('ar_threshold_min')) ?></span>
        <input name="reorder_threshold" inputmode="decimal"
               value="<?= $a['reorder_threshold'] !== null ? $h($qty($a['reorder_threshold'])) : '' ?>"
               placeholder="<?= $h($t('ar_threshold_ph')) ?>"></label>
      <button class="btn tiny"><?= $h($t('save')) ?></button>
    </form>
    <p class="muted small" style="margin:10px 0 0"><?= $h($t('ar_threshold_hint')) ?></p>
  </div>
  <?php endif; ?>

  <?php $movs = Articles::movements((int)$a['id']); if ($movs): ?>
  <div class="card">
    <h3><?= svg('events') ?> <?= $h($t('ar_movements')) ?></h3>
    <?php foreach ($movs as $m): $d = (float)$m['delta']; ?>
      <div class="lb">
        <span class="nm" style="min-width:0">
          <span style="color:<?= $d < 0 ? 'var(--red)' : 'var(--green)' ?>"><?= $d > 0 ? '+' : '' ?><?= $h($qty($d)) ?></span>
          <span class="muted small">→ <?= $h($qty($m['stock_after'])) ?> · <?= $h($t('ar_reason_' . $m['reason'])) ?></span>
          <?php if (!empty($m['note'])): ?><div class="muted small"><?= $h($m['note']) ?></div><?php endif; ?>
        </span>
        <span class="mini"><?= $h(short_time($m['created_at'])) ?><br><?= $h((string)$m['user_name']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

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
  <span class="muted small"><?= $h($t('ar_sub')) ?></span>
  <?php if ($isAdminHere): ?>
    <span class="cu-acts">
      <details class="drawer">
        <summary class="btn"><?= svg('articles') ?> <?= $h($t('ar_new')) ?></summary>
        <div class="card" style="position:absolute;right:20px;z-index:6;width:min(760px,94vw);margin-top:8px;padding:0">
          <?php article_form(Articles::categories(), Articles::suppliers(), $h, $t, null); ?>
        </div>
      </details>
    </span>
  <?php endif; ?>
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

<?php if ($cnt['low'] > 0 && $st !== 'low'): ?>
  <div class="flash flash-warn" style="margin-bottom:12px">
    <?= $h(sprintf($t('ar_low_banner'), $cnt['low'])) ?>
    <a href="<?= $h($keep(['state' => 'low', 'p' => null])) ?>"><?= $h($t('ar_low_see')) ?></a>
  </div>
<?php endif; ?>

<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px">
  <?= $chip('all', $t('ar_f_all'), $cnt['total']) ?>
  <?= $chip('in_stock', $t('ar_f_in_stock'), $cnt['in_stock']) ?>
  <?= $chip('negative', $t('ar_f_negative'), $cnt['negative']) ?>
  <?= $chip('ordered', $t('ar_f_ordered'), $cnt['ordered']) ?>
  <?= $chip('serials', $t('ar_f_serials'), $cnt['serials']) ?>
  <?= $chip('low', $t('ar_f_low'), $cnt['low']) ?>
  <?= $chip('crm', $t('ar_f_crm'), $cnt['crm']) ?>
  <?php if ($cnt['archived'] > 0): ?><?= $chip('archived', $t('ar_f_archived'), $cnt['archived']) ?><?php endif; ?>
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
        <strong><?= $h($qty($s)) ?></strong>
        <?php if ($r['reorder_threshold'] !== null && $s <= (float)$r['reorder_threshold']): ?>
          <div class="small" style="color:var(--amber)"><?= $h($t('ar_low')) ?> · min <?= $h($qty($r['reorder_threshold'])) ?></div>
        <?php endif; ?></td>
      <td style="text-align:right"><?= $h($money($r['list_price'])) ?></td>
      <?php if ($isAdminHere): ?><td style="text-align:right" class="muted"><?= $h($money($r['cost_price'])) ?></td><?php endif; ?>
      <td style="text-align:right;white-space:nowrap">
        <?php if ((string)$r['origin'] === 'crm'): ?>
          <span class="pill" title="<?= $h($t('ar_origin_crm_h')) ?>"><?= $h($t('ar_origin_crm')) ?></span>
        <?php endif; ?>
        <a class="btn ghost tiny" href="?tab=articles&id=<?= (int)$r['id'] ?>"><?= $h($t('cu_open')) ?></a></td>
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
.cu-acts{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;position:relative}
@media (max-width:1000px){.cu-cols{flex-direction:column}.cu-side{width:100%}}
</style>
<?php endif; ?>
