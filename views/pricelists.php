<?php
/**
 * Listini — sales price lists, drawn from the warehouse.
 *
 * Four screens behind one tab:
 *
 *   ?tab=pricelists                       the lists
 *   ?tab=pricelists&list=N                the catalogue: a shop-style grid of
 *                                         the list's products, PDF and print
 *   ?tab=pricelists&list=N&item=A         one product's sheet: photos,
 *                                         documents, longer text, the link
 *   ?tab=pricelists&list=N&manage=1       (office) which warehouse articles
 *                                         are in the list, and at what price
 *
 * Agents see the lists the office has made visible and nothing to change;
 * the office manages lists and their contents. A product's photos, documents
 * and link are set on its record in Magazzino, next to the per-list flags.
 *
 * In scope: $t, $h, $isAgent, $lang.
 */

use Glue\Crm\ArticleMedia;
use Glue\Crm\Articles;
use Glue\Crm\PriceLists;

/** Create / edit form for a list. */
function pricelist_form(callable $h, callable $t, ?array $l = null): void { ?>
  <form method="post" class="card" style="margin:10px 0 0">
    <input type="hidden" name="do" value="pricelist_save">
    <?php if ($l): ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><?php endif; ?>
    <label class="fld"><span><?= $h($t('pl_name')) ?> *</span>
      <input name="name" required maxlength="120" value="<?= $h($l['name'] ?? '') ?>" placeholder="<?= $h($t('pl_name_ph')) ?>"></label>
    <label class="fld"><span><?= $h($t('pl_desc')) ?></span>
      <textarea name="description" rows="2" maxlength="500" placeholder="<?= $h($t('pl_desc_ph')) ?>"><?= $h($l['description'] ?? '') ?></textarea></label>
    <div class="row">
      <label class="fld"><span><?= $h($t('pl_basis')) ?></span>
        <select name="price_basis">
          <option value="list"<?= ($l['price_basis'] ?? 'list') === 'list' ? ' selected' : '' ?>><?= $h($t('pl_basis_list')) ?></option>
          <option value="sale4"<?= ($l['price_basis'] ?? '') === 'sale4' ? ' selected' : '' ?>><?= $h($t('pl_basis_sale4')) ?></option>
        </select></label>
      <label class="fld"><span><?= $h($t('pl_adjust')) ?></span>
        <input name="adjust_pct" inputmode="decimal" placeholder="0"
               value="<?= $l && (float)$l['adjust_pct'] != 0.0 ? $h(rtrim(rtrim(number_format((float)$l['adjust_pct'], 2, ',', ''), '0'), ',')) : '' ?>">
        <small class="muted"><?= $h($t('pl_adjust_h')) ?></small></label>
    </div>
    <label class="fld" style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="vat_included" value="1" style="width:auto"<?= !empty($l['vat_included']) ? ' checked' : '' ?>>
      <span style="margin:0"><?= $h($t('pl_vat_included')) ?></span></label>
    <label class="fld" style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="visible" value="1" style="width:auto"<?= !$l || !empty($l['visible']) ? ' checked' : '' ?>>
      <span style="margin:0"><?= $h($t('pl_visible')) ?></span></label>
    <button class="btn"><?= $h($t('save')) ?></button>
  </form>
<?php }

/** The little line that says how a list is priced. */
function pricelist_terms(callable $t, array $l, string $lang = 'it'): string {
    $s = $t($l['price_basis'] === 'sale4' ? 'pl_basis_sale4' : 'pl_basis_list');
    $adj = (float)$l['adjust_pct'];
    if ($adj != 0.0) {
        $s .= ' ' . ($adj > 0 ? '+' : '−') . rtrim(rtrim(number_format(abs($adj), 2, ',', '.'), '0'), ',') . '%';
    }
    // The version is part of how a list reads: it is what a printed catalogue
    // and a printed quote name, so the office can match paper to screen.
    return $s . ' · ' . $t((int)$l['vat_included'] === 1 ? 'pl_vat_in' : 'pl_vat_ex')
             . ' · ' . PriceLists::versionLabel($l, $lang);
}

