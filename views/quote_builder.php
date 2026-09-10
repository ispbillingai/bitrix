<?php
/**
 * Quote builder — one quote request turned into a priced document.
 *
 * The seller asked in words ("860 + smart rt labware, sconto 15%, leasing");
 * the office turns that into lines here: products drawn from the warehouse
 * (priced from the list, with the stock shown), services that are not stock at
 * all (a support contract, an installation), a discount per line and on the
 * whole quote. "Generate" renders the PDF, attaches it to the request and tells
 * the seller it is ready to send; the existing signing flow does the rest.
 *
 * Stock is NOT touched here. It is drawn when the customer SIGNS — a quote that
 * is never accepted must not empty the shelves.
 *
 * Included by views/quotes.php when ?build=<id>. Admin only.
 * In scope: $t, $h, $pdo, $uid, $isAgent, $buildId.
 */

use Glue\Crm\QuoteRequests;

$q = QuoteRequests::find($buildId);
if (!$q) {
    echo '<div class="card"><div class="empty">' . $h($t('qt_err_not_found')) . '</div></div>';
    return;
}
$lead    = \Glue\Crm\Leads::find((int)$q['lead_id']) ?: [];
$contact = !empty($lead['contact_id']) ? (\Glue\Crm\Contacts::find((int)$lead['contact_id']) ?: []) : [];
$lines   = QuoteRequests::lines($buildId);
$locked  = !empty($q['stock_applied_at']);
$doc     = !empty($q['document_id']) ? \Glue\Sign\Documents::find((int)$q['document_id']) : null;
$reqBy   = 'CRM';
if (!empty($q['requested_by'])) {
    $rs = $pdo->prepare('SELECT COALESCE(NULLIF(full_name, ""), username) FROM users WHERE id = ?');
    $rs->execute([(int)$q['requested_by']]);
    $reqBy = (string)($rs->fetchColumn() ?: 'CRM');
}
$num = fn($n): string => rtrim(rtrim(number_format((float)$n, 2, ',', ''), '0'), ',');
$pr  = fn($n): string => number_format((float)$n, 2, ',', '');

/** One editable row. $i is the array index; '__I__' in the JS templates. */
$row = function (array $l, string $i) use ($h, $t, $num, $pr): string {
    $isArt = ($l['kind'] ?? 'article') === 'article';
    $avail = array_key_exists('stock_available', $l) && $l['stock_available'] !== null
        ? (float)$l['stock_available'] : null;
    ob_start(); ?>
<tr class="qb-row" data-kind="<?= $isArt ? 'article' : 'service' ?>"<?= $avail !== null ? ' data-avail="' . $h($avail) . '"' : '' ?>>
  <td>
    <input type="hidden" name="lines[<?= $h($i) ?>][kind]" value="<?= $isArt ? 'article' : 'service' ?>">
    <input type="hidden" name="lines[<?= $h($i) ?>][article_id]" value="<?= (int)($l['article_id'] ?? 0) ?: '' ?>">
    <?php if ($isArt): ?>
      <input type="hidden" name="lines[<?= $h($i) ?>][code]" value="<?= $h($l['code'] ?? '') ?>">
      <strong class="small qb-code-text"><?= $h($l['code'] ?? '') ?></strong>
    <?php else: ?>
      <span class="muted small"><?= $h($t('qt_service')) ?></span>
    <?php endif; ?>
  </td>
  <td>
    <input class="qb-desc" name="lines[<?= $h($i) ?>][description]" value="<?= $h($l['description'] ?? '') ?>"
           placeholder="<?= $isArt ? '' : $h($t('qt_service_ph')) ?>">
    <?php if ($isArt && $avail !== null): ?>
      <div class="muted small qb-avail"><?= $h($t('qt_avail')) ?> <span class="qb-avail-n"><?= $h($num($avail)) ?></span><span
        class="qb-short" hidden style="color:var(--amber)"> · <?= $h($t('qt_short')) ?></span></div>
    <?php endif; ?>
    <?php if ($isArt): ?>
      <div class="small qb-noprice" style="color:var(--amber)"<?= (float)($l['unit_price'] ?? 0) > 0 ? ' hidden' : '' ?>><?= $h($t('qt_no_price')) ?></div>
    <?php endif; ?>
  </td>
  <td><input class="qb-n qb-qty" name="lines[<?= $h($i) ?>][qty]" value="<?= $h($num($l['qty'] ?? 1)) ?>" inputmode="decimal"></td>
  <td><input class="qb-n qb-price" name="lines[<?= $h($i) ?>][price]" value="<?= $h($pr($l['unit_price'] ?? 0)) ?>" inputmode="decimal"></td>
  <td><input class="qb-n qb-disc" name="lines[<?= $h($i) ?>][discount]" value="<?= $h($num($l['discount_pct'] ?? 0)) ?>" inputmode="decimal"></td>
  <td><input class="qb-n qb-vat" name="lines[<?= $h($i) ?>][vat]" value="<?= $h($num($l['vat_rate'] ?? 22)) ?>" inputmode="decimal"></td>
  <td class="qb-tot">—</td>
  <td><button type="button" class="btn ghost tiny qb-del" title="<?= $h($t('qt_remove')) ?>">✕</button></td>
</tr>
<?php
    return (string)ob_get_clean();
};
?>
<div class="cu-top">
  <a class="btn ghost tiny" href="?tab=quotes">&larr; <?= $h($t('nav_quotes')) ?></a>
  <h2 style="margin:0"><?= $h($t('qt_build_h')) ?> <?= $h($q['number'] ?: ('#' . (int)$q['id'])) ?>
    <span class="pill"><?= $h($t('qt_st_' . $q['status'])) ?></span></h2>
