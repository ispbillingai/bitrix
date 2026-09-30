<?php
/**
 * Mass WhatsApp/email campaigns.
 *
 * Recipients come from three places that add up: customers ticked in the
 * picker, whole groups of the Clienti tab, and anything still typed by hand.
 * The office asked for the first two — "scegliere i clienti dalla lista così da
 * non scriverli" — so the typed box is now the exception, folded away.
 *
 * A campaign can also carry a photo or a document: WhatsApp gets it by URL
 * (photo in the chat, document as a file), email as an attachment.
 *
 * In scope: $t, $h, $pdo.
 */

use Glue\Campaign\Audience;
use Glue\Campaign\Media;
use Glue\Crm\Customers;

$rows = $pdo->query('SELECT * FROM campaigns ORDER BY id DESC LIMIT 100')->fetchAll();
$cnt  = Customers::counters();
// The groups on offer, with the count the Clienti tab shows for each. The exact
// number that can be reached depends on the channel (a phone for WhatsApp, an
// address for email) and is reported once the campaign is created.
$groups = [
    'all'     => [$t('camp_g_all'), $cnt['total']],
    'support' => [$t('camp_g_support'), $cnt['support']],
    'expired' => [$t('camp_g_expired'), $cnt['expired']],
    'owing'   => [$t('camp_g_owing'), $cnt['owing']],
    'leads'   => [$t('camp_g_leads'), $cnt['leads']],
];
$fmt = fn($n): string => number_format((float)$n, 0, ',', '.');
?>
<h2><?= $h($t('camp_title')) ?></h2>
<div class="warn"><?= $h($t('camp_warn')) ?></div>

<form method="post" class="card" enctype="multipart/form-data" id="camp-form">
  <input type="hidden" name="do" value="create_campaign">
  <h3><?= $h($t('camp_new')) ?></h3>
  <div class="row">
    <label class="fld"><span><?= $h($t('camp_name')) ?></span><input name="name"></label>
    <label class="fld"><span><?= $h($t('camp_channel')) ?></span>
      <select name="channel" id="camp-channel"><option value="whatsapp">WhatsApp</option><option value="email">Email</option></select></label>
    <label class="fld" id="camp-subject-fld" hidden><span><?= $h($t('camp_subject')) ?></span><input name="subject"></label>
  </div>
  <label class="fld"><span><?= $h($t('camp_body')) ?></span>
    <textarea name="body" rows="4" required placeholder="<?= $h($t('camp_body_ph')) ?>"></textarea>
    <small class="muted"><?= $h($t('camp_body_h')) ?></small></label>

  <label class="fld"><span><?= $h($t('camp_media')) ?></span>
    <input type="file" name="media" id="camp-media"
           accept="<?= $h('.' . implode(',.', array_merge(Media::IMAGE_EXT, Media::DOC_EXT))) ?>">
    <small class="muted"><?= $h($t('camp_media_h')) ?></small></label>

  <b class="small"><?= $h($t('camp_recipients')) ?></b>
  <div class="camp-pick">
    <div class="camp-search">
      <input type="search" id="camp-q" autocomplete="off" placeholder="<?= $h($t('camp_search_ph')) ?>">
      <div id="camp-hits" class="camp-hits" hidden></div>
    </div>
    <div id="camp-chips" class="camp-chips"></div>
    <p class="muted small" id="camp-none" style="margin:4px 0 10px"><?= $h($t('camp_pick_none')) ?></p>

    <div class="camp-groups">
      <?php foreach ($groups as $key => [$label, $n]): ?>
        <label class="camp-group">
          <input type="checkbox" name="groups[]" value="<?= $h($key) ?>" data-n="<?= (int)$n ?>">
          <span><?= $h($label) ?> <b><?= $h($fmt($n)) ?></b></span>
        </label>
      <?php endforeach; ?>
    </div>

    <details class="drawer" style="margin-top:10px">
      <summary class="muted small" style="cursor:pointer"><?= $h($t('camp_typed')) ?></summary>
      <label class="fld" style="margin-top:8px">
        <textarea name="recipients" id="camp-typed" rows="3" placeholder="<?= $h($t('camp_typed_ph')) ?>"></textarea>
        <small class="muted"><?= $h($t('camp_typed_h')) ?></small></label>
    </details>
  </div>

  <p class="camp-total" id="camp-total"></p>
  <button class="btn" id="camp-send"><?= svg('send') ?> <?= $h($t('camp_create')) ?></button>