$isAdminHere = empty($isAgent);
$eur    = fn(float $n): string => '€ ' . number_format($n, 2, ',', '.');
$qty    = fn($n): string => rtrim(rtrim(number_format((float)$n, 2, ',', '.'), '0'), ',');
$listId = (int)($_GET['list'] ?? 0);
$list   = $listId > 0 ? PriceLists::find($listId) : null;
if ($list && !$isAdminHere && (int)$list['visible'] !== 1) {
    $list = null;   // a list the office has not published is not there for an agent
}
$itemId = (int)($_GET['item'] ?? 0);
$manage = $isAdminHere && !empty($_GET['manage']);

/** Where a product's photo leads: its own link when it has one, else its sheet. */
$photoHref = fn(array $r): array => trim((string)($r['info_url'] ?? '')) !== ''
    ? [(string)$r['info_url'], true]
    : ['?tab=pricelists&list=' . $listId . '&item=' . (int)$r['id'], false];

if ($listId > 0 && !$list): ?>
  <div class="card"><div class="empty"><?= $h($t('pl_not_found')) ?>
    <p style="margin-top:10px"><a class="btn ghost tiny" href="?tab=pricelists">&larr; <?= $h($t('pl_back_lists')) ?></a></p></div></div>

<?php elseif ($list && $itemId > 0):
    // ======================= one product's sheet =======================
    $it = PriceLists::item($listId, $itemId);
    if (!$it): ?>
      <div class="card"><div class="empty"><?= $h($t('pl_item_gone')) ?>
        <p style="margin-top:10px"><a class="btn ghost tiny" href="?tab=pricelists&list=<?= $listId ?>">&larr; <?= $h($list['name']) ?></a></p></div></div>
    <?php else:
        $photos = ArticleMedia::photos($itemId);
        $files  = ArticleMedia::files($itemId);
        $price  = PriceLists::shownPrice($it, $list);
        $av     = PriceLists::availability($it);
        $link   = trim((string)($it['info_url'] ?? ''));
    ?>
    <div class="cu-top">
      <a class="btn ghost tiny" href="?tab=pricelists&list=<?= $listId ?>">&larr; <?= $h($list['name']) ?></a>
      <?php if ($isAdminHere): ?>
        <span class="cu-acts"><a class="btn ghost tiny" href="?tab=articles&id=<?= $itemId ?>"><?= svg('pen') ?> <?= $h($t('pl_edit_in_wh')) ?></a></span>
      <?php endif; ?>
    </div>

    <div class="pl-sheet">
      <div class="pl-gal">
        <?php if ($photos): $first = $photos[0]; ?>
          <a class="pl-gal-main" id="plMainA" href="<?= $h($link !== '' ? $link : '?amf=' . (int)$first['id']) ?>" target="_blank" rel="noopener">
            <img id="plMain" src="?amf=<?= (int)$first['id'] ?>" alt="<?= $h($it['description']) ?>">
            <?php if ($link !== ''): ?><span class="pl-ext" title="<?= $h($t('pl_open_link')) ?>"><?= svg('external') ?></span><?php endif; ?>
          </a>
          <?php if (count($photos) > 1): ?>
            <div class="pl-gal-thumbs">
              <?php foreach ($photos as $i => $p): ?>
                <button type="button" class="<?= $i === 0 ? 'on' : '' ?>" data-full="?amf=<?= (int)$p['id'] ?>" aria-label="<?= $h($p['name']) ?>">
                  <img src="?amf=<?= (int)$p['id'] ?>&s=t" alt="" loading="lazy"></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <<?= $link !== '' ? 'a href="' . $h($link) . '" target="_blank" rel="noopener"' : 'div' ?> class="pl-gal-main pl-noph">
            <?= svg('image') ?><span><?= $h($t('pl_no_photo')) ?></span>
          </<?= $link !== '' ? 'a' : 'div' ?>>
        <?php endif; ?>
      </div>

      <div class="pl-info">
        <div class="pl-code"><?= $h($it['code']) ?></div>
        <h2 style="margin:4px 0 12px"><?= $h($it['description'] ?: $it['code']) ?></h2>
        <?php if ($price > 0): ?>
          <div class="pl-price big"><?= $h($eur($price)) ?>
            <span><?= $h(sprintf($t((int)$list['vat_included'] === 1 ? 'pl_vat_incl_n' : 'pl_plus_vat_n'), $qty(PriceLists::vatRate($it)))) ?></span></div>
        <?php else: ?>
          <div class="pl-price big req"><?= $h($t('pl_on_request')) ?></div>
        <?php endif; ?>
        <div class="pl-av av-<?= $h($av) ?>" style="margin:10px 0 16px"><?= $h($t('pl_av_' . $av)) ?></div>

        <?php if ($link !== ''): ?>
          <p style="margin:0 0 16px"><a class="btn" href="<?= $h($link) ?>" target="_blank" rel="noopener"><?= svg('external') ?> <?= $h($t('pl_open_link')) ?></a></p>
        <?php endif; ?>

        <?php // a definition list, not a table: tables scroll sideways on a phone ?>
        <dl class="pl-kv">
          <dt><?= $h($t('ar_code')) ?></dt><dd><strong><?= $h($it['code']) ?></strong></dd>
          <?php if (trim((string)$it['barcode']) !== ''): ?>
            <dt><?= $h($t('ar_barcode')) ?></dt><dd><?= $h($it['barcode']) ?></dd>
          <?php endif; ?>
          <?php if ($it['category']): ?>
            <dt><?= $h($t('ar_category')) ?></dt><dd><?= $h($it['category']) ?><?= $it['subcategory'] ? ' · ' . $h($it['subcategory']) : '' ?></dd>
          <?php endif; ?>
          <dt><?= $h($t('ar_vat')) ?></dt><dd><?= $h($qty(PriceLists::vatRate($it))) ?>%</dd>
          <?php if ($price > 0): $net = PriceLists::netPrice($it, $list); ?>
            <dt><?= $h($t('pl_net')) ?></dt><dd><?= $h($eur($net)) ?></dd>
            <dt><?= $h($t('pl_gross')) ?></dt><dd><?= $h($eur(round($net * (1 + PriceLists::vatRate($it) / 100), 2))) ?></dd>
          <?php endif; ?>
        </dl>

        <?php if ($files): ?>
          <h3><?= svg('clip') ?> <?= $h($t('pl_docs')) ?></h3>
          <div class="pl-docs">
            <?php foreach ($files as $f): ?>
              <a href="?amf=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">
                <?= svg('clip') ?><span><?= $h($f['name']) ?></span><small class="muted"><?= $h(ArticleMedia::size((int)$f['size_bytes'])) ?></small></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (trim((string)$it['web_description']) !== ''): ?>
      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0"><?= $h($t('pl_details')) ?></h3>
        <div style="white-space:pre-line;line-height:1.65"><?= $h($it['web_description']) ?></div>
      </div>
    <?php endif; ?>
    <script>
    document.querySelectorAll('.pl-gal-thumbs button').forEach(function(b){
      b.addEventListener('click',function(){
        document.getElementById('plMain').src=b.dataset.full;
        var a=document.getElementById('plMainA');
        if(a && <?= $link !== '' ? 'false' : 'true' ?>){a.href=b.dataset.full;}
        document.querySelectorAll('.pl-gal-thumbs button').forEach(function(x){x.classList.toggle('on',x===b);});
      });
    });
    </script>
    <?php endif; ?>

