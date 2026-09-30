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
use Glue\Campaign\Sender;
use Glue\Config;
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
// The wait between one message and the next, as Impostazioni has it: the
// placeholder of the per-campaign field, and what a campaign uses when left blank.
$defThrottle = Sender::throttleFor();
// Below the gateway's own minimum gap nothing goes faster, so the estimate says so.
$minGap = max(0, (int)Config::get('textmebot.min_gap_seconds', 6));

// ?camp=<id> opens one campaign for editing, in place of the new-campaign form.
// What has already left cannot be taken back: editing reaches only the messages
// still queued, which the card says out loud.
$edit = null;
$pending = 0;
if (($editId = (int)($_GET['camp'] ?? 0)) > 0) {
    $st = $pdo->prepare('SELECT * FROM campaigns WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
    if ($edit) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'");
        $st->execute([$editId]);
        $pending = (int)$st->fetchColumn();
    }
}
?>
<h2><?= $h($t('camp_title')) ?></h2>
<div class="warn"><?= $h($t('camp_warn')) ?></div>

<?php if ($edit): $eid = (int)$edit['id']; $isMail = $edit['channel'] === 'email'; ?>
<div class="card">
  <div class="camp-edit-head">
    <h3 style="margin:0"><?= svg('pen') ?> <?= $h($edit['name']) ?></h3>
    <a class="btn ghost tiny" href="?tab=campaigns"><?= $h($t('camp_back')) ?></a>
  </div>
  <p class="muted small" style="margin:6px 0 14px">
    <?= $h($pending > 0
        ? sprintf($t('camp_edit_note'), (int)$edit['sent'], $pending)
        : sprintf($t('camp_edit_note_done'), (int)$edit['sent'])) ?>
  </p>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="do" value="campaign_edit">
    <input type="hidden" name="id" value="<?= $eid ?>">
    <div class="row">
      <label class="fld"><span><?= $h($t('camp_name')) ?></span>
        <input name="name" value="<?= $h($edit['name']) ?>"></label>
      <?php if ($isMail): ?>
        <label class="fld"><span><?= $h($t('camp_subject')) ?></span>
          <input name="subject" value="<?= $h((string)$edit['subject']) ?>"></label>
      <?php else: ?>
        <label class="fld" style="max-width:220px"><span><?= $h($t('camp_throttle')) ?></span>
          <input name="throttle_seconds" type="number" min="0" max="3600" inputmode="numeric"
                 value="<?= $edit['throttle_seconds'] === null ? '' : (int)$edit['throttle_seconds'] ?>"
                 placeholder="<?= (int)$defThrottle ?>">
          <small class="muted"><?= $h(sprintf($t('camp_throttle_h'), $defThrottle)) ?></small></label>
      <?php endif; ?>
    </div>
    <label class="fld"><span><?= $h($t('camp_body')) ?></span>
      <textarea name="body" rows="4" required><?= $h($edit['body']) ?></textarea>
      <small class="muted"><?= $h($t('camp_body_h')) ?></small></label>

    <label class="fld"><span><?= $h($t('camp_media')) ?></span>
      <input type="file" name="media"
             accept="<?= $h('.' . implode(',.', array_merge(Media::IMAGE_EXT, Media::DOC_EXT))) ?>">
      <?php if (!empty($edit['media_path'])): ?>
        <small class="muted"><?= $h($t('camp_media_now')) ?>
          <a href="<?= $h(Media::url((string)$edit['media_path'])) ?>" target="_blank" rel="noopener">
            <?= $edit['media_kind'] === 'document' ? '📎' : '🖼' ?> <?= $h($edit['media_name'] ?: '') ?></a>
          · <?= $h($t('camp_media_replace_h')) ?></small>
      <?php else: ?>
        <small class="muted"><?= $h($t('camp_media_h')) ?></small>
      <?php endif; ?></label>
    <?php if (!empty($edit['media_path'])): ?>
      <div style="margin:0 0 12px">
        <label class="camp-group">
          <input type="checkbox" name="remove_media" value="1">
          <span><?= $h($t('camp_media_remove')) ?></span></label>
      </div>
    <?php endif; ?>

    <button class="btn"><?= svg('check') ?> <?= $h($t('save')) ?></button>
  </form>

  <div class="camp-edit-acts">
    <?php if ($edit['status'] === 'running'): ?>
      <form method="post" class="inline">
        <input type="hidden" name="do" value="campaign_status"><input type="hidden" name="id" value="<?= $eid ?>">
        <input type="hidden" name="status" value="paused">
        <button class="btn ghost tiny"><?= $h($t('camp_pause')) ?></button></form>
    <?php elseif ($edit['status'] !== 'done' && $pending > 0): ?>
      <form method="post" class="inline">
        <input type="hidden" name="do" value="campaign_status"><input type="hidden" name="id" value="<?= $eid ?>">
        <input type="hidden" name="status" value="running">
        <button class="btn ghost tiny"><?= svg('send') ?> <?= $h($t('camp_resume')) ?></button></form>
    <?php endif; ?>
    <form method="post" class="inline"
          onsubmit="return confirm(<?= $h(json_encode(sprintf($t($pending > 0 ? 'camp_del_confirm_pending' : 'camp_del_confirm'),
                                                              $edit['name'], $pending), JSON_UNESCAPED_UNICODE)) ?>)">
      <input type="hidden" name="do" value="campaign_delete"><input type="hidden" name="id" value="<?= $eid ?>">
      <button class="btn danger tiny"><?= $h($t('camp_delete')) ?></button></form>
  </div>