</div>

<?php if ($locked): ?>
  <div class="flash" style="margin-bottom:14px">
    <?= $h(sprintf($t('qt_accepted_note'), date('d/m/Y H:i', (int)strtotime((string)$q['accepted_at'])))) ?></div>
<?php endif; ?>

<div class="cu-cols">
<div class="cu-main">

  <div class="card">
    <h3><?= svg('messages') ?> <?= $h($t('qt_asked')) ?></h3>
    <div style="white-space:pre-wrap;line-height:1.55"><?= $h($q['notes']) ?></div>
    <div class="muted small" style="margin-top:8px"><?= $h($reqBy) ?> · <?= $h(short_time($q['created_at'])) ?></div>
  </div>

  <?php // The seller sent the generated quote back: what they want changed sits
        // right above the lines it is about. Regenerating clears the status. ?>
  <?php if ((string)$q['status'] === QuoteRequests::REVISION && !empty($q['revision_note'])): ?>
  <div class="card" style="border-color:var(--amber)">
    <h3 style="color:var(--amber)">✏️ <?= $h($t('qt_revision_h')) ?></h3>
    <div style="white-space:pre-wrap;line-height:1.55"><?= $h($q['revision_note']) ?></div>
    <div class="muted small" style="margin-top:8px"><?= $h($reqBy) ?> · <?= $h(short_time($q['revision_requested_at'])) ?></div>
  </div>
  <?php endif; ?>

  <form method="post" class="card" id="qb-form">
    <input type="hidden" name="do" value="quote_lines_save">
    <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
    <fieldset<?= $locked ? ' disabled' : '' ?> style="border:0;padding:0;margin:0;min-width:0">
      <h3><?= svg('quotes') ?> <?= $h($t('qt_lines')) ?></h3>
      <div style="overflow-x:auto">
        <table class="qb-table">
          <thead><tr>
            <th><?= $h($t('qt_col_code')) ?></th><th><?= $h($t('qt_col_desc')) ?></th>
            <th><?= $h($t('qt_col_qty')) ?></th><th><?= $h($t('qt_col_price')) ?></th>
            <th><?= $h($t('qt_col_disc')) ?></th><th><?= $h($t('qt_col_vat')) ?></th>
            <th style="text-align:right"><?= $h($t('qt_col_total')) ?></th><th></th>
          </tr></thead>
          <tbody id="qb-body">
            <?php foreach ($lines as $k => $l) { echo $row($l, (string)$k); } ?>
          </tbody>
        </table>
      </div>
      <div id="qb-empty" class="muted small" style="margin:10px 0"<?= $lines ? ' hidden' : '' ?>><?= $h($t('qt_no_lines')) ?></div>

      <div class="qb-add">
        <span class="qb-pick">
          <input type="text" id="qb-art" autocomplete="off" placeholder="<?= $h($t('qt_add_article_ph')) ?>">
          <div id="qb-hits" class="qb-hits" hidden></div>
        </span>
        <button type="button" class="btn ghost tiny" id="qb-svc"><?= $h($t('qt_add_service')) ?></button>
      </div>

      <div class="row" style="margin-top:16px">
        <label class="fld"><span><?= $h($t('qt_doc_discount')) ?></span>
          <input name="discount_pct" id="qb-dd" class="qb-n" style="width:100%" inputmode="decimal"
                 value="<?= $h($num($q['discount_pct'] ?? 0)) ?>"></label>
        <label class="fld"><span><?= $h($t('qt_valid_until')) ?></span>
          <input type="date" name="valid_until"
                 value="<?= $h($q['valid_until'] ?: date('Y-m-d', strtotime('+30 days'))) ?>"></label>
      </div>
      <label class="fld"><span><?= $h($t('qt_cust_notes')) ?></span>
        <textarea name="customer_notes" rows="3" placeholder="<?= $h($t('qt_cust_notes_ph')) ?>"><?= $h($q['customer_notes'] ?? '') ?></textarea></label>

      <div class="qb-totals">
        <div><span><?= $h($t('qt_net')) ?></span><b>EUR <span id="qb-t-net">0,00</span></b></div>
        <div><span><?= $h($t('qt_discounts')) ?></span><span>EUR <span id="qb-t-disc">0,00</span></span></div>
        <div><span><?= $h($t('qt_vat_total')) ?></span><span>EUR <span id="qb-t-vat">0,00</span></span></div>
        <div class="grand"><span><?= $h($t('qt_grand')) ?></span><b>EUR <span id="qb-t-total">0,00</span></b></div>
      </div>

      <p class="muted small" style="margin:12px 0 6px"><?= $h($t('qt_stock_on_accept')) ?></p>
      <?php if ($doc && in_array((string)$doc['status'], ['sent', 'viewed'], true)): ?>
        <p class="muted small" style="margin:0 0 10px;color:var(--amber)"><?= $h($t('qt_regen_note')) ?></p>
      <?php endif; ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn ghost" name="then" value="save"><?= $h($t('qt_save_draft')) ?></button>
        <button class="btn" name="then" value="generate" id="qb-gen"><?= svg('documents') ?> <?= $h($t('qt_generate')) ?></button>
      </div>
    </fieldset>
  </form>
