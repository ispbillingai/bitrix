<?php
/**
 * Devices — live up/down status of the shop devices + disconnection log.
 * Polled from the routers by bin/poll-devices.php; refreshed here via
 * public/device-api.php. In scope: $t, $h, $pdo. Visible to admin + tech.
 */
$rows = $pdo->query(
    "SELECT d.name, d.ip, d.status, d.latency_ms, d.last_seen_at, d.last_checked_at, d.area_id, a.name AS area_name
       FROM devices d LEFT JOIN network_areas a ON a.id = d.area_id
      ORDER BY d.sort_order, d.id"
)->fetchAll();

$log = $pdo->query(
    "SELECT e.created_at, e.event_type, e.latency_ms, d.name, d.ip, d.area_id
       FROM device_events e JOIN devices d ON d.id = e.device_id
      ORDER BY e.id DESC LIMIT 100"
)->fetchAll();

// Customers = network areas (each router is one customer site). Used by the filter.
$areas = $pdo->query("SELECT id, name FROM network_areas ORDER BY sort_order, id")->fetchAll();

$staleAfter = 180;
$ago = function (?string $ts) use ($t): string {
    if (!$ts) { return $t('dev_never'); }
    $s = time() - strtotime($ts);
    if ($s < 0) { $s = 0; }
    if ($s < 60) { return $s . 's'; }
    if ($s < 3600) { return floor($s / 60) . 'm'; }
    if ($s < 86400) { return floor($s / 3600) . 'h'; }
    return floor($s / 86400) . 'd';
};
?>
<div class="dev-head">
  <h2><?= $h($t('dev_title')) ?></h2>
  <div class="dev-head-actions">
    <?php if (count($areas) > 1): ?>
    <label class="dev-filter">
      <span class="muted small"><?= $h($t('dev_filter_customer')) ?></span>
      <select id="devAreaFilter">
        <option value=""><?= $h($t('dev_filter_all')) ?></option>
        <?php foreach ($areas as $a): ?>
          <option value="<?= (int)$a['id'] ?>"><?= $h($a['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <button class="btn primary" id="devCheckNow"><?= $h($t('dev_check_now')) ?></button>
  </div>
</div>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('dev_sub')) ?></p>

<table id="devTable"><thead><tr>
  <th><?= $h($t('dev_th_device')) ?></th><th><?= $h($t('dev_th_ip')) ?></th>
  <th><?= $h($t('dev_th_area')) ?></th><th><?= $h($t('dev_th_status')) ?></th>
  <th><?= $h($t('dev_th_latency')) ?></th><th><?= $h($t('dev_th_seen')) ?></th>
  <th><?= $h($t('dev_th_checked')) ?></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r):
    $stale = !$r['last_checked_at'] || (time() - strtotime($r['last_checked_at']) > $staleAfter);
    $st = $stale ? 'unknown' : $r['status'];
    [$cls, $label] = $st === 'up' ? ['ok', $t('dev_up')] : ($st === 'down' ? ['down', $t('dev_down')] : ['unk', $t('dev_unknown')]);
?>
  <tr data-ip="<?= $h($r['ip']) ?>" data-area="<?= (int)($r['area_id'] ?? 0) ?>">
    <td><strong><?= $h($r['name']) ?></strong></td>
    <td class="mono small"><?= $h($r['ip']) ?></td>
    <td class="small muted"><?= $h($r['area_name'] ?? '—') ?></td>
    <td class="cell-status"><span class="dev-pill dev-<?= $cls ?>"><?= $h($label) ?></span></td>
    <td class="cell-latency small"><?= ($st === 'up' && $r['latency_ms'] !== null) ? $h(number_format((float)$r['latency_ms'], 1)) . ' ms' : '<span class="muted">—</span>' ?></td>
    <td class="cell-seen small muted"><?= $h($ago($r['last_seen_at'])) ?></td>
    <td class="cell-checked small muted"><?= $h($ago($r['last_checked_at'])) ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table>

<h3 style="margin-top:26px"><?= $h($t('dev_log_title')) ?></h3>
<p class="muted small" style="margin:-4px 0 12px"><?= $h($t('dev_log_sub')) ?></p>
<table id="devLog"><thead><tr>
  <th><?= $h($t('dev_th_time')) ?></th><th><?= $h($t('dev_th_device')) ?></th><th><?= $h($t('dev_th_event')) ?></th>
</tr></thead><tbody>
<?php if (!$log): ?><tr><td colspan="3" class="muted"><?= $h($t('dev_log_empty')) ?></td></tr><?php endif; ?>
<?php foreach ($log as $e): ?>
  <tr data-area="<?= (int)($e['area_id'] ?? 0) ?>">
    <td class="small"><?= $h($e['created_at']) ?></td>
    <td><?= $h($e['name']) ?> <span class="mono small muted"><?= $h($e['ip']) ?></span></td>
    <td><span class="dev-pill dev-<?= $e['event_type'] === 'up' ? 'ok' : 'down' ?>"><?= $h($e['event_type'] === 'up' ? $t('dev_evt_up') : $t('dev_evt_down')) ?></span>
      <?php if ($e['event_type'] === 'up' && $e['latency_ms'] !== null): ?><span class="small muted"><?= $h(number_format((float)$e['latency_ms'], 1)) ?> ms</span><?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
</tbody></table>