</div>
<?php else: ?>
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

  <div class="row">
    <label class="fld"><span><?= $h($t('camp_media')) ?></span>
      <input type="file" name="media" id="camp-media"
             accept="<?= $h('.' . implode(',.', array_merge(Media::IMAGE_EXT, Media::DOC_EXT))) ?>">
      <small class="muted"><?= $h($t('camp_media_h')) ?></small></label>
    <?php // The pace this campaign sends at. Empty = the one in Impostazioni. ?>
    <label class="fld" id="camp-throttle-fld" style="max-width:220px"><span><?= $h($t('camp_throttle')) ?></span>
      <input name="throttle_seconds" id="camp-throttle" type="number" min="0" max="3600" inputmode="numeric"
             placeholder="<?= (int)$defThrottle ?>">
      <small class="muted"><?= $h(sprintf($t('camp_throttle_h'), $defThrottle)) ?></small></label>
  </div>

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
<?php endif; ?>

<table><thead><tr>
  <th><?= $h($t('camp_name')) ?></th><th><?= $h($t('th_channel')) ?></th><th><?= $h($t('camp_media')) ?></th>
  <th><?= $h($t('camp_throttle_col')) ?></th><th><?= $h($t('th_total')) ?></th>
  <th><?= $h($t('th_sent')) ?></th><th><?= $h($t('th_failed')) ?></th><th><?= $h($t('th_status')) ?></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <?php // The name is the way in: a button in the last column would sit off-screen
        // on a phone, where every table scrolls sideways. ?>
  <tr<?= $edit && (int)$edit['id'] === (int)$r['id'] ? ' class="camp-on"' : '' ?>>
    <td><a class="camp-open" href="?tab=campaigns&amp;camp=<?= (int)$r['id'] ?>" title="<?= $h($t('camp_edit_open')) ?>">
      <?= svg('pen') ?> <?= $h($r['name']) ?></a></td>
    <td><?= $h(code_label($t, 'chan_', $r['channel'])) ?></td>
    <td class="small"><?php if (!empty($r['media_path'])): ?>
        <a href="<?= $h(Media::url((string)$r['media_path'])) ?>" target="_blank" rel="noopener">
          <?= $r['media_kind'] === 'document' ? '📎' : '🖼' ?> <?= $h($r['media_name'] ?: '') ?></a>
      <?php else: ?><span class="muted">—</span><?php endif; ?></td>
    <td class="small"><?= $h($r['channel'] === 'email' ? '—' : sprintf($t('camp_throttle_v'), Sender::throttleFor($r))) ?></td>
    <td><?= $h($r['total']) ?></td>
    <td><?= $h($r['sent']) ?></td><td><?= $h($r['failed']) ?></td><td><?= pill($h, $r['status'], $t) ?></td></tr>
<?php endforeach; ?>
</tbody></table>

<style>
/* .fld sets display:block, which beats the [hidden] attribute on its own */
#camp-form [hidden]{display:none!important}
.camp-edit-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.camp-edit-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid var(--line)}
.camp-on td{background:var(--surface2)}
.camp-on td:first-child{box-shadow:inset 3px 0 0 var(--accent)}
.camp-open svg{width:14px;height:14px;vertical-align:-2px;opacity:.5}
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
      'eta'    => $t('camp_eta'), 'h' => $t('unit_h'), 'min' => $t('unit_min'), 'sec' => $t('unit_s'),
      'gap'    => $minGap,
  ], JSON_UNESCAPED_UNICODE) ?>;
  var picked = {};   // contact id => {name, phone, email}
  var chips = document.getElementById('camp-chips'), totalEl = document.getElementById('camp-total'),
      noneEl = document.getElementById('camp-none'), chan = document.getElementById('camp-channel'),
      typed = document.getElementById('camp-typed'),
      thr = document.getElementById('camp-throttle');

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

  function human(sec) {
    var out = [], hr = Math.floor(sec / 3600), mi = Math.floor(sec % 3600 / 60);
    if (hr) out.push(hr + ' ' + L.h);
    if (mi) out.push(mi + ' ' + L.min);
    if (!out.length) out.push(Math.max(1, Math.round(sec)) + ' ' + L.sec);
    return out.join(' ');
  }

  function count() {
    var n = 0, warn = 0;
    Object.keys(picked).forEach(function (id) { reachable(picked[id]) ? n++ : warn++; });
    form.querySelectorAll('input[name="groups[]"]:checked').forEach(function (g) { n += parseInt(g.dataset.n, 10) || 0; });
    (typed.value || '').split(/[\n,;]+/).forEach(function (l) { if (l.trim() !== '') n++; });
    var txt = n > 0 ? L.total.replace('%d', n) : '';
    // At this pace the list takes this long — the reason the pace is worth setting.
    var gap = thr.value.trim() === '' ? parseInt(thr.placeholder, 10) : parseInt(thr.value, 10);
    if (gap > 0) { gap = Math.max(gap, L.gap); }   // the gateway never goes faster than its own gap
    if (txt && isWa() && gap > 0 && n > 1) txt += ' · ' + L.eta.replace('%s', human((n - 1) * gap));
    totalEl.textContent = txt;
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
    document.getElementById('camp-throttle-fld').hidden = !isWa();   // email has no such limit
    draw();
  }
  chan.addEventListener('change', channelChanged);
  form.addEventListener('change', count);
  typed.addEventListener('input', count);
  thr.addEventListener('input', count);
  form.addEventListener('submit', function (e) {
    var n = count();
    if (n === 0) { e.preventDefault(); e.stopImmediatePropagation(); alert(L.empty); return; }
    if (!confirm(L.confirm.replace('%d', n))) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);
  channelChanged();
})();
</script>