</form>

<table><thead><tr>
  <th><?= $h($t('camp_name')) ?></th><th><?= $h($t('th_channel')) ?></th><th><?= $h($t('camp_media')) ?></th>
  <th><?= $h($t('th_total')) ?></th>
  <th><?= $h($t('th_sent')) ?></th><th><?= $h($t('th_failed')) ?></th><th><?= $h($t('th_status')) ?></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr><td><?= $h($r['name']) ?></td><td><?= $h(code_label($t, 'chan_', $r['channel'])) ?></td>
    <td class="small"><?php if (!empty($r['media_path'])): ?>
        <a href="<?= $h(Media::url((string)$r['media_path'])) ?>" target="_blank" rel="noopener">
          <?= $r['media_kind'] === 'document' ? '📎' : '🖼' ?> <?= $h($r['media_name'] ?: '') ?></a>
      <?php else: ?><span class="muted">—</span><?php endif; ?></td>
    <td><?= $h($r['total']) ?></td>
    <td><?= $h($r['sent']) ?></td><td><?= $h($r['failed']) ?></td><td><?= pill($h, $r['status'], $t) ?></td></tr>
<?php endforeach; ?>
</tbody></table>

<style>
/* .fld sets display:block, which beats the [hidden] attribute on its own */
#camp-form [hidden]{display:none!important}
.camp-pick{border:1px solid var(--line);border-radius:10px;padding:12px;margin:6px 0 14px}
.camp-search{position:relative}
.camp-search input{width:100%}
.camp-hits{position:absolute;z-index:40;left:0;right:0;top:100%;margin-top:4px;max-height:280px;overflow-y:auto;
  background:var(--surface);border:1px solid var(--line);border-radius:10px;box-shadow:0 10px 26px rgba(0,0,0,.35)}
.camp-hits button{display:block;width:100%;text-align:left;padding:9px 12px;border:0;background:transparent;
  color:inherit;font:inherit;cursor:pointer;border-bottom:1px solid var(--line)}
.camp-hits button:last-child{border-bottom:0}
.camp-hits button:hover{background:var(--surface2)}
.camp-hits .sub{display:block;font-size:11.5px;color:var(--muted);margin-top:2px}
.camp-hits .none{padding:9px 12px;font-size:12px;color:var(--muted)}
.camp-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.camp-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;
  background:var(--accent-soft);color:var(--accent);font-size:12.5px;font-weight:600}
