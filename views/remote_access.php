<?php
/**
 * Remote access — the dst-nat rules that publish one shop device's web page on
 * its customer's router VPN address, so a technician on the VPN can open it
 * from the panel instead of building the rule in Winbox by hand.
 *
 * Picking customer + device + port here writes
 *   chain=dstnat protocol=tcp in-interface=WIREGUARD dst-port=<port>
 *   action=dst-nat to-addresses=<device ip> to-ports=80
 * on that router, and the Devices tab grows an Open link for the device.
 *
 * In scope: $t, $h, $pdo. Admin-only (gated in dashboard.php) — these rules
 * change the customer's router configuration.
 */

use Glue\Devices\Forwards;

$rows = Forwards::all();

// Only routers with a reachable address, and only devices that have one — a
// forward needs both ends to exist.
$areas = $pdo->query("SELECT id, name, host FROM network_areas ORDER BY sort_order, id")->fetchAll();
$devices = $pdo->query(
    "SELECT id, name, ip, area_id FROM devices WHERE area_id IS NOT NULL AND active = 1 ORDER BY sort_order, id"
)->fetchAll();

// Devices grouped per customer for the dependent picker, plus the ports already
// spoken for on each router so the form can suggest the next free one.
$devByArea = [];
foreach ($devices as $d) {
    $devByArea[(int)$d['area_id']][] = ['id' => (int)$d['id'], 'name' => $d['name'], 'ip' => $d['ip']];
}
$hostByArea = [];
foreach ($areas as $a) {
    $hostByArea[(int)$a['id']] = $a['host'];
}

$statusPill = static function (string $s) use ($t): array {
    return match ($s) {
        'active' => ['ok',   $t('ra_status_active')],
        'error'  => ['down', $t('ra_status_error')],
        default  => ['unk',  $t('ra_status_pending')],
    };
};
?>
<div class="dev-head">
  <h2><?= $h($t('ra_title')) ?></h2>
  <button class="btn primary" onclick="raOpen()"><?= $h($t('ra_add')) ?></button>
</div>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('ra_sub')) ?></p>

<table id="raTable"><thead><tr>
  <th><?= $h($t('ra_th_customer')) ?></th><th><?= $h($t('ra_th_device')) ?></th>
  <th><?= $h($t('ra_th_rule')) ?></th><th><?= $h($t('ra_th_link')) ?></th>
  <th><?= $h($t('ra_th_status')) ?></th><th></th>
</tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="muted"><?= $h($t('ra_none')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r):
    [$cls, $label] = $statusPill((string)$r['status']);
    $iface = trim((string)($r['vpn_interface'] ?? '')) ?: Forwards::DEFAULT_INTERFACE;
?>
  <tr>
    <td><strong><?= $h($r['area_name']) ?></strong><br><span class="mono small muted"><?= $h($r['area_host']) ?></span></td>
    <td><?= $h($r['device_name']) ?><br><span class="mono small muted"><?= $h($r['device_ip']) ?></span></td>
    <td class="mono small muted"><?= $h($iface) ?> :<?= (int)$r['dst_port'] ?> &rarr; <?= $h($r['device_ip']) ?>:<?= (int)$r['to_port'] ?></td>
    <td><a class="ra-link mono small" href="<?= $h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $h($r['url']) ?></a></td>
    <td>
      <span class="dev-pill dev-<?= $cls ?>"><?= $h($label) ?></span>
      <?php if ($r['status'] === 'error' && $r['last_error'] !== ''): ?>
        <div class="small ra-err" title="<?= $h($r['last_error']) ?>"><?= $h($r['last_error']) ?></div>
      <?php endif; ?>
    </td>
    <td class="na-actions">
      <a class="btn ghost tiny" href="<?= $h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $h($t('ra_open')) ?></a>
      <button class="btn ghost tiny" onclick='raEdit(<?= json_encode([
          "id" => (int)$r["id"], "area_id" => (int)$r["area_id"], "device_id" => (int)$r["device_id"],
          "dst_port" => (int)$r["dst_port"], "to_port" => (int)$r["to_port"], "url_path" => $r["url_path"],
      ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><?= $h($t('ra_edit')) ?></button>
      <button class="btn ghost tiny" onclick="raApply(<?= (int)$r['id'] ?>, this)"><?= $h($t('ra_reapply')) ?></button>
      <span class="ra-out" data-for="<?= (int)$r['id'] ?>"></span>
      <button class="btn ghost tiny danger" onclick="raDelete(<?= (int)$r['id'] ?>)"><?= $h($t('ra_delete')) ?></button>
    </td>
  </tr>
