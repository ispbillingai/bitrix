<?php
/**
 * Network areas — the MikroTik routers the panel polls. Admin can add/edit/
 * delete routers and test the connection. Devices are pinged through the router
 * of their area. In scope: $t, $h, $pdo. Admin-only (view is gated in dashboard).
 */
$areas = $pdo->query("SELECT * FROM network_areas ORDER BY sort_order, id")->fetchAll();
$counts = [];
foreach ($pdo->query("SELECT area_id, COUNT(*) c FROM devices WHERE area_id IS NOT NULL GROUP BY area_id") as $r) {
    $counts[(int)$r['area_id']] = (int)$r['c'];
}
?>
<div class="dev-head">
  <h2><?= $h($t('na_title')) ?></h2>
  <button class="btn primary" onclick="naOpen()"><?= $h($t('na_add')) ?></button>
</div>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('na_sub')) ?></p>

<table id="naTable"><thead><tr>
  <th><?= $h($t('na_name')) ?></th><th><?= $h($t('na_host')) ?></th><th><?= $h($t('na_port')) ?></th>
  <th><?= $h($t('na_devices')) ?></th><th><?= $h($t('na_active')) ?></th><th></th>
</tr></thead><tbody>
<?php if (!$areas): ?><tr><td colspan="6" class="muted"><?= $h($t('na_none')) ?></td></tr><?php endif; ?>
<?php foreach ($areas as $a): ?>
  <tr>
    <td><strong><?= $h($a['name']) ?></strong></td>
    <td class="mono small"><?= $h($a['host']) ?></td>
    <td class="small"><?= (int)$a['api_port'] ?></td>
    <td><span class="dev-pill dev-unk"><?= (int)($counts[(int)$a['id']] ?? 0) ?></span></td>
    <td><span class="dev-pill dev-<?= (int)$a['active'] === 1 ? 'ok' : 'unk' ?>"><?= $h((int)$a['active'] === 1 ? $t('na_yes') : $t('na_no')) ?></span></td>
    <td class="na-actions">
      <button class="btn ghost tiny" onclick='naEdit(<?= json_encode([
          "id" => (int)$a["id"], "name" => $a["name"], "host" => $a["host"],
          "api_port" => (int)$a["api_port"], "api_user" => $a["api_user"],
          "ping_count" => (int)$a["ping_count"], "active" => (int)$a["active"],
          "sort_order" => (int)$a["sort_order"], "alert_phone" => $a["alert_phone"] ?? "",
      ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><?= $h($t('na_edit')) ?></button>
      <button class="btn ghost tiny" onclick="naTest(<?= (int)$a['id'] ?>, this)"><?= $h($t('na_test')) ?></button>
      <span class="na-test-out" data-for="<?= (int)$a['id'] ?>"></span>
      <button class="btn ghost tiny danger" onclick="naDelete(<?= (int)$a['id'] ?>)"><?= $h($t('na_delete')) ?></button>
    </td>
  </tr>
<?php endforeach; ?>
</tbody></table>

<!-- modal -->
<div class="na-modal-bg" id="naModalBg" onclick="if(event.target===this)naClose()">
  <div class="na-modal">
    <h3 id="naModalTitle"><?= $h($t('na_add')) ?></h3>
    <input type="hidden" id="na_id">
    <label class="fld"><span><?= $h($t('na_name')) ?></span><input id="na_name" placeholder="<?= $h($t('na_name_ph')) ?>"></label>
    <div class="na-row">
      <label class="fld" style="flex:2"><span><?= $h($t('na_host')) ?></span><input id="na_host" placeholder="192.168.200.15"></label>
      <label class="fld"><span><?= $h($t('na_port')) ?></span><input id="na_port" type="number" value="8728"></label>
    </div>
    <div class="na-row">
      <label class="fld"><span><?= $h($t('na_user')) ?></span><input id="na_user" value="admin"></label>
      <label class="fld"><span><?= $h($t('na_pass')) ?></span><input id="na_pass" type="password" autocomplete="new-password"></label>
    </div>
    <div class="na-row">
      <label class="fld"><span><?= $h($t('na_pings')) ?></span><input id="na_count" type="number" value="2" min="1" max="10"></label>
      <label class="fld"><span><?= $h($t('na_sort')) ?></span><input id="na_sort" type="number" value="0"></label>
      <label class="fld chk"><span><?= $h($t('na_active')) ?></span><input id="na_active" type="checkbox" checked></label>
    </div>
    <label class="fld"><span><?= $h($t('na_alert_phone')) ?></span><input id="na_alert" placeholder="<?= $h($t('na_alert_phone_ph')) ?>"></label>
    <p class="muted small" style="margin:-6px 0 4px"><?= $h($t('na_alert_hint')) ?></p>
    <div class="na-modal-foot">
      <button class="btn ghost" onclick="naClose()"><?= $h($t('cancel')) ?></button>
      <button class="btn primary" onclick="naSave(this)"><?= $h($t('save')) ?></button>
    </div>
  </div>