.camp-chip.no-reach{background:var(--amber-bg);color:var(--amber)}
.camp-chip button{background:none;border:0;color:inherit;cursor:pointer;font-size:14px;line-height:1;padding:0}
.camp-groups{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.camp-group{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border:1px solid var(--line);
  border-radius:999px;cursor:pointer;font-size:13px}
.camp-group input{width:auto}
.camp-group b{color:var(--accent)}
.camp-total{font-weight:600;margin:10px 0}
@media (max-width:560px){.camp-group{width:100%}}
</style>
<script>
(function () {
  var form = document.getElementById('camp-form');
  if (!form) return;
  var L = <?= json_encode([
      'none'   => $t('camp_pick_none'),
      'total'  => $t('camp_total'),
      'nohits' => $t('camp_no_hits'),
      'nophone'=> $t('camp_no_phone'),
      'noemail'=> $t('camp_no_email'),
      'confirm'=> $t('camp_confirm'),
      'empty'  => $t('camp_err_no_recipients'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  var picked = {};   // contact id => {name, phone, email}
  var chips = document.getElementById('camp-chips'), totalEl = document.getElementById('camp-total'),
      noneEl = document.getElementById('camp-none'), chan = document.getElementById('camp-channel'),
      typed = document.getElementById('camp-typed');

  function isWa() { return chan.value !== 'email'; }
  function reachable(c) { return isWa() ? !!c.phone : !!c.email; }
  function esc(s) { return String(s == null ? '' : s); }

  function draw() {
    chips.innerHTML = '';
    var ids = Object.keys(picked);
    ids.forEach(function (id) {
      var c = picked[id];
      var chip = document.createElement('span');
      chip.className = 'camp-chip' + (reachable(c) ? '' : ' no-reach');
      chip.title = reachable(c) ? '' : (isWa() ? L.nophone : L.noemail);
      chip.appendChild(document.createTextNode(esc(c.name) + (reachable(c) ? '' : ' ⚠')));
      var x = document.createElement('button');
      x.type = 'button'; x.textContent = '×';
      x.addEventListener('click', function () { delete picked[id]; draw(); });
      chip.appendChild(x);
      var hidden = document.createElement('input');
      hidden.type = 'hidden'; hidden.name = 'contact_ids[]'; hidden.value = id;
      chip.appendChild(hidden);
      chips.appendChild(chip);
    });
    noneEl.hidden = ids.length > 0;
    count();
  }

  function count() {
    var n = 0, warn = 0;
    Object.keys(picked).forEach(function (id) { reachable(picked[id]) ? n++ : warn++; });
    form.querySelectorAll('input[name="groups[]"]:checked').forEach(function (g) { n += parseInt(g.dataset.n, 10) || 0; });
    (typed.value || '').split(/[\n,;]+/).forEach(function (l) { if (l.trim() !== '') n++; });
    totalEl.textContent = n > 0 ? L.total.replace('%d', n) : '';
    totalEl.style.color = n > 0 ? 'var(--txt)' : '';
    return n;
  }

  // ---- search the customer registry ----
  var q = document.getElementById('camp-q'), hits = document.getElementById('camp-hits'), timer = null;
  function closeHits() { hits.hidden = true; hits.innerHTML = ''; }
  q.addEventListener('input', function () {
    clearTimeout(timer);
    var v = q.value.trim();
    if (v.length < 2) { closeHits(); return; }
    timer = setTimeout(function () {
      fetch('?find=contacts&q=' + encodeURIComponent(v), {credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (list) {
          hits.innerHTML = '';
          if (!list.length) {
            var d = document.createElement('div'); d.className = 'none'; d.textContent = L.nohits;
            hits.appendChild(d); hits.hidden = false; return;
          }
          list.forEach(function (c) {
            var b = document.createElement('button'); b.type = 'button';
            var st = document.createElement('strong'); st.textContent = c.name; b.appendChild(st);
            var sp = document.createElement('span'); sp.className = 'sub';
            sp.textContent = c.label || '';
            if (!(isWa() ? c.phone : c.email)) { sp.textContent += (sp.textContent ? ' · ' : '') + (isWa() ? L.nophone : L.noemail); sp.style.color = 'var(--amber)'; }
            b.appendChild(sp);
            b.addEventListener('click', function () {
              picked[c.id] = {name: c.name, phone: c.phone, email: c.email};
              closeHits(); q.value = ''; draw(); q.focus();
            });
            hits.appendChild(b);
          });
          hits.hidden = false;
        }).catch(closeHits);
    }, 250);
  });
  q.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') e.preventDefault();      // never submit from the search box
    if (e.key === 'Escape') closeHits();
  });
  document.addEventListener('click', function (e) {
    if (e.target !== q && !hits.contains(e.target)) closeHits();
  });

  // The subject only belongs to email; the chips re-read as the channel changes.
  function channelChanged() {
    document.getElementById('camp-subject-fld').hidden = isWa();
    draw();
  }
  chan.addEventListener('change', channelChanged);
  form.addEventListener('change', count);
  typed.addEventListener('input', count);
  form.addEventListener('submit', function (e) {
    var n = count();
    if (n === 0) { e.preventDefault(); e.stopImmediatePropagation(); alert(L.empty); return; }
    if (!confirm(L.confirm.replace('%d', n))) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);
  channelChanged();
})();
</script>