</div>

<div class="cu-side">
  <div class="card">
    <h3><?= svg('customers') ?> <?= $h($t('qt_customer')) ?></h3>
    <p style="margin:0 0 4px"><strong><?= $h(($contact['company'] ?? '') ?: ($contact['name'] ?? ($lead['customer_name'] ?? ''))) ?></strong></p>
    <?php if (!empty($lead['customer_name'])): ?><div class="small"><?= $h($lead['customer_name']) ?></div><?php endif; ?>
    <?php $cv = ($contact['vat_number'] ?? '') ?: ($lead['vat_number'] ?? ''); if ($cv): ?>
      <div class="muted small"><?= $h($t('f_vat')) ?> <?= $h($cv) ?></div><?php endif; ?>
    <div class="muted small"><?= phone_link($h, ($contact['phone'] ?? '') ?: ($lead['customer_phone'] ?? '')) ?></div>
    <div class="muted small"><?= $h(($contact['email'] ?? '') ?: ($lead['customer_email'] ?? '')) ?></div>
    <?php if (!empty($lead['contact_id'])): ?>
      <a class="btn ghost tiny" style="margin-top:10px" href="?tab=customers&id=<?= (int)$lead['contact_id'] ?>"><?= $h($t('qt_open_customer')) ?></a>
    <?php endif; ?>
  </div>

  <?php if ($doc): ?>
  <div class="card">
    <h3><?= svg('documents') ?> <?= $h($t('qt_document')) ?></h3>
    <a href="?sdl=<?= (int)$doc['id'] ?>&k=orig"><?= $h($doc['title']) ?></a>
    <div style="margin-top:6px"><span class="pill pill-<?= $h($doc['status']) ?>"><?= $h($t('dc_st_' . $doc['status'])) ?></span></div>
    <?php if (!empty($doc['signed_path'])): ?>
      <a class="btn ghost tiny" style="margin-top:10px" href="?sdl=<?= (int)$doc['id'] ?>&k=signed"><?= $h($t('dc_dl_signed')) ?></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</div>

<template id="qb-tpl-article"><?= $row(['kind' => 'article', 'qty' => 1, 'unit_price' => 0, 'discount_pct' => 0,
    'vat_rate' => 22, 'stock_available' => 0, 'code' => ''], '__I__') ?></template>
<template id="qb-tpl-service"><?= $row(['kind' => 'service', 'qty' => 1, 'unit_price' => 0, 'discount_pct' => 0,
    'vat_rate' => 22], '__I__') ?></template>