</div>

<style>
.na-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.na-test-out{font-size:12px;}
.na-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:60;align-items:center;justify-content:center;}
.na-modal-bg.show{display:flex;}
.na-modal{background:var(--surface,#161c28);border:1px solid var(--line,#28303f);border-radius:12px;padding:22px;width:min(560px,92vw);}
.na-modal h3{margin:0 0 14px;}
.na-modal .fld{display:block;margin-bottom:11px;}
.na-modal .fld span{display:block;font-size:12px;color:var(--muted,#8b95a7);margin-bottom:4px;}
.na-modal .fld input[type=text],.na-modal .fld input[type=password],.na-modal .fld input[type=number],.na-modal .fld input:not([type]){width:100%;padding:8px 10px;border-radius:8px;border:1px solid var(--line,#28303f);background:var(--surface2,#1c2533);color:var(--txt,#e7ecf4);}
.na-row{display:flex;gap:10px;}.na-row .fld{flex:1;}
.na-modal .chk{display:flex;align-items:center;gap:8px;} .na-modal .chk span{margin:0;}
.na-modal-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:8px;}
.btn.tiny{padding:4px 9px;font-size:12px;} .btn.danger{color:var(--red,#e5616e);}
</style>

<script>
var NA = {
  add:<?= json_encode($t('na_add')) ?>, edit:<?= json_encode($t('na_edit')) ?>,
  ok:<?= json_encode($t('na_test_ok')) ?>, fail:<?= json_encode($t('na_test_fail')) ?>,
  passKeep:<?= json_encode($t('na_pass_keep')) ?>, passNew:<?= json_encode($t('na_pass_ph')) ?>,
  delConfirm:<?= json_encode($t('na_delete_confirm')) ?>, reqErr:<?= json_encode($t('na_req_err')) ?>,
  phoneErr:<?= json_encode($t('na_bad_phone')) ?>
};
function $(id){return document.getElementById(id);}
function naOpen(){ $('naModalTitle').textContent=NA.add; $('na_id').value=''; $('na_name').value=''; $('na_host').value='';
  $('na_port').value=8728; $('na_user').value='admin'; $('na_pass').value=''; $('na_pass').placeholder=NA.passNew;
  $('na_count').value=2; $('na_sort').value=0; $('na_active').checked=true; $('na_alert').value=''; $('naModalBg').classList.add('show'); }
function naEdit(a){ $('naModalTitle').textContent=NA.edit; $('na_id').value=a.id; $('na_name').value=a.name||'';
  $('na_host').value=a.host||''; $('na_port').value=a.api_port||8728; $('na_user').value=a.api_user||'admin';
  $('na_pass').value=''; $('na_pass').placeholder=NA.passKeep; $('na_count').value=a.ping_count||2;
  $('na_sort').value=a.sort_order||0; $('na_active').checked=(a.active==1); $('na_alert').value=a.alert_phone||''; $('naModalBg').classList.add('show'); }
function naClose(){ $('naModalBg').classList.remove('show'); }
function naSave(btn){
  var body={ action:'save_area', id:parseInt($('na_id').value||'0',10), name:$('na_name').value.trim(),
    host:$('na_host').value.trim(), api_port:parseInt($('na_port').value||'8728',10), api_user:$('na_user').value.trim(),
    api_pass:$('na_pass').value, ping_count:parseInt($('na_count').value||'2',10),
    sort_order:parseInt($('na_sort').value||'0',10), active:$('na_active').checked?1:0,
    alert_phone:$('na_alert').value.trim() };
  if(!body.name||!body.host){ alert(NA.reqErr); return; }
  btn.disabled=true;
  fetch('device-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
    .then(function(r){return r.json();})
    .then(function(j){ if(j&&j.ok){ location.reload(); }
      else { alert(j&&j.error==='bad_phone'?NA.phoneErr:((j&&j.error)||'error')); btn.disabled=false; } })
    .catch(function(){ btn.disabled=false; });
}
function naTest(id,btn){
  var out=document.querySelector('.na-test-out[data-for="'+id+'"]'); if(out){out.textContent='…';out.style.color='';}
  btn.disabled=true;
  fetch('device-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'test',area_id:id})})
    .then(function(r){return r.json();})
    .then(function(j){ if(out){ if(j&&j.ok){out.style.color='var(--green,#3fb868)';out.textContent=NA.ok;}
      else{out.style.color='var(--red,#e5616e)';out.textContent=NA.fail+': '+((j&&j.error)||'');} } })
    .catch(function(e){ if(out){out.style.color='var(--red,#e5616e)';out.textContent=e.message;} })
    .finally(function(){ btn.disabled=false; });
}
function naDelete(id){
  if(!confirm(NA.delConfirm)){return;}
  fetch('device-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_area',id:id})})
    .then(function(r){return r.json();}).then(function(j){ if(j&&j.ok){location.reload();} else {alert((j&&j.error)||'error');} });
}
</script>