<style>
.dev-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.dev-head-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.dev-filter{display:flex;align-items:center;gap:7px;}
.dev-filter select{padding:6px 10px;border-radius:8px;border:1px solid var(--line,#28303f);background:var(--surface2,#1c2533);color:var(--txt,#e7ecf4);font-size:13px;}
.dev-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-weight:600;font-size:12px;}
.dev-pill::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor;}
.dev-ok{background:var(--green-bg,rgba(63,184,104,.13));color:var(--green,#3fb868);}
.dev-down{background:var(--red-bg,rgba(229,97,110,.13));color:var(--red,#e5616e);}
.dev-unk{background:var(--amber-bg,rgba(217,164,10,.13));color:var(--amber,#d9a40a);}
#devTable .mono,#devLog .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}
</style>

<script>
(function () {
  var STALE = <?= (int)$staleAfter ?>;
  var L = { up:<?= json_encode($t('dev_up')) ?>, down:<?= json_encode($t('dev_down')) ?>,
            unknown:<?= json_encode($t('dev_unknown')) ?>, never:<?= json_encode($t('dev_never')) ?>,
            checking:<?= json_encode($t('dev_checking')) ?> };
  function ago(ts){ if(!ts){return L.never;} var s=Math.floor((Date.now()-new Date(ts.replace(' ','T')).getTime())/1000);
    if(s<0){s=0;} if(s<60){return s+'s';} if(s<3600){return Math.floor(s/60)+'m';} if(s<86400){return Math.floor(s/3600)+'h';} return Math.floor(s/86400)+'d'; }
  function paint(d){
    var row=document.querySelector('#devTable tr[data-ip="'+d.ip+'"]'); if(!row){return;}
    var stale=!d.last_checked_at||(Date.now()-new Date(d.last_checked_at.replace(' ','T')).getTime()>STALE*1000);
    var st=stale?'unknown':d.status;
    var map={up:['ok',L.up],down:['down',L.down],unknown:['unk',L.unknown]};
    var m=map[st]||map.unknown;
    row.querySelector('.cell-status').innerHTML='<span class="dev-pill dev-'+m[0]+'">'+m[1]+'</span>';
    row.querySelector('.cell-latency').innerHTML=(st==='up'&&d.latency_ms!==null)?(parseFloat(d.latency_ms).toFixed(1)+' ms'):'<span class="muted">—</span>';
    row.querySelector('.cell-seen').textContent=ago(d.last_seen_at);
    row.querySelector('.cell-checked').textContent=ago(d.last_checked_at);
  }
  function refresh(){ fetch('device-api.php?what=status',{headers:{'Accept':'application/json'}})
    .then(function(r){return r.ok?r.json():null;})
    .then(function(j){ if(j&&j.ok&&j.devices){ j.devices.forEach(paint); } }).catch(function(){}); }
  var btn=document.getElementById('devCheckNow');
  if(btn){ btn.addEventListener('click',function(){
    btn.disabled=true; var o=btn.textContent; btn.textContent=L.checking;
    fetch('device-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'poll'})})
      .then(function(r){return r.json();})
      .then(function(j){ if(j&&j.devices){ j.devices.forEach(paint); } })
      .catch(function(){})
      .finally(function(){ btn.disabled=false; btn.textContent=o; setTimeout(function(){location.reload();},400); });
  }); }
  setInterval(refresh, 10000);

  // ---- customer/router filter ----
  var sel = document.getElementById('devAreaFilter');
  if (sel) {
    var KEY = 'devAreaFilter';
    function applyFilter(area) {
      area = String(area || '');
      // Device rows
      var devRows = document.querySelectorAll('#devTable tbody tr[data-area]');
      var shownDev = 0;
      devRows.forEach(function (row) {
        var match = !area || row.getAttribute('data-area') === area;
        row.style.display = match ? '' : 'none';
        if (match) { shownDev++; }
      });
      toggleEmpty('devTable', shownDev, 7);
      // Log rows
      var logRows = document.querySelectorAll('#devLog tbody tr[data-area]');
      var shownLog = 0;
      logRows.forEach(function (row) {
        var match = !area || row.getAttribute('data-area') === area;
        row.style.display = match ? '' : 'none';
        if (match) { shownLog++; }
      });
      toggleEmpty('devLog', shownLog, 3);
    }
    // Insert/remove a "no rows for this customer" placeholder row.
    function toggleEmpty(tableId, shown, cols) {
      var tbody = document.querySelector('#' + tableId + ' tbody');
      if (!tbody) { return; }
      var ph = tbody.querySelector('.dev-empty-filter');
      if (shown === 0 && !ph) {
        var tr = document.createElement('tr');
        tr.className = 'dev-empty-filter';
        tr.innerHTML = '<td colspan="' + cols + '" class="muted">' + <?= json_encode($t('dev_filter_none')) ?> + '</td>';
        tbody.appendChild(tr);
      } else if (shown > 0 && ph) {
        ph.remove();
      }
    }
    // Initial value: ?area= param wins, else last saved choice.
    var params = new URLSearchParams(location.search);
    var initial = params.get('area') || localStorage.getItem(KEY) || '';
    if (initial && sel.querySelector('option[value="' + initial + '"]')) {
      sel.value = initial;
    }
    applyFilter(sel.value);
    sel.addEventListener('change', function () {
      localStorage.setItem(KEY, sel.value);
      applyFilter(sel.value);
    });
  }
})();
</script>