<?php elseif ($list && $manage):
    // ======================= office: what is in the list =======================
    $q    = trim((string)($_GET['q'] ?? ''));
    $cat  = trim((string)($_GET['category'] ?? ''));
    $mode = in_array($_GET['mode'] ?? '', ['in', 'out', 'all'], true) ? (string)$_GET['mode'] : 'in';
    $stk  = !empty($_GET['stock']);
    $page = max(1, (int)($_GET['p'] ?? 1));
    $filter = ['q' => $q, 'category' => $cat, 'state' => $stk ? 'in_stock' : 'all',
               'pricelist' => $listId, 'pl_mode' => $mode === 'all' ? '' : $mode];
    $res  = Articles::search($filter, $page, 50);
    $ids  = array_map(fn($r) => (int)$r['id'], $res['rows']);
    $mem  = PriceLists::membership($listId, $ids);
    $covs = ArticleMedia::coversFor($ids);
    $keep = fn(array $over = []): string => '?' . http_build_query(array_filter(array_merge(
        ['tab' => 'pricelists', 'list' => $listId, 'manage' => 1, 'q' => $q, 'category' => $cat,
         'mode' => $mode, 'stock' => $stk ? 1 : null, 'p' => $page > 1 ? $page : null], $over),
        fn($v) => $v !== '' && $v !== null));
    $nIn = PriceLists::items($listId, [], 1, 1)['total'];