<?php endforeach; ?>
</tbody></table>

<!-- modal -->
<div class="na-modal-bg" id="raModalBg" onclick="if(event.target===this)raClose()">
  <div class="na-modal">
    <h3 id="raModalTitle"><?= $h($t('ra_add')) ?></h3>
    <input type="hidden" id="ra_id">
    <label class="fld"><span><?= $h($t('ra_customer')) ?></span>
      <select id="ra_area" onchange="raFillDevices()">
        <option value=""><?= $h($t('ra_pick_customer')) ?></option>
        <?php foreach ($areas as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $h($a['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="fld"><span><?= $h($t('ra_device')) ?></span>
      <select id="ra_device" onchange="raDeviceChanged()"><option value=""><?= $h($t('ra_pick_customer')) ?></option></select>
    </label>
    <div class="na-row">
      <label class="fld"><span><?= $h($t('ra_port')) ?></span><input id="ra_port" type="number" min="1" max="65535" oninput="raPreview()"></label>
      <label class="fld"><span><?= $h($t('ra_to_port')) ?></span><input id="ra_to_port" type="number" min="1" max="65535" value="80" oninput="raPreview()"></label>
    </div>
    <p class="muted small" style="margin:-6px 0 10px"><?= $h($t('ra_port_hint')) ?></p>
    <label class="fld"><span><?= $h($t('ra_path')) ?></span><input id="ra_path" placeholder="<?= $h(Forwards::CASHMATIC_PATH) ?>" oninput="raPreview()"></label>
    <p class="muted small" style="margin:-6px 0 10px"><?= $h($t('ra_path_hint')) ?></p>
    <div class="ra-preview">
      <div class="muted small"><?= $h($t('ra_preview')) ?></div>
      <code id="ra_rule">—</code>
      <code id="ra_url">—</code>
    </div>
    <div class="na-modal-foot">
      <button class="btn ghost" onclick="raClose()"><?= $h($t('cancel')) ?></button>
      <button class="btn primary" onclick="raSave(this)"><?= $h($t('save')) ?></button>
    </div>
  </div>
</div>

<style>
.dev-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.dev-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-weight:600;font-size:12px;}
.dev-pill::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor;}
.dev-ok{background:var(--green-bg,rgba(63,184,104,.13));color:var(--green,#3fb868);}
.dev-down{background:var(--red-bg,rgba(229,97,110,.13));color:var(--red,#e5616e);}
.dev-unk{background:var(--amber-bg,rgba(217,164,10,.13));color:var(--amber,#d9a40a);}
#raTable .mono,.ra-preview code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}
.ra-link{color:var(--accent,#5b6cff);}
.ra-link:hover{text-decoration:underline;}
.ra-err{color:var(--red,#e5616e);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:4px;}
.ra-out{font-size:12px;}
.na-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.btn.tiny{padding:4px 9px;font-size:12px;} .btn.danger{color:var(--red,#e5616e);}
.ra-preview{background:var(--surface2,#1c2533);border:1px solid var(--line,#28303f);border-radius:8px;padding:10px 12px;margin:2px 0 12px;}
.ra-preview code{display:block;font-size:12px;margin-top:5px;word-break:break-all;color:var(--txt,#e7ecf4);}
.na-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:60;align-items:center;justify-content:center;}
.na-modal-bg.show{display:flex;}
.na-modal{background:var(--surface,#161c28);border:1px solid var(--line,#28303f);border-radius:12px;padding:22px;width:min(560px,92vw);max-height:92vh;overflow-y:auto;}
.na-modal h3{margin:0 0 14px;}
.na-modal .fld{display:block;margin-bottom:11px;}
.na-modal .fld span{display:block;font-size:12px;color:var(--muted,#8b95a7);margin-bottom:4px;}
.na-modal .fld input[type=text],.na-modal .fld input[type=number],.na-modal .fld input:not([type]),.na-modal .fld select{width:100%;padding:8px 10px;border-radius:8px;border:1px solid var(--line,#28303f);background:var(--surface2,#1c2533);color:var(--txt,#e7ecf4);}
.na-row{display:flex;gap:10px;}.na-row .fld{flex:1;}
.na-modal-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:8px;}
</style>

<script>
var RA = {
  devices:  <?= json_encode($devByArea, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
  hosts:    <?= json_encode($hostByArea, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
  used:     <?= json_encode(Forwards::usedPorts()) ?>,
  reserved: <?= json_encode(Forwards::reservedPorts()) ?>,
  portMin:  <?= (int)Forwards::PORT_MIN ?>,
  cashPath: <?= json_encode(Forwards::CASHMATIC_PATH) ?>,
  iface:    <?= json_encode(Forwards::DEFAULT_INTERFACE) ?>,
  add:      <?= json_encode($t('ra_add')) ?>,
  edit:     <?= json_encode($t('ra_edit_title')) ?>,
  pick:     <?= json_encode($t('ra_pick_customer')) ?>,
  noDev:    <?= json_encode($t('ra_no_devices')) ?>,
  delConfirm: <?= json_encode($t('ra_delete_confirm')) ?>,
  delForce:   <?= json_encode($t('ra_delete_force')) ?>,
  applied:    <?= json_encode($t('ra_applied')) ?>,
  errs: {
    device_not_found:    <?= json_encode($t('ra_err_device')) ?>,
    device_has_no_router:<?= json_encode($t('ra_err_no_router')) ?>,
    bad_port:            <?= json_encode($t('ra_err_port')) ?>,
    bad_to_port:         <?= json_encode($t('ra_err_port')) ?>,
    reserved_port:       <?= json_encode($t('ra_err_reserved')) ?>,
    port_taken:          <?= json_encode($t('ra_err_port_taken')) ?>,
    port_busy_on_router: <?= json_encode($t('ra_err_busy')) ?>,
    bad_path:            <?= json_encode($t('ra_err_path')) ?>
  }
};
function raEl(id){ return document.getElementById(id); }

// A router error arrives verbatim from RouterOS (or as router_unreachable:…) —
// show the translation when we have one, else the router's own words.
function raMsg(code){
  if(!code){ return 'error'; }
  if(RA.errs[code]){ return RA.errs[code]; }
  if(code.indexOf('old_router_unreachable') === 0){ return <?= json_encode($t('ra_err_old_router')) ?> + '\n' + code; }
  if(code.indexOf('router_unreachable') === 0){ return <?= json_encode($t('ra_err_unreachable')) ?> + '\n' + code; }
  return code;
}

function raFillDevices(keepDevice){
  var area = raEl('ra_area').value;
  var sel = raEl('ra_device');
  sel.innerHTML = '';
  var list = (area && RA.devices[area]) ? RA.devices[area] : [];
  if(!area){ sel.innerHTML = '<option value="">' + RA.pick + '</option>'; }
  else if(!list.length){ sel.innerHTML = '<option value="">' + RA.noDev + '</option>'; }
  list.forEach(function(d){
    var o = document.createElement('option');
    o.value = d.id; o.textContent = d.name + ' — ' + d.ip; o.setAttribute('data-ip', d.ip);
    sel.appendChild(o);
  });
  if(keepDevice){ sel.value = String(keepDevice); }
  if(!raEl('ra_port').value){ raEl('ra_port').value = raSuggestPort(area); }
  raPreview();
}

// Lowest port from 81 up that is neither a router service nor already taken on
// this router. Editable — the technician can still type their own.
function raSuggestPort(area){
  var used = (area && RA.used[area]) ? RA.used[area] : [];
  for(var p = RA.portMin; p <= 65535; p++){
    if(RA.reserved.indexOf(p) === -1 && used.indexOf(p) === -1){ return p; }
  }
  return RA.portMin;
}

// Cashmatic change machines answer on 80 but only open at /cws/loginform.php.
function raDeviceChanged(){
  var sel = raEl('ra_device');
  var name = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
  if(!raEl('ra_path').value && /cashmatic/i.test(name)){ raEl('ra_path').value = RA.cashPath; }
  raPreview();
}

function raPreview(){
  var sel = raEl('ra_device');
  var opt = sel.options[sel.selectedIndex];
  var ip = opt ? (opt.getAttribute('data-ip') || '') : '';
  var host = RA.hosts[raEl('ra_area').value] || '';
  var port = raEl('ra_port').value || '?';
  var toPort = raEl('ra_to_port').value || '80';
  var path = raEl('ra_path').value || '';
  raEl('ra_rule').textContent = ip
    ? 'chain=dstnat protocol=tcp in-interface=' + RA.iface + ' dst-port=' + port +
      ' action=dst-nat to-addresses=' + ip + ' to-ports=' + toPort
    : '—';
  raEl('ra_url').textContent = host ? ('http://' + host + ':' + port + path) : '—';
}

function raOpen(area, device){
  raEl('raModalTitle').textContent = RA.add;
  raEl('ra_id').value = ''; raEl('ra_port').value = ''; raEl('ra_to_port').value = 80; raEl('ra_path').value = '';
  raEl('ra_area').value = area ? String(area) : '';
  raFillDevices(device);
  raEl('raModalBg').classList.add('show');
}
function raEdit(f){
  raEl('raModalTitle').textContent = RA.edit;
  raEl('ra_id').value = f.id; raEl('ra_area').value = String(f.area_id);
  raEl('ra_port').value = f.dst_port; raEl('ra_to_port').value = f.to_port; raEl('ra_path').value = f.url_path || '';
  raFillDevices(f.device_id);
  raEl('raModalBg').classList.add('show');
}
function raClose(){ raEl('raModalBg').classList.remove('show'); }

function raPost(body){
  return fetch('device-api.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)})
    .then(function(r){ return r.json(); });
}

function raSave(btn){
  var body = { action:'save_forward', id:parseInt(raEl('ra_id').value || '0', 10),
    device_id:parseInt(raEl('ra_device').value || '0', 10),
    dst_port:parseInt(raEl('ra_port').value || '0', 10),
    to_port:parseInt(raEl('ra_to_port').value || '80', 10),
    url_path:raEl('ra_path').value.trim() };
  if(!body.device_id || !body.dst_port){ alert(RA.errs.bad_port); return; }
  btn.disabled = true;
  raPost(body)
    .then(function(j){
      // A saved-but-unpushed rule is still a real row: reload so it shows up in
      // its error state with a Re-apply button, and say why it did not go live.
      if(j && !j.ok){ alert(raMsg(j.error)); }
      location.reload();
    })
    .catch(function(){ btn.disabled = false; });
}

function raApply(id, btn){
  var out = document.querySelector('.ra-out[data-for="' + id + '"]');
  if(out){ out.textContent = '…'; out.style.color = ''; }
  btn.disabled = true;
  raPost({action:'apply_forward', id:id})
    .then(function(j){
      if(out){
        if(j && j.ok){ out.style.color = 'var(--green,#3fb868)'; out.textContent = RA.applied; setTimeout(function(){ location.reload(); }, 600); }
        else { out.style.color = 'var(--red,#e5616e)'; out.textContent = raMsg(j && j.error); }
      }
    })
    .catch(function(e){ if(out){ out.style.color = 'var(--red,#e5616e)'; out.textContent = e.message; } })
    .finally(function(){ btn.disabled = false; });
}

function raDelete(id){
  if(!confirm(RA.delConfirm)){ return; }
  raPost({action:'delete_forward', id:id})
    .then(function(j){
      if(j && j.ok){ location.reload(); return; }
      // Router unreachable: offer to drop our record and leave the rule behind.
      if(confirm(raMsg(j && j.error) + '\n\n' + RA.delForce)){
        raPost({action:'delete_forward', id:id, force:1}).then(function(){ location.reload(); });
      }
    });
}

// Deep link from the Devices tab: ?tab=remote_access&device=<id> opens the form
// with that customer and device already chosen.
(function(){
  var p = new URLSearchParams(location.search);
  var dev = parseInt(p.get('device') || '0', 10);
  if(!dev){ return; }
  for(var areaId in RA.devices){
    if(RA.devices[areaId].some(function(d){ return d.id === dev; })){ raOpen(areaId, dev); return; }
  }
})();
</script>