<style>
.cu-top{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap}
.cu-cols{display:flex;gap:16px;align-items:flex-start}
.cu-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:16px}
.cu-main .card{margin:0}
.cu-side{width:320px;flex-shrink:0;display:flex;flex-direction:column;gap:16px}
.cu-side .card{margin:0}
@media (max-width:1000px){.cu-cols{flex-direction:column}.cu-side{width:100%}}
.qb-table{width:100%}
.qb-table td{vertical-align:top;padding:6px 6px}
.qb-table input{width:100%;min-width:0}
.qb-table .qb-desc{min-width:220px}
.qb-n{width:76px;text-align:right}
.qb-tot{text-align:right;white-space:nowrap;padding-top:12px !important;font-weight:600}
.qb-add{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:flex-start}
.qb-pick{position:relative;flex:1;min-width:240px}
.qb-pick input{width:100%}
.qb-hits{position:absolute;z-index:40;left:0;right:0;top:100%;margin-top:4px;max-height:300px;overflow-y:auto;
  background:var(--surface,#161c28);border:1px solid var(--line,#28303f);border-radius:10px;box-shadow:0 10px 26px rgba(0,0,0,.35)}
.qb-hits button{display:block;width:100%;text-align:left;padding:9px 12px;border:0;background:transparent;
  color:inherit;font:inherit;cursor:pointer;border-bottom:1px solid var(--line,#28303f)}
.qb-hits button:last-child{border-bottom:0}
.qb-hits button:hover{background:var(--surface2,#1c2533)}
.qb-hits .sub{display:block;font-size:11.5px;color:var(--muted,#8b95a7);margin-top:2px}
.qb-hits .none{padding:9px 12px;font-size:12px;color:var(--muted,#8b95a7)}
.qb-totals{margin:16px 0 0 auto;max-width:340px;display:flex;flex-direction:column;gap:6px}
.qb-totals div{display:flex;justify-content:space-between;gap:16px}
.qb-totals .grand{border-top:1px solid var(--line);padding-top:8px;font-size:16px}
</style>
<script>
var QB_NONE  = <?= json_encode($t('qt_no_article'), JSON_UNESCAPED_UNICODE) ?>;
var QB_AVAIL = <?= json_encode($t('qt_avail'), JSON_UNESCAPED_UNICODE) ?>;
var QB_NOPRICE = <?= json_encode($t('qt_no_price_short'), JSON_UNESCAPED_UNICODE) ?>;
var QB_LISTINO = <?= json_encode($t('qt_listino'), JSON_UNESCAPED_UNICODE) ?>;
var QB_VENDITA = <?= json_encode($t('qt_vendita'), JSON_UNESCAPED_UNICODE) ?>;
var QB_ZERO    = <?= json_encode($t('qt_zero_confirm'), JSON_UNESCAPED_UNICODE) ?>;
(function () {
  var body = document.getElementById('qb-body');
  if (!body) return;
  var idx = <?= count($lines) ?> + 1000;   // new rows never collide with saved ones

  // Italian typing: "1.234,56" and "1234.56" both mean the same amount.
  function num(s) {
    s = String(s || '').trim();
    if (!s) return 0;
    s = s.replace(/[^0-9,.\-]/g, '');
    var c = s.lastIndexOf(','), d = s.lastIndexOf('.');
    if (c > -1 && c > d) { s = s.replace(/\./g, '').replace(',', '.'); } else { s = s.replace(/,/g, ''); }
    var v = parseFloat(s);
    return isNaN(v) ? 0 : v;
  }
  function eur(n) { return n.toLocaleString('it-IT', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
  function r2(n) { return Math.round(n * 100) / 100; }

  // A preview of what the server will compute — the server's figures are the
  // ones that go on the PDF.
  function recompute() {
    var dd = Math.min(100, Math.max(0, num(document.getElementById('qb-dd').value)));
    var gross = 0, net = 0, vat = {};
    body.querySelectorAll('tr.qb-row').forEach(function (tr) {
      var q = num(tr.querySelector('.qb-qty').value), p = num(tr.querySelector('.qb-price').value),
          ds = num(tr.querySelector('.qb-disc').value), v = num(tr.querySelector('.qb-vat').value);
      var g = q * p, after = r2(g * (1 - ds / 100)), n = r2(after * (1 - dd / 100));
      tr.querySelector('.qb-tot').textContent = eur(after);
      gross += g; net += n; vat[v] = (vat[v] || 0) + n;
      var sh = tr.querySelector('.qb-short'), av = tr.dataset.avail;
      if (sh) sh.hidden = !(av !== undefined && av !== '' && q > parseFloat(av));
      var np = tr.querySelector('.qb-noprice');
      if (np) np.hidden = p > 0;
    });
    var vt = 0;
    Object.keys(vat).forEach(function (r) { vt += r2(vat[r] * parseFloat(r) / 100); });
    document.getElementById('qb-t-net').textContent = eur(net);
    document.getElementById('qb-t-disc').textContent = eur(gross - net);
    document.getElementById('qb-t-vat').textContent = eur(vt);
    document.getElementById('qb-t-total').textContent = eur(net + vt);
    document.getElementById('qb-empty').hidden = body.querySelector('tr.qb-row') !== null;
  }

  function add(tplId, fill) {
    var html = document.getElementById(tplId).innerHTML.split('__I__').join(String(idx++));
    var tmp = document.createElement('tbody');
    tmp.innerHTML = html.trim();
    var tr = tmp.firstElementChild;
    body.appendChild(tr);
    if (fill) fill(tr);
    recompute();
    var f = tr.querySelector(fill ? '.qb-qty' : '.qb-desc');
    if (f) f.focus();
  }

  body.addEventListener('input', recompute);
  document.getElementById('qb-dd').addEventListener('input', recompute);
  body.addEventListener('click', function (e) {
    var b = e.target.closest('.qb-del');
    if (b) { b.closest('tr').remove(); recompute(); }
  });
  document.getElementById('qb-svc').addEventListener('click', function () { add('qb-tpl-service'); });

  // Products from the warehouse: type, pick, and the row fills itself.
  var box = document.getElementById('qb-art'), hits = document.getElementById('qb-hits'), timer = null;
  function close() { hits.hidden = true; hits.innerHTML = ''; }
  box.addEventListener('input', function () {
    var q = box.value.trim();
    if (timer) clearTimeout(timer);
    if (q.length < 2) { close(); return; }
    timer = setTimeout(function () {
      fetch('?find=articles&q=' + encodeURIComponent(q), {credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (list) {
          hits.innerHTML = '';
          if (!list.length) {
            var d = document.createElement('div'); d.className = 'none'; d.textContent = QB_NONE;
            hits.appendChild(d); hits.hidden = false; return;
          }
          list.forEach(function (a) {
            var b = document.createElement('button'); b.type = 'button';
            var st = document.createElement('strong'); st.textContent = a.code; b.appendChild(st);
            b.appendChild(document.createTextNode(' — ' + a.description));
            var sp = document.createElement('span'); sp.className = 'sub';
            var prices = [];
            if (a.listino > 0) prices.push(QB_LISTINO + ' EUR ' + eur(a.listino));
            if (a.vendita > 0) prices.push(QB_VENDITA + ' EUR ' + eur(a.vendita));
            sp.textContent = QB_AVAIL + ' ' + a.available.toLocaleString('it-IT') + ' · '
              + (prices.length ? prices.join(' · ') : QB_NOPRICE);
            if (!prices.length) sp.style.color = 'var(--amber)';
            b.appendChild(sp);
            b.addEventListener('click', function () {
              close(); box.value = '';
              add('qb-tpl-article', function (tr) {
                tr.dataset.avail = a.available;
                tr.querySelector('input[name$="[article_id]"]').value = a.id;
                tr.querySelector('input[name$="[code]"]').value = a.code;
                tr.querySelector('.qb-code-text').textContent = a.code;
                tr.querySelector('.qb-desc').value = a.description;
                tr.querySelector('.qb-price').value = a.price.toFixed(2).replace('.', ',');
                tr.querySelector('.qb-vat').value = String(a.vat).replace('.', ',');
                var n = tr.querySelector('.qb-avail-n');
                if (n) n.textContent = a.available.toLocaleString('it-IT');
              });
              if (a.source === 'none') {
                var last = body.lastElementChild, pr = last && last.querySelector('.qb-price');
                if (pr) { pr.focus(); pr.select(); }
              }
            });
            hits.appendChild(b);
          });
          hits.hidden = false;
        })
        .catch(close);
    }, 220);
  });
  box.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') e.preventDefault();   // never submit the form from the search box
    if (e.key === 'Escape') close();
  });
  document.addEventListener('click', function (e) {
    if (e.target !== box && !hits.contains(e.target)) close();
  });
  // A 0,00 line may be a deliberate freebie, so it is asked about, not refused.
  var gen = document.getElementById('qb-gen');
  if (gen) {
    gen.addEventListener('click', function (e) {
      var zero = 0;
      body.querySelectorAll('tr.qb-row').forEach(function (tr) {
        if (num(tr.querySelector('.qb-price').value) <= 0) zero++;
      });
      if (zero > 0 && !confirm(QB_ZERO.replace('%d', zero))) e.preventDefault();
    });
  }
  recompute();
})();
</script>