?>
<div class="cu-top">
  <a class="btn ghost tiny" href="?tab=pricelists&list=<?= $listId ?>">&larr; <?= $h($t('pl_see_catalog')) ?></a>
  <h2 style="margin:0"><?= $h($t('pl_manage')) ?> · <?= $h($list['name']) ?></h2>
</div>
<p class="muted small" style="margin:-4px 0 14px;max-width:860px"><?= $h($t('pl_manage_h')) ?></p>

<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px">
  <?php foreach (['in' => $t('pl_mode_in') . ' ' . $nIn, 'out' => $t('pl_mode_out'), 'all' => $t('pl_mode_all')] as $mk => $ml): ?>
    <a class="btn tiny <?= $mode === $mk ? '' : 'ghost' ?>" href="<?= $h($keep(['mode' => $mk, 'p' => null])) ?>"><?= $h($ml) ?></a>
  <?php endforeach; ?>
  <form method="get" class="inline" style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="tab" value="pricelists"><input type="hidden" name="list" value="<?= $listId ?>">
    <input type="hidden" name="manage" value="1"><input type="hidden" name="mode" value="<?= $h($mode) ?>">
    <label class="small muted" style="display:flex;gap:6px;align-items:center;white-space:nowrap">
      <input type="checkbox" name="stock" value="1" style="width:auto"<?= $stk ? ' checked' : '' ?> onchange="this.form.submit()"> <?= $h($t('pl_only_stock')) ?></label>
    <select name="category" onchange="this.form.submit()" style="max-width:220px">
      <option value=""><?= $h($t('ar_all_categories')) ?></option>
      <?php foreach (Articles::categories() as $c): ?>
        <option value="<?= $h($c) ?>"<?= $c === $cat ? ' selected' : '' ?>><?= $h($c) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= $h($q) ?>" placeholder="<?= $h($t('ar_search_ph')) ?>" style="width:min(260px,60vw)">
  </form>
</div>

<?php if (!$res['rows']): ?>
  <div class="card"><div class="empty"><?= $h($t($mode === 'in' && $q === '' && $cat === '' ? 'pl_manage_empty' : 'ar_none')) ?></div></div>
<?php else: ?>
<form method="post" id="plMembers">
  <input type="hidden" name="do" value="pricelist_members">
  <input type="hidden" name="id" value="<?= $listId ?>">
  <input type="hidden" name="back" value="<?= $h($keep()) ?>">
  <table>
    <thead><tr>
      <th style="width:36px"><input type="checkbox" style="width:auto" title="<?= $h($t('pl_tick_all')) ?>"
        onclick="document.querySelectorAll('#plMembers .pl-on').forEach(c=>c.checked=this.checked)"></th>
      <th></th>
      <th><?= $h($t('ar_code')) ?></th><th><?= $h($t('ar_desc')) ?></th>
      <th style="text-align:right"><?= $h($t('pl_wh_price')) ?></th>
      <th style="text-align:right;min-width:130px"><?= $h($t('pl_own_price')) ?></th>
      <th style="text-align:right"><?= $h($t('ar_st_now')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($res['rows'] as $r): $aid = (int)$r['id']; $in = array_key_exists($aid, $mem);
          $base = round(PriceLists::basePrice($r, (string)$list['price_basis']) * (1 + (float)$list['adjust_pct'] / 100), 2); ?>
      <tr>
        <td><input type="hidden" name="shown[]" value="<?= $aid ?>">
          <input type="checkbox" class="pl-on" name="on[]" value="<?= $aid ?>" style="width:auto;transform:scale(1.2)"<?= $in ? ' checked' : '' ?>></td>
        <td style="width:52px"><?php if (isset($covs[$aid])): ?>
          <img class="pl-mini" src="?amf=<?= (int)$covs[$aid]['id'] ?>&s=t" alt="" loading="lazy">
          <?php else: ?><span class="pl-mini pl-mini-no"><?= svg('image') ?></span><?php endif; ?></td>
        <td><a href="?tab=articles&id=<?= $aid ?>"><strong><?= $h($r['code']) ?></strong></a></td>
        <td><?= $h($r['description'] ?: '—') ?>
          <?php if ($r['category']): ?><div class="muted small"><?= $h($r['category']) ?></div><?php endif; ?></td>
        <td style="text-align:right;white-space:nowrap"><?= $base > 0 ? $h($eur($base)) : '<span class="muted">—</span>' ?></td>
        <td style="text-align:right"><input name="price[<?= $aid ?>]" inputmode="decimal" style="max-width:120px;text-align:right"
          value="<?= $in && $mem[$aid] !== null ? $h(number_format((float)$mem[$aid], 2, ',', '')) : '' ?>"
          placeholder="<?= $h($t('pl_auto')) ?>"></td>
        <td style="text-align:right;color:<?= (float)$r['stock'] > 0 ? 'var(--green)' : 'var(--muted)' ?>"><?= $h($qty($r['stock'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:-4px 0 14px">
    <button class="btn"><?= svg('check') ?> <?= $h($t('pl_save_sel')) ?></button>
    <span class="muted small"><?= $h($t('pl_save_sel_h')) ?></span>
  </div>
</form>

<?php if ($mode !== 'in'): ?>
  <form method="post" style="margin-bottom:14px"
        onsubmit="return confirm(<?= $h(json_encode(sprintf($t('pl_add_all_confirm'), $res['total']), JSON_UNESCAPED_UNICODE)) ?>)">
    <input type="hidden" name="do" value="pricelist_add_all">
    <input type="hidden" name="id" value="<?= $listId ?>">
    <input type="hidden" name="q" value="<?= $h($q) ?>">
    <input type="hidden" name="category" value="<?= $h($cat) ?>">
    <input type="hidden" name="stock" value="<?= $stk ? 1 : '' ?>">
    <input type="hidden" name="back" value="<?= $h($keep()) ?>">
    <button class="btn ghost tiny"><?= $h(sprintf($t('pl_add_all'), number_format($res['total'], 0, ',', '.'))) ?></button>
  </form>
<?php endif; ?>

<?php if ($res['pages'] > 1): ?>
  <div class="pl-pager">
    <?php if ($res['page'] > 1): ?><a class="btn ghost tiny" href="<?= $h($keep(['p' => $res['page'] - 1])) ?>">&larr;</a><?php endif; ?>
    <span class="muted small"><?= (int)$res['page'] ?> / <?= (int)$res['pages'] ?> · <?= number_format($res['total'], 0, ',', '.') ?></span>
    <?php if ($res['page'] < $res['pages']): ?><a class="btn ghost tiny" href="<?= $h($keep(['p' => $res['page'] + 1])) ?>">&rarr;</a><?php endif; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php elseif ($list):
    // ======================= the catalogue =======================
    $q    = trim((string)($_GET['q'] ?? ''));
    $cat  = trim((string)($_GET['category'] ?? ''));
    $page = max(1, (int)($_GET['p'] ?? 1));
    $res  = PriceLists::items($listId, ['q' => $q, 'category' => $cat], $page);
    $cats = PriceLists::categories($listId);
    $keep = fn(array $over = []): string => '?' . http_build_query(array_filter(array_merge(
        ['tab' => 'pricelists', 'list' => $listId, 'q' => $q, 'category' => $cat], $over),
        fn($v) => $v !== '' && $v !== null));
    $pdfUrl = '?' . http_build_query(array_filter(['plpdf' => $listId, 'q' => $q, 'category' => $cat], fn($v) => $v !== ''));
?>
<div class="cu-top">
  <a class="btn ghost tiny" href="?tab=pricelists">&larr; <?= $h($t('pl_back_lists')) ?></a>
  <h2 style="margin:0"><?= $h($list['name']) ?></h2>
  <?php if ($isAdminHere && (int)$list['visible'] !== 1): ?><span class="pill pl-hid"><?= $h($t('pl_hidden')) ?></span><?php endif; ?>
  <span class="cu-acts">
    <a class="btn tiny" href="<?= $h($pdfUrl . '&dl=1') ?>"><?= svg('download') ?> <?= $h($t('pl_pdf')) ?></a>
    <a class="btn ghost tiny" href="<?= $h($pdfUrl) ?>" target="_blank" rel="noopener"><?= svg('printer') ?> <?= $h($t('pl_print')) ?></a>
    <?php if ($isAdminHere): ?>
      <a class="btn ghost tiny" href="?tab=pricelists&list=<?= $listId ?>&manage=1"><?= svg('articles') ?> <?= $h($t('pl_manage')) ?></a>
    <?php endif; ?>
  </span>
</div>
<?php if (trim((string)$list['description']) !== ''): ?>
  <p class="muted" style="margin:-6px 0 12px;max-width:860px;white-space:pre-line"><?= $h($list['description']) ?></p>
<?php endif; ?>

<form method="get" class="pl-filter">
  <input type="hidden" name="tab" value="pricelists"><input type="hidden" name="list" value="<?= $listId ?>">
  <input type="search" name="q" value="<?= $h($q) ?>" placeholder="<?= $h($t('pl_search_ph')) ?>">
  <?php if ($cats): ?>
    <select name="category" onchange="this.form.submit()">
      <option value=""><?= $h($t('ar_all_categories')) ?></option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= $h($c) ?>"<?= $c === $cat ? ' selected' : '' ?>><?= $h($c) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
  <button class="btn ghost"><?= $h($t('pl_search')) ?></button>
</form>
<p class="muted small" style="margin:0 0 12px">
  <?= $h(sprintf($t($res['total'] === 1 ? 'pl_n_products1' : 'pl_n_products'), number_format($res['total'], 0, ',', '.'))) ?>
  · <?= $h(pricelist_terms($t, $list, $lang)) ?>
  <?php if ($q !== '' || $cat !== ''): ?> · <a href="?tab=pricelists&list=<?= $listId ?>"><?= $h($t('clear')) ?></a><?php endif; ?>
</p>

<?php if (!$res['rows']): ?>
  <div class="card"><div class="empty">
    <?= $h($t($q !== '' || $cat !== '' ? 'pl_none_found' : ($isAdminHere ? 'pl_empty_admin' : 'pl_empty'))) ?>
    <?php if ($isAdminHere && $q === '' && $cat === ''): ?>
      <p style="margin-top:12px"><a class="btn tiny" href="?tab=pricelists&list=<?= $listId ?>&manage=1&mode=all"><?= $h($t('pl_add_products')) ?></a></p>
    <?php endif; ?>
  </div></div>
<?php else: ?>
<div class="pl-grid">
  <?php foreach ($res['rows'] as $r):
      [$href, $ext] = $photoHref($r);
      $sheet = '?tab=pricelists&list=' . $listId . '&item=' . (int)$r['id'];
      $price = PriceLists::shownPrice($r, $list);
      $av    = PriceLists::availability($r); ?>
    <div class="pl-card">
      <a class="pl-ph" href="<?= $h($href) ?>"<?= $ext ? ' target="_blank" rel="noopener"' : '' ?>
         title="<?= $h($t($ext ? 'pl_open_link' : 'pl_open_sheet')) ?>">
        <?php if (!empty($r['cover_id'])): ?>
          <img src="?amf=<?= (int)$r['cover_id'] ?>&s=t" alt="<?= $h($r['description']) ?>" loading="lazy">
        <?php else: ?>
          <span class="pl-noph"><?= svg('image') ?></span>
        <?php endif; ?>
        <?php if ($ext): ?><span class="pl-ext"><?= svg('external') ?></span><?php endif; ?>
      </a>
      <div class="pl-body">
        <div class="pl-code"><?= $h($r['code']) ?></div>
        <a class="pl-name" href="<?= $h($sheet) ?>"><?= $h($r['description'] ?: $r['code']) ?></a>
        <?php if ($price > 0): ?>
          <div class="pl-price"><?= $h($eur($price)) ?>
            <span><?= $h($t((int)$list['vat_included'] === 1 ? 'pl_vat_incl' : 'pl_plus_vat')) ?></span></div>
        <?php else: ?>
          <div class="pl-price req"><?= $h($t('pl_on_request')) ?></div>
        <?php endif; ?>
        <div class="pl-av av-<?= $h($av) ?>"><?= $h($t('pl_av_' . $av)) ?></div>
        <a class="btn ghost tiny pl-more" href="<?= $h($sheet) ?>"><?= $h($t('pl_open_sheet')) ?></a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($res['pages'] > 1): ?>
  <div class="pl-pager">
    <?php if ($res['page'] > 1): ?><a class="btn ghost tiny" href="<?= $h($keep(['p' => $res['page'] - 1])) ?>">&larr;</a><?php endif; ?>
    <span class="muted small"><?= (int)$res['page'] ?> / <?= (int)$res['pages'] ?></span>
    <?php if ($res['page'] < $res['pages']): ?><a class="btn ghost tiny" href="<?= $h($keep(['p' => $res['page'] + 1])) ?>">&rarr;</a><?php endif; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php else:
    // ======================= the lists =======================
    $lists = PriceLists::all(!$isAdminHere);
?>
<div class="cu-top">
  <h2 style="margin:0"><?= $h($t('nav_pricelists')) ?></h2>
  <span class="muted small"><?= $h($t($isAdminHere ? 'pl_sub_admin' : 'pl_sub')) ?></span>
  <?php if ($isAdminHere): ?>
    <span class="cu-acts">
      <details class="drawer"<?= !$lists ? ' open' : '' ?>>
        <summary class="btn"><?= svg('pricelists') ?> <?= $h($t('pl_new')) ?></summary>
        <div style="position:absolute;right:0;z-index:6;width:min(560px,92vw)"><?php pricelist_form($h, $t); ?></div>
      </details>
    </span>
  <?php endif; ?>
</div>

<?php if (!$lists): ?>
  <div class="card"><div class="empty"><?= $h($t($isAdminHere ? 'pl_none_admin' : 'pl_none')) ?></div></div>
<?php else: ?>
<div class="pl-lists">
  <?php foreach ($lists as $l): $lid = (int)$l['id']; ?>
    <div class="card pl-list">
      <div style="display:flex;gap:10px;align-items:flex-start">
        <span class="pl-list-ic"><?= svg('pricelists') ?></span>
        <div style="flex:1;min-width:0">
          <a href="?tab=pricelists&list=<?= $lid ?>"><strong style="font-size:16px"><?= $h($l['name']) ?></strong></a>
          <div class="muted small"><?= $h(sprintf($t((int)$l['items'] === 1 ? 'pl_n_products1' : 'pl_n_products'), (int)$l['items'])) ?>
            · <?= $h(pricelist_terms($t, $l, $lang)) ?></div>
          <?php if ($isAdminHere && (int)$l['visible'] !== 1): ?><span class="pill pl-hid"><?= $h($t('pl_hidden')) ?></span><?php endif; ?>
        </div>
      </div>
      <?php if (trim((string)$l['description']) !== ''): ?>
        <p class="muted small" style="margin:10px 0 0;white-space:pre-line"><?= $h($l['description']) ?></p>
      <?php endif; ?>
      <div class="pl-list-acts">
        <a class="btn tiny" href="?tab=pricelists&list=<?= $lid ?>"><?= svg('eye') ?> <?= $h($t('pl_open')) ?></a>
        <a class="btn ghost tiny" href="?plpdf=<?= $lid ?>&dl=1"><?= svg('download') ?> PDF</a>
        <?php if ($isAdminHere): ?>
          <a class="btn ghost tiny" href="?tab=pricelists&list=<?= $lid ?>&manage=1"><?= svg('articles') ?> <?= $h($t('pl_manage')) ?></a>
        <?php endif; ?>
      </div>
      <?php if ($isAdminHere): ?>
        <details class="drawer" style="margin:10px 0 0">
          <summary class="muted small" style="cursor:pointer"><?= svg('pen') ?> <?= $h($t('pl_edit')) ?></summary>
          <?php pricelist_form($h, $t, $l); ?>
          <form method="post" style="margin-top:8px" onsubmit="return confirm(<?= $h(json_encode($t('pl_del_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
            <input type="hidden" name="do" value="pricelist_delete"><input type="hidden" name="id" value="<?= $lid ?>">
            <button class="btn danger tiny"><?= $h($t('pl_delete')) ?></button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<style>
.cu-top{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap}
.cu-acts{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;position:relative}
.pl-lists{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
.pl-list{margin:0}
.pl-hid{text-transform:none;color:var(--amber);background:var(--amber-bg);border-color:transparent;margin-top:6px}
.pl-kv{display:grid;grid-template-columns:max-content 1fr;border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin:0}
.pl-kv dt,.pl-kv dd{margin:0;padding:10px 14px;border-bottom:1px solid var(--line);min-width:0;overflow-wrap:anywhere}
.pl-kv dt{color:var(--muted)}
.pl-kv dt:last-of-type,.pl-kv dd:last-of-type{border-bottom:0}
.pl-list-ic{width:36px;height:36px;border-radius:9px;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.pl-list-ic svg{width:18px;height:18px}
.pl-list-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.pl-filter{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.pl-filter input{flex:1;min-width:200px}
.pl-filter select{width:auto;max-width:260px}
.pl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}
.pl-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;transition:border-color .12s}
.pl-card:hover{border-color:var(--line2)}
.pl-ph{position:relative;display:block;aspect-ratio:1/1;background:#fff;border-bottom:1px solid var(--line)}
.pl-ph img{width:100%;height:100%;object-fit:contain;padding:10px;display:block}
.pl-noph{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#b7bdc9;background:#f4f5f8}
.pl-noph svg{width:42px;height:42px}
.pl-ext{position:absolute;top:8px;right:8px;width:28px;height:28px;border-radius:7px;background:rgba(14,19,28,.72);color:#fff;display:flex;align-items:center;justify-content:center}
.pl-ext svg{width:14px;height:14px}
.pl-body{padding:12px 14px 14px;display:flex;flex-direction:column;gap:4px;flex:1}
.pl-code{font-size:11.5px;color:var(--muted);font-family:ui-monospace,Menlo,Consolas,monospace;overflow-wrap:anywhere}
.pl-name{font-weight:600;line-height:1.35;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:3;line-clamp:3;overflow:hidden;overflow-wrap:anywhere}
.pl-name:hover{color:var(--accent)}
.pl-price{font-size:19px;font-weight:700;margin-top:auto;padding-top:8px;letter-spacing:-.01em}
.pl-price span{font-size:11.5px;font-weight:500;color:var(--muted);margin-left:4px;letter-spacing:0}
.pl-price.req{font-size:14px;color:var(--muted)}
.pl-price.big{font-size:30px;margin:0;padding:0}
.pl-price.big.req{font-size:18px}
.pl-av{font-size:12px;font-weight:600}
.pl-av::before{content:'';display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px;background:currentColor;vertical-align:1px}
.av-in_stock{color:var(--green)} .av-on_order{color:var(--amber)} .av-none{color:var(--muted)}
.pl-more{margin-top:10px;justify-content:center}
.pl-pager{display:flex;gap:8px;align-items:center;margin-top:16px;flex-wrap:wrap}
.pl-sheet{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:20px;align-items:start}
.pl-gal-main{position:relative;display:flex;align-items:center;justify-content:center;aspect-ratio:1/1;background:#fff;border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
.pl-gal-main img{width:100%;height:100%;object-fit:contain;padding:16px}
.pl-gal-main.pl-noph{position:relative;flex-direction:column;gap:8px;color:#9aa1ad}
.pl-gal-main.pl-noph svg{width:56px;height:56px}
.pl-gal-thumbs{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.pl-gal-thumbs button{width:68px;height:68px;padding:4px;background:#fff;border:2px solid var(--line);border-radius:9px;cursor:pointer}
.pl-gal-thumbs button.on{border-color:var(--accent)}
.pl-gal-thumbs img{width:100%;height:100%;object-fit:contain;display:block}
.pl-info{min-width:0}
.pl-docs{display:flex;flex-direction:column;gap:6px}
.pl-docs a{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--surface)}
.pl-docs a:hover{border-color:var(--accent)}
.pl-docs a span{flex:1;min-width:0;overflow-wrap:anywhere}
.pl-docs svg{width:15px;height:15px;flex:0 0 auto;color:var(--muted)}
.pl-mini{width:40px;height:40px;object-fit:contain;background:#fff;border-radius:6px;display:block}
.pl-mini-no{display:flex;align-items:center;justify-content:center;background:var(--surface2);color:var(--muted)}
.pl-mini-no svg{width:16px;height:16px}
@media (max-width:900px){.pl-sheet{grid-template-columns:1fr}}
@media (max-width:560px){
  .pl-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .pl-body{padding:10px}
  .pl-price{font-size:16px}
  .pl-price span{display:block;margin:0}
  .pl-lists{grid-template-columns:1fr}
  .cu-acts{margin-left:0;width:100%}
  .pl-filter input{min-width:0;width:100%}
  .pl-filter select{max-width:none;width:100%}
}
</style>
