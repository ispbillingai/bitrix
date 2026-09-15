<?php
declare(strict_types=1);

/**
 * Shared dashboard chrome: the icon set and the stylesheet. Lives here rather
 * than inside dashboard.php because the PARTNER area (public/partner.php) is the
 * same application wearing a smaller nav — same shell, same cards, same tables,
 * same colours — and two copies of a design system drift apart within a month.
 *
 * Both pages require this before rendering. Nothing here touches the database or
 * the session: it is markup and CSS only.
 */

function svg(string $name): string {
    $p = [
        'overview'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'leads'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'deals'       => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M3 11v3"/><line x1="7" y1="10" x2="7" y2="15"/>',
        'pipeline'    => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="14" y2="12"/><line x1="4" y1="18" x2="9" y2="18"/>',
        'contacts'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/>',
        'customers'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20v-1a5.5 5.5 0 0 1 11 0v1"/><path d="M16 3.5a3.5 3.5 0 0 1 0 9"/><path d="M17.5 13.7A5.5 5.5 0 0 1 21.5 19v1"/>',
        'appointments'=> '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'tasks'       => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'agents'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'reminders'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'messages'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'chat'        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'campaigns'   => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'mega'        => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'events'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'instructions'=> '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'database'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>',
        'invoices'    => '<path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v6h6"/><path d="M12 11v7"/><path d="M14 12.5h-3a1.5 1.5 0 0 0 0 3h2a1.5 1.5 0 0 1 0 3H10"/>',
        'payments'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="6" y1="15" x2="10" y2="15"/>',
        'eye'         => '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>',
        'devices'     => '<rect x="4" y="3" width="16" height="12" rx="1"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="15" x2="12" y2="21"/>',
        'partners'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M12 12l2 2 4-4"/>',
        'network_areas' => '<rect x="9" y="2" width="6" height="6" rx="1"/><rect x="3" y="16" width="6" height="6" rx="1"/><rect x="15" y="16" width="6" height="6" rx="1"/><path d="M12 8v4M12 12H6v4M12 12h6v4"/>',
        'installations' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'support'     => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"/><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"/><line x1="14.83" y1="9.17" x2="19.07" y2="4.93"/><line x1="4.93" y1="19.07" x2="9.17" y2="14.83"/>',
        'team'        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><circle cx="9" cy="10" r="1"/><circle cx="13" cy="10" r="1"/><circle cx="17" cy="10" r="1"/>',
        'tickets'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="13" y2="13"/>',
        'link'        => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/>',
        'send'        => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'outbound'    => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'templates'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>',
        'money'       => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'alert'       => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'check'       => '<path d="M20 6 9 17l-5-5"/>',
        'documents'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 16c1.5-2.5 3-2.5 3-1s-1.5 1.5-1 3c2 0 3-1 4-2"/>',
        'sign'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 16c1.5-2.5 3-2.5 3-1s-1.5 1.5-1 3c2 0 3-1 4-2"/>',
        'trophy'      => '<path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0V4z"/><path d="M5 4H3v2a3 3 0 0 0 3 3M19 4h2v2a3 3 0 0 1-3 3"/>',
        'phone'       => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'pen'         => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'articles'    => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4"/><path d="M12 11v10"/>',
        'quotes'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="13" y2="13"/><line x1="8" y1="17" x2="15" y2="17"/><line x1="8" y1="9" x2="10" y2="9"/>',
        'commissions' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'my_commissions' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
    ];
    $body = $p[$name] ?? $p['overview'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

/**
 * One staff chat bubble, as HTML. Shared by the tickets view's initial render
 * and the live-poll endpoint, so a message appended by polling is byte-identical
 * to one drawn by a page load. Uses pill()/short_time()/svg() from the dashboard.
 */
function ticket_bubble(array $m, callable $t, callable $h): string {
    $mine = $m['sender_type'] !== 'customer';
    ob_start(); ?>
<div class="msg <?= $mine ? 'staff' : 'cust' ?>" data-mid="<?= (int)$m['id'] ?>">
  <?php if ((string)$m['body'] !== ''): ?><div class="msg-b"><?= nl2br($h($m['body'])) ?></div><?php endif; ?>
  <?php if (!empty($m['sign_document_id'])): ?>
    <div class="msg-b">✍️ <a href="?sdl=<?= (int)$m['sign_document_id'] ?>&k=orig"><?= $h($m['sign_title'] ?: $t('dc_h_doc')) ?></a></div>
    <div class="msg-rcpt<?= ($m['sign_status'] ?? '') === 'signed' ? ' ok' : '' ?>">
      <?= pill($h, (string)($m['sign_status'] ?? 'sent'), $t) ?>
      <?php if (($m['sign_status'] ?? '') === 'signed'): ?>
        ✅ <?= $h(short_time($m['sign_signed_at'])) ?>
        <?php if (!empty($m['sign_signed_path'])): ?> · <a href="?sdl=<?= (int)$m['sign_document_id'] ?>&k=signed"><?= $h($t('dc_dl_signed')) ?></a><?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($m['attachment_path'])): ?>
    <?php if (\Glue\Crm\Tickets::isAudio($m['attachment_name'])): ?>
      <div class="msg-b"><audio controls preload="metadata" src="?dl=<?= $h($m['id']) ?>" style="max-width:230px;height:40px"></audio></div>
    <?php else: ?>
      <div class="msg-b"><a href="?dl=<?= $h($m['id']) ?>">📎 <?= $h($m['attachment_name'] ?: $t('tk_attachment')) ?></a></div>
    <?php endif; ?>
    <?php if ($mine): ?>
      <div class="msg-rcpt<?= $m['downloaded_at'] ? ' ok' : '' ?>">
        <?= $m['downloaded_at']
            ? '📥 ' . $h($t('tk_downloaded')) . ' ' . $h(short_time($m['downloaded_at']))
            : '· ' . $h($t('tk_not_downloaded')) ?>
        <?php if (!empty($m['accepted_at'])): ?>
          <span class="msg-acc">✅ <?= $h($t('tk_accepted')) ?> <?= $h(short_time($m['accepted_at'])) ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
  <div class="msg-m"><?= $h($m['sender_name'] ?: ($mine ? $t('tk_staff') : $t('th_customer'))) ?> · <?= $h(short_time($m['created_at'])) ?></div>
</div>
<?php return (string)ob_get_clean();
}

/**
 * Voice-message recorder, shared by the staff reply bar and (via the same
 * markup contract) any chat form: a [data-mic] button records with the mic and
 * drops the clip into the form's <input type=file name=attachment>, so sending
 * works exactly like attaching a file. Buttons stay hidden until this confirms
 * the browser can record. Labels are passed in already translated.
 */
function chat_recorder_js(string $rec, string $stop, string $ready, string $deny): string {
    $enc  = static fn(string $s): string => (string)json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $jRec = $enc($rec); $jStop = $enc($stop); $jReady = $enc($ready); $jDeny = $enc($deny);
    return <<<JS
<script>
(function(){
  var L={rec:$jRec,stop:$jStop,ready:$jReady,deny:$jDeny};
  var canInline = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  // Phones get the NATIVE recorder (a capture file input): inline getUserMedia
  // is refused inside many mobile browsers and in-app webviews, but the OS
  // recorder always opens the mic. Desktop keeps the inline recorder that works.
  var coarse=false; try{coarse=window.matchMedia&&matchMedia('(pointer:coarse)').matches;}catch(e){}
  var useNative = !canInline || coarse;
  function extFor(m){m=m||'';if(m.indexOf('webm')>=0)return 'webm';if(m.indexOf('ogg')>=0)return 'ogg';
    if(m.indexOf('mp4')>=0||m.indexOf('m4a')>=0||m.indexOf('aac')>=0)return 'm4a';
    if(m.indexOf('mpeg')>=0)return 'mp3';if(m.indexOf('wav')>=0)return 'wav';return 'webm';}
  function drop(input,file,note){try{var dt=new DataTransfer();dt.items.add(file);input.files=dt.files;}catch(e){}
    if(note)note.textContent='🎤 '+L.ready;}
  document.querySelectorAll('[data-mic]').forEach(function(btn){
    var form=btn.closest('form');
    var input=form&&form.querySelector('input[type=file][name=attachment]');
    if(!input) return;
    var note=form.querySelector('.tk-fn')||document.getElementById('fn');
    if(useNative){
      // Native OS voice recorder via a hidden capture input.
      var cap=document.createElement('input');
      cap.type='file'; cap.accept='audio/*'; cap.setAttribute('capture','user');
      cap.style.display='none'; form.appendChild(cap);
      btn.hidden=false;
      btn.addEventListener('click',function(){cap.click();});
      cap.addEventListener('change',function(){
        if(cap.files&&cap.files.length){drop(input,cap.files[0],note);}
      });
      return;
    }
    btn.hidden=false;
    var rec=null,chunks=[],stream=null,t0=0,timer=null;
    function tidy(){if(timer){clearInterval(timer);timer=null;}if(stream){stream.getTracks().forEach(function(x){x.stop();});stream=null;}
      btn.classList.remove('rec');btn.textContent='🎤';}
    btn.addEventListener('click',function(){
      if(rec&&rec.state==='recording'){rec.stop();return;}
      navigator.mediaDevices.getUserMedia({audio:true}).then(function(s){
        stream=s;chunks=[];rec=new MediaRecorder(s);
        rec.ondataavailable=function(e){if(e.data&&e.data.size)chunks.push(e.data);};
        rec.onstop=function(){
          var mime=(rec&&rec.mimeType)||'audio/webm';var ext=extFor(mime);
          var file=new File([new Blob(chunks,{type:mime})],'audio-'+Date.now()+'.'+ext,{type:mime});
          drop(input,file,note);
          if(note)note.textContent='🎤 '+L.ready+' ('+Math.max(1,Math.round((Date.now()-t0)/1000))+'s)';
          tidy();
        };
        rec.start();t0=Date.now();btn.classList.add('rec');
        btn.textContent='⏹';btn.title=L.stop;
        timer=setInterval(function(){btn.textContent='⏹ '+Math.round((Date.now()-t0)/1000);},1000);
      }).catch(function(){if(note)note.textContent=L.deny;});
    });
  });
})();
</script>
JS;
}

/**
 * A phone field: country selector (Italy by default) + number box, as one
 * .fld label. The form posts NAME and NAME_cc; Glue\Crm\Phone::applyPosted()
 * joins them into the international number before any handler reads $_POST.
 * A stored number is split back into selector + box so an edit form shows
 * "+39" selected and the national part in the box.
 *
 * $a: required (bool), placeholder (string), hint (string, shown under the box).
 */
function phone_field(callable $h, string $label, string $name, ?string $value, string $lang = 'it', array $a = []): void {
    $split = \Glue\Crm\Phone::split($value);
    $ccLabel = $lang === 'it' ? 'Prefisso internazionale' : 'Country code'; ?>
<label class="fld"><span><?= $h($label) ?></span>
  <span class="phonewrap">
    <select name="<?= $h($name) ?>_cc" aria-label="<?= $h($ccLabel) ?>" title="<?= $h($ccLabel) ?>">
      <?php foreach (array_keys(\Glue\Crm\Phone::COUNTRIES) as $dial): $dial = (string)$dial; ?>
        <option value="<?= $h($dial) ?>"<?= $dial === $split['cc'] ? ' selected' : '' ?>><?= $h(\Glue\Crm\Phone::optionLabel($dial, $lang)) ?></option>
      <?php endforeach; ?>
    </select>
    <input name="<?= $h($name) ?>" type="tel" inputmode="tel" autocomplete="tel-national" value="<?= $h($split['number']) ?>"
      placeholder="<?= $h($a['placeholder'] ?? ($lang === 'it' ? 'es. 339 1234567' : 'e.g. 339 1234567')) ?>"<?= !empty($a['required']) ? ' required' : '' ?>>
  </span>
  <?php if (!empty($a['hint'])): ?><small class="muted"><?= $h($a['hint']) ?></small><?php endif; ?>
</label>
<?php }

function css(): void { ?>
<style>
:root{
  --bg:#0e131c;--surface:#161c28;--surface2:#1c2533;--line:#28303f;--line2:#39435a;
  --txt:#e7ecf4;--muted:#8b95a7;--accent:#5b6cff;--accent-soft:rgba(91,108,255,.14);
  --green:#3fb868;--green-bg:rgba(63,184,104,.13);--red:#e5616e;--red-bg:rgba(229,97,110,.13);
  --amber:#d9a40a;--amber-bg:rgba(217,164,10,.13);--violet:#7c5cff;--radius:12px;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',system-ui,sans-serif;color:var(--txt);font-size:14px;line-height:1.5;
  background:var(--bg);min-height:100vh;-webkit-font-smoothing:antialiased;}
.center{display:flex;align-items:center;justify-content:center;min-height:100vh;}
.muted{color:var(--muted);} .small{font-size:12px;} .big{font-size:30px;font-weight:700;letter-spacing:-.02em;}
a{color:inherit;text-decoration:none;}
.logo{width:40px;height:40px;border-radius:10px;background:var(--accent);display:flex;align-items:center;
  justify-content:center;font-weight:800;color:#fff;font-size:18px;flex:0 0 auto;}
.shell{display:flex;min-height:100vh;}
.sidebar{width:236px;background:var(--surface);border-right:1px solid var(--line);
  padding:18px 14px;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;flex:0 0 auto;}
.brand{display:flex;gap:11px;align-items:center;margin:4px 6px 22px;}
.brand strong{display:block;font-size:15px;} .brand span{display:block;line-height:1.3;margin-top:2px;}
nav{display:flex;flex-direction:column;gap:2px;overflow-y:auto;}
nav a{display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:8px;color:var(--muted);
  font-weight:500;transition:background .12s,color .12s;}
nav a svg{width:18px;height:18px;flex:0 0 auto;}
/* Icons in section headings and buttons ship with no width/height attribute;
   without an explicit size some browsers scale the viewBox to the container
   and render them screen-wide (seen on the customer page). */
h2 svg,h3 svg,summary svg{width:17px;height:17px;flex:0 0 auto;vertical-align:-3px;}
nav a:hover{background:var(--surface2);color:var(--txt);}
nav a.active{background:var(--accent);color:#fff;}
nav a .nav-n{margin-left:auto;min-width:19px;height:19px;padding:0 6px;border-radius:10px;background:var(--accent);color:#fff;font-size:11px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;}
nav a.active .nav-n{background:#fff;color:var(--accent);}
main{flex:1;display:flex;flex-direction:column;min-width:0;}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:13px 28px;
  border-bottom:1px solid var(--line);background:var(--surface);position:sticky;top:0;z-index:5;}
.crumb{font-weight:700;font-size:17px;}
.actions{display:flex;gap:12px;align-items:center;}
.actions .btn.tiny svg{width:14px;height:14px;}
.langsw{display:inline-flex;background:var(--surface2);border:1px solid var(--line);border-radius:8px;padding:2px;}
.langsw a{padding:4px 9px;border-radius:6px;color:var(--muted);font-weight:600;font-size:12px;}
.langsw a.on{background:var(--accent);color:#fff;}
.content{padding:24px 28px;width:100%;}
h2{font-size:21px;margin-bottom:18px;letter-spacing:-.01em;} h3{font-size:15px;margin:16px 0 12px;}
.lead{font-size:15px;color:var(--muted);margin-bottom:20px;line-height:1.65;max-width:820px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px;}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:20px;margin-bottom:16px;}
.tile{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:16px 18px;transition:border-color .12s;}
.tile:hover{border-color:var(--line2);}
.tile-top{display:flex;align-items:center;gap:9px;margin-bottom:10px;color:var(--muted);}
.tile-top svg{width:17px;height:17px;}
.tile .big{display:block;margin-top:6px;} .tile .sub{font-size:12px;color:var(--muted);margin-top:4px;}
.badge{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;border-radius:7px;font-size:12.5px;font-weight:600;}
.badge .dot{width:7px;height:7px;border-radius:50%;}
.badge.ok{background:var(--green-bg);color:var(--green);} .badge.ok .dot{background:var(--green);}
.badge.no{background:var(--red-bg);color:var(--red);} .badge.no .dot{background:var(--red);}
.cols{display:grid;gap:16px;margin-bottom:16px;}
.cols.c-2-1{grid-template-columns:2fr 1fr;} .cols.c-1-1{grid-template-columns:1fr 1fr;}
@media(max-width:1100px){.cols.c-2-1,.cols.c-1-1{grid-template-columns:1fr;}}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;}
.panel-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.panel-h h3{margin:0;display:flex;align-items:center;gap:9px;} .panel-h h3 svg{width:17px;height:17px;color:var(--muted);}
.chart-wrap{position:relative;height:240px;} .chart-wrap.sm{height:210px;}
.feed{display:flex;flex-direction:column;gap:2px;}
.feed-row{display:flex;align-items:center;gap:11px;padding:9px 4px;border-bottom:1px solid var(--line);}
.feed-row:last-child{border-bottom:none;}
.feed-ic{width:30px;height:30px;border-radius:8px;background:var(--surface2);display:flex;align-items:center;justify-content:center;color:var(--muted);flex:0 0 auto;}
.feed-ic svg{width:15px;height:15px;}
.feed-main{min-width:0;flex:1;} .feed-main b{font-weight:600;font-size:13.5px;} .feed-main .meta{font-size:12px;color:var(--muted);}
.feed-time{font-size:11.5px;color:var(--muted);white-space:nowrap;}
.empty{color:var(--muted);text-align:center;padding:26px 0;font-size:13px;}
.fld{display:block;margin-bottom:16px;} .fld span{display:block;margin-bottom:7px;color:var(--muted);font-size:13px;font-weight:500;}
input,select,textarea{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--bg);
  color:var(--txt);font-size:14px;outline:none;font-family:inherit;transition:border-color .12s;}
input:focus,select:focus,textarea:focus{border-color:var(--accent);}
input[readonly]{color:var(--muted);cursor:pointer;}
.fld small{display:block;margin-top:6px;font-size:12px;line-height:1.5;}
/* Phone field: country selector + number box on one line, the box taking the
   room. The selector is narrow on purpose — the closed control shows the flag
   and +39, the open list shows the country names. */
.fld .phonewrap{display:flex;gap:6px;margin:0;}
.phonewrap select{width:auto;flex:0 0 auto;max-width:118px;padding-left:8px;padding-right:8px;}
.phonewrap input{flex:1;min-width:0;}
/* Masked credential field: the eye sits inside the input, not beside it, so the
   field keeps the same width as every other one on the row. */
.secretwrap{position:relative;display:block;}
.secretwrap input{padding-right:40px;}
.peek{position:absolute;top:50%;right:6px;transform:translateY(-50%);background:none;border:0;
  padding:6px;cursor:pointer;color:var(--muted);display:flex;line-height:0;border-radius:6px;}
.peek:hover{color:var(--txt);background:var(--surface2);}
.peek.on{color:var(--accent);}
.peek svg{width:16px;height:16px;}
.row{display:flex;gap:14px;flex-wrap:wrap;} .row .fld{flex:1;min-width:150px;}
.btn{padding:10px 16px;border:none;border-radius:8px;background:var(--accent);color:#fff;font-weight:600;
  cursor:pointer;font-size:14px;transition:filter .12s;display:inline-flex;align-items:center;gap:7px;} .btn:hover{filter:brightness(1.08);}
.btn svg{width:15px;height:15px;}
.btn.ghost{background:var(--surface2);border:1px solid var(--line);color:var(--txt);}
.btn.ghost:hover{border-color:var(--line2);filter:none;background:var(--surface);}
.btn.danger{background:var(--red-bg);border:1px solid var(--red);color:var(--red);}
.btn.danger:hover{background:var(--red);color:#fff;filter:none;}
.btn.tiny{padding:6px 12px;font-size:12.5px;}
.inline{display:inline-flex;gap:8px;align-items:center;margin:0 10px 8px 0;}
.inline input,.inline select{width:auto;}
table{width:100%;border-collapse:separate;border-spacing:0;background:var(--surface);
  border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;}
th,td{text-align:left;padding:11px 14px;border-bottom:1px solid var(--line);vertical-align:middle;}
th{color:var(--muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;background:var(--surface2);}
tbody tr:hover{background:var(--surface2);} tr:last-child td{border-bottom:none;}
.pill{display:inline-block;padding:4px 10px;border-radius:7px;background:var(--surface2);font-size:12px;font-weight:600;border:1px solid var(--line);text-transform:capitalize;}
.pill-pending,.pill-requested,.pill-open{color:var(--amber);background:var(--amber-bg);border-color:transparent;}
/* Partner-facing lead ladder (partner.php): new -> contacted/qualified/working
   -> negotiation -> won/lost. Cool at the start, warm in the middle, decided at
   the end, so a partner reads movement down the column at a glance. */
.pill-new{color:var(--accent);background:var(--accent-soft);border-color:transparent;}
.pill-contacted,.pill-working{color:var(--amber);background:var(--amber-bg);border-color:transparent;}
.pill-qualified,.pill-negotiation{color:var(--violet);background:rgba(124,92,255,.14);border-color:transparent;}
.pill-sent,.pill-confirmed,.pill-done,.pill-won,.pill-converted{color:var(--green);background:var(--green-bg);border-color:transparent;}
.pill-failed,.pill-cancelled,.pill-lost,.pill-junk,.pill-no_show{color:var(--red);background:var(--red-bg);border-color:transparent;}
.reason-err{color:var(--red);word-break:break-word;max-width:340px;}
.flash{background:var(--green-bg);border:1px solid var(--green);color:var(--green);padding:12px 16px;
  border-radius:8px;margin-bottom:18px;word-break:break-word;font-weight:500;}
.flash-err{background:var(--red-bg);border-color:var(--red);color:var(--red);}
/* saved, but worth a second look — a partner entered under a name already on the roster. */
.flash-warn{background:var(--amber-bg);border-color:var(--amber);color:var(--amber);}
.warn{background:var(--amber-bg);border:1px solid var(--amber);color:var(--amber);padding:12px 16px;
  border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.55;}
.step{background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--accent);
  border-radius:10px;padding:17px 21px;margin-bottom:14px;}
.step.accent{border-left-color:var(--amber);} .step p{line-height:1.65;color:var(--muted);} .step b{color:var(--txt);font-weight:600;}
.tabs{display:inline-flex;gap:4px;margin-bottom:16px;background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:3px;}
.tabs a{padding:7px 14px;border-radius:7px;color:var(--muted);font-size:13px;font-weight:500;}
.tabs a.on{background:var(--accent);color:#fff;}
.login{background:var(--surface);padding:38px 36px;border-radius:14px;width:360px;text-align:center;border:1px solid var(--line);}
.login .logo{margin:0 auto 18px;width:50px;height:50px;font-size:22px;} .login h1{font-size:21px;margin-bottom:5px;}
.login input{margin:9px 0;} .login button{width:100%;margin-top:10px;}
.err{color:var(--red);font-size:13px;margin-bottom:8px;}
/* kanban */
.kanban{display:flex;gap:14px;overflow-x:auto;padding-bottom:8px;}
.kcol{flex:0 0 270px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);display:flex;flex-direction:column;max-height:72vh;}
.kcol-h{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;}
.kcol-h .dotc{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:8px;}
.kcol-h .cnt{font-size:11px;color:var(--muted);background:var(--surface2);border-radius:20px;padding:2px 8px;}
.kbody{padding:10px;display:flex;flex-direction:column;gap:9px;overflow-y:auto;min-height:60px;}
.kbody.drag{outline:2px dashed var(--line2);outline-offset:-6px;border-radius:8px;}
.kcard{background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:11px 12px;cursor:grab;}
.kcard:hover{border-color:var(--line2);} .kcard b{font-size:13.5px;font-weight:600;}
.kcard .meta{font-size:11.5px;color:var(--muted);margin-top:4px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.kcard .amt{color:var(--green);font-weight:600;}
/* Note preview under a lead (kanban card + list row). It used to be a single nowrap
   line cut with an ellipsis: on a long note that showed the first few words and
   nothing else. Wrap over several lines and clamp instead, so the note is readable
   while the card keeps a predictable height. pre-line keeps the author's newlines. */
.note-clip{display:-webkit-box;-webkit-box-orient:vertical;overflow:hidden;
  white-space:pre-line;overflow-wrap:anywhere;font-style:italic;line-height:1.45;}
.note-clip.l2{-webkit-line-clamp:2;line-clamp:2;}
.note-clip.l4{-webkit-line-clamp:4;line-clamp:4;}
/* "typed in by hand" marker — tells a lead someone keyed in from a lead that
   arrived on its own (public form / fair form / partner API). */
.byhand{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:6px;
  background:var(--amber-bg);color:var(--amber);font-size:11px;font-weight:600;white-space:nowrap;}
.byhand svg{width:11px;height:11px;flex:0 0 auto;}
/* "brought in by a partner" marker — which referrer this lead belongs to. Its own
   colour, not .byhand's: a partner lead and a hand-keyed lead are different facts
   and both can be true at once. */
.bypartner{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:6px;
  background:var(--accent-soft);color:var(--accent);font-size:11px;font-weight:600;white-space:nowrap;}
.bypartner svg{width:11px;height:11px;flex:0 0 auto;}
.avatar{display:inline-flex;width:22px;height:22px;border-radius:50%;background:var(--accent-soft);color:var(--accent);
  align-items:center;justify-content:center;font-size:11px;font-weight:700;}
.tl{display:flex;flex-direction:column;gap:0;}
.tl-row{display:flex;gap:11px;padding:9px 0;border-bottom:1px solid var(--line);}
.tl-row:last-child{border-bottom:none;}
.tl-ic{width:26px;height:26px;border-radius:7px;background:var(--surface2);display:flex;align-items:center;justify-content:center;color:var(--muted);flex:0 0 auto;}
.tl-ic svg{width:13px;height:13px;} .tl-main{flex:1;min-width:0;} .tl-main .meta{font-size:11.5px;color:var(--muted);}
.lb{display:flex;align-items:center;gap:10px;padding:10px 4px;border-bottom:1px solid var(--line);}
.lb:last-child{border-bottom:none;} .lb .nm{flex:1;font-weight:600;} .lb .sc{font-weight:700;color:var(--accent);}
.lb .mini{font-size:11.5px;color:var(--muted);}
details.drawer{margin-bottom:8px;} details.drawer>summary{cursor:pointer;list-style:none;}
details.drawer>summary::-webkit-details-marker{display:none;}
/* Drawer header row (leads/deals/partners/agents): avatar · .dw-info · pills · agent.
   .dw-info carries the name + phone + email and absorbs the slack. */
summary.dw-sum{display:flex;align-items:center;gap:12px;padding:13px 18px;}
.dw-info{flex:1;min-width:0;}
/* Click-to-call number: accent-coloured link + phone glyph, sits inline in muted text. */
a.tel{display:inline-flex;align-items:center;gap:4px;color:var(--accent);white-space:nowrap;vertical-align:baseline;}
a.tel svg{width:13px;height:13px;flex:0 0 auto;}
a.tel:hover{text-decoration:underline;}
@media(max-width:560px){
  /* A phone number is one unbreakable token. Squeezed into what the pills leave over on
     a narrow screen it overflowed the card and agents could not read it, so let the row
     wrap and give .dw-info the full width. */
  summary.dw-sum{flex-wrap:wrap;row-gap:6px;}
  .dw-info{flex-basis:100%;}
}
/* hamburger (mobile only) + off-canvas backdrop */
.navtoggle{display:none;background:var(--surface2);border:1px solid var(--line);color:var(--txt);
  border-radius:8px;padding:7px;cursor:pointer;align-items:center;justify-content:center;margin-right:4px;}
.navtoggle svg{width:20px;height:20px;display:block;}
.nav-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:40;}
@media(max-width:900px){
  /* Sidebar becomes a slide-in drawer with full labels — no more icon-only rail. */
  .sidebar{position:fixed;top:0;left:0;height:100dvh;width:248px;z-index:50;
    transform:translateX(-100%);transition:transform .22s ease;box-shadow:0 0 40px rgba(0,0,0,.4);}
  .sidebar.open{transform:translateX(0);}
  .nav-backdrop.show{display:block;}
  .navtoggle{display:inline-flex;}
  .topbar{padding:11px 16px;gap:10px;}
  .crumb{font-size:16px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .content{padding:16px;}
  /* Trim the topbar so it fits a phone: drop the public-form button + username. */
  .actions{gap:8px;} .actions .btn.tiny span{display:none;}
  .topbar .pubform,.topbar .who{display:none;}
}
@media(max-width:560px){
  .row{flex-direction:column;gap:0;} .row .fld{min-width:0;}
  .grid{grid-template-columns:1fr;}
  .cols.c-2-1,.cols.c-1-1{grid-template-columns:1fr;}
  .langsw a{padding:4px 8px;}
  .login{width:100%;max-width:360px;padding:30px 22px;}
  .tabs{display:flex;flex-wrap:wrap;}
  th,td{padding:9px 11px;}
}
/* Wide tables scroll sideways inside their own box instead of overflowing the
   page. .table-wrap is added around every table by JS (see render_foot). */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin-bottom:16px;}
.table-wrap table{margin-bottom:0;}
@media(max-width:560px){.table-wrap table{min-width:520px;}}
/* commission statements (Provvigioni): the office desk, an agent's own list, the partner area */
.cm-card{background:var(--surface);border:1px solid var(--line);border-radius:12px;margin-bottom:10px;}
.cm-card>summary{display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;list-style:none;flex-wrap:wrap;}
.cm-card>summary::-webkit-details-marker{display:none;}
.cm-card .cm-t{flex:1;min-width:180px;}
.cm-card .cm-t b{display:block;margin-bottom:2px;}
.cm-amt{font-weight:700;font-size:16px;white-space:nowrap;}
.cm-body{padding:4px 16px 16px;border-top:1px solid var(--line);}
.cm-st{display:inline-block;padding:4px 10px;border-radius:7px;font-size:12px;font-weight:600;white-space:nowrap;}
.cm-st-sent{color:var(--amber);background:var(--amber-bg);}
.cm-st-invoiced{color:var(--accent);background:var(--accent-soft);}
.cm-st-paid{color:var(--green);background:var(--green-bg);}
.cm-st-cancelled{color:var(--red);background:var(--red-bg);}
.cm-steps{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0;}
.cm-step{flex:1;min-width:130px;padding:8px 10px;border:1px solid var(--line);border-radius:9px;font-size:12px;color:var(--muted);background:var(--surface2);}
.cm-step b{display:block;color:var(--txt);margin-bottom:2px;}
.cm-step.done{border-color:var(--green);}
.cm-step.done b{color:var(--green);}
.cm-kv{display:grid;grid-template-columns:max-content 1fr;gap:6px 14px;font-size:13px;margin:10px 0;}
.cm-kv dt{color:var(--muted);}
.cm-kv dd{margin:0;min-width:0;overflow-wrap:anywhere;}
.cm-note{background:var(--surface2);border-radius:9px;padding:10px 12px;font-size:13px;margin:8px 0;white-space:pre-wrap;}
.cm-warn{background:var(--red-bg);color:var(--red);border-radius:9px;padding:10px 12px;font-size:13px;margin:8px 0;}
.cm-form{border:1px dashed var(--line2);border-radius:10px;padding:12px;margin-top:12px;}
.cm-form .fld{margin-bottom:10px;}
.cm-chips{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 14px;}
.cm-chip{padding:6px 12px;border:1px solid var(--line);border-radius:999px;font-size:13px;color:var(--txt);text-decoration:none;background:var(--surface);}
.cm-chip.on{border-color:var(--accent);color:var(--accent);}
.cm-acc{border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin-bottom:14px;}
.cm-acc-row{display:flex;gap:8px;align-items:flex-start;margin-top:6px;font-size:13px;}
.cm-strip{border:1px solid var(--line);border-radius:10px;padding:10px 12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
@media (max-width:560px){.cm-kv{grid-template-columns:1fr;gap:2px;}.cm-kv dt{margin-top:6px;}}
</style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php }

/**
 * One team-chat bubble, as HTML — shared by the page render, the live poll and
 * the assistant's in-place answer, so a message drawn any of the three ways
 * looks the same. Mine on the right, everyone else on the left with their
 * name; the assistant's Markdown becomes HTML, with its proposed actions as
 * cards; a CRM note sits centred. $me is the viewer's user id.
 */
function team_bubble(array $m, callable $t, callable $h, int $me): string {
    $role = (string)$m['role'];
    $mid  = (int)$m['id'];
    if ($role === 'system') {
        return '<div class="tm-sys" data-mid="' . $mid . '">' . $h($m['body']) . ' <span class="muted">· ' . $h(short_time($m['created_at'])) . '</span></div>';
    }
    $mine = $role === 'user' && (int)($m['sender_id'] ?? 0) === $me;
    $cls  = $mine ? 'staff' : 'cust';
    if ($role === 'assistant') { $cls .= ' ai'; }
    ob_start(); ?>
<div class="msg <?= $cls ?>" data-mid="<?= $mid ?>">
  <?php if ($role === 'assistant'): ?>
    <div class="msg-b"><?= \Glue\Ai\Markdown::toHtml((string)$m['body']) ?></div>
    <?php foreach ((array)($m['meta']['actions'] ?? []) as $a) { echo team_action_card($a, $mid, $t, $h); } ?>
    <?php if (!empty($m['meta']['tools'])): ?>
      <div class="ai-tools">🔎 <?= $h(implode(', ', (array)$m['meta']['tools'])) ?></div>
    <?php endif; ?>
  <?php else: ?>
    <?php if ((string)$m['body'] !== ''): ?><div class="msg-b"><?= nl2br($h($m['body'])) ?></div><?php endif; ?>
    <?php if (!empty($m['attachment_path'])): $kind = (string)($m['attachment_kind'] ?? 'file'); ?>
      <?php if ($kind === 'audio'): ?>
        <div class="msg-b"><audio controls preload="metadata" src="?tdl=<?= $mid ?>" style="max-width:230px;height:40px"></audio></div>
      <?php elseif ($kind === 'video'): ?>
        <div class="msg-b"><video controls preload="metadata" src="?tdl=<?= $mid ?>" playsinline></video></div>
      <?php elseif ($kind === 'image'): ?>
        <div class="msg-b"><a href="?tdl=<?= $mid ?>" target="_blank"><img class="tm-img" src="?tdl=<?= $mid ?>" alt="<?= $h($m['attachment_name']) ?>"></a></div>
      <?php else: ?>
        <div class="msg-b"><a href="?tdl=<?= $mid ?>">📎 <?= $h($m['attachment_name'] ?: $t('tk_attachment')) ?></a></div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
  <div class="msg-m"><?= $h($role === 'assistant' ? $t('tm_ai') : ($m['sender_name'] ?: $t('tk_staff'))) ?> · <?= $h(short_time($m['created_at'])) ?></div>
</div>
<?php return (string)ob_get_clean();
}

/** A proposed action on an assistant message: what it will do, and Confirm / Cancel while it is still pending. */
function team_action_card(array $a, int $mid, callable $t, callable $h): string {
    $st = (string)($a['status'] ?? 'pending');
    $icon = ['create_task' => '📝', 'add_note' => '🗒', 'send_message_to_customer' => '💬', 'send_whatsapp' => '📲', 'set_ticket_status' => '🎫',
             'move_lead_stage' => '➡️', 'move_deal_stage' => '➡️', 'update_lead' => '✏️', 'create_lead' => '➕', 'book_appointment' => '📅'][$a['tool'] ?? ''] ?? '⚙️';
    $html = '<div class="ai-act ' . $h($st) . '" data-mid="' . $mid . '" data-aid="' . $h($a['id'] ?? '') . '">'
          . '<div class="ai-act-l">' . $icon . ' ' . $h($a['label'] ?? $a['tool'] ?? '') . '</div>';
    if ($st === 'pending') {
        $html .= '<div class="ai-act-b"><button type="button" class="btn tiny" data-act="ok">✓ ' . $h($t('tm_confirm')) . '</button>'
               . '<button type="button" class="btn tiny ghost" data-act="no">' . $h($t('tm_cancel')) . '</button></div>';
    } else {
        $label = ['done' => '✅ ' . $t('tm_done'), 'failed' => '❌ ' . $t('tm_failed'), 'cancelled' => '⛔ ' . $t('tm_cancelled')][$st] ?? $st;
        $html .= '<div class="ai-act-s">' . $h($label) . (!empty($a['result']) ? ' — ' . $h($a['result']) : '')
               . (!empty($a['at']) ? ' <span class="muted">· ' . $h(short_time($a['at'])) . '</span>' : '') . '</div>';
    }
    return $html . '</div>';
}

/**
 * One commission statement as a card: amount and status on the summary line;
 * inside, the calculation, the invoice and the payment, each step with its
 * date, then whatever form the viewer may use at this point ($actions).
 * Shared by the office desk (Provvigioni), an agent's own list and the partner
 * area, so all three show a statement the same way. Its files download through
 * ?cmf=<id>&w=calc|invoice on the page that draws it — each page checks the
 * viewer may see them. $who names the payee (the office list only).
 */
function commission_card(array $s, callable $t, callable $h, string $actions = '', bool $open = false, string $who = ''): string {
    $id = (int)$s['id'];
    $st = (string)$s['status'];
    $m  = fn($n): string => \Glue\Commission\Statements::money((float)$n);
    $d  = fn($v): string => $v ? date('d/m/Y', strtotime((string)$v)) : '';
    $dt = fn($v): string => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '';
    $shown = $st === 'paid' ? ($s['paid_amount'] ?? $s['amount'])
           : ($st === 'invoiced' ? ($s['invoice_amount'] ?? $s['amount']) : $s['amount']);
    ob_start(); ?>
<details class="cm-card" id="cm-<?= $id ?>"<?= $open ? ' open' : '' ?>>
  <summary>
    <span class="cm-t"><b><?= $h($s['title']) ?></b>
      <span class="muted small"><?= $who !== '' ? $h($who) . ' · ' : '' ?><?= $h($t('cm_no')) ?> <?= $id ?> · <?= $h($d($s['created_at'])) ?><?= !empty($s['period']) ? ' · ' . $h($s['period']) : '' ?></span></span>
    <span class="cm-amt"><?= $h($m($shown)) ?></span>
    <span class="cm-st cm-st-<?= $h($st) ?>"><?= $h($t('cm_st_' . $st)) ?></span>
  </summary>
  <div class="cm-body">
    <div class="cm-steps">
      <div class="cm-step done"><b>1 · <?= $h($t('cm_step_calc')) ?></b><?= $h($dt($s['created_at'])) ?></div>
      <div class="cm-step<?= in_array($st, ['invoiced', 'paid'], true) ? ' done' : '' ?>"><b>2 · <?= $h($t('cm_step_invoice')) ?></b><?= $h(in_array($st, ['invoiced', 'paid'], true) ? $dt($s['invoiced_at']) : ($st === 'cancelled' ? '—' : $t('cm_step_waiting'))) ?></div>
      <div class="cm-step<?= $st === 'paid' ? ' done' : '' ?>"><b>3 · <?= $h($t('cm_step_paid')) ?></b><?= $h($st === 'paid' ? $d($s['paid_on']) : ($st === 'cancelled' ? '—' : $t('cm_step_waiting'))) ?></div>
    </div>
    <?php if ($st === 'cancelled'): ?>
      <div class="cm-warn">⛔ <?= $h($t('cm_cancelled_on')) ?> <?= $h($dt($s['cancelled_at'])) ?><?= !empty($s['cancel_note']) ? ' — ' . $h($s['cancel_note']) : '' ?></div>
    <?php endif; ?>
    <?php if ($st === 'sent' && !empty($s['rejected_note'])): ?>
      <div class="cm-warn">↩ <?= $h($t('cm_rejected_h')) ?> (<?= $h($dt($s['rejected_at'])) ?>): <?= $h($s['rejected_note']) ?></div>
    <?php endif; ?>
    <dl class="cm-kv">
      <dt><?= $h($t('cm_amount')) ?></dt><dd><b><?= $h($m($s['amount'])) ?></b></dd>
      <?php if (!empty($s['calc_path'])): ?>
        <dt><?= $h($t('cm_calc')) ?></dt><dd><a href="?cmf=<?= $id ?>&amp;w=calc" target="_blank">📎 <?= $h($s['calc_name'] ?: $t('cm_download')) ?></a></dd>
      <?php endif; ?>
      <?php if (!empty($s['invoice_number'])): ?>
        <dt><?= $h($t('cm_invoice_h')) ?></dt><dd><?= $h($t('cm_inv_no_short')) ?> <b><?= $h($s['invoice_number']) ?></b> <?= $h($t('cm_of')) ?> <?= $h($d($s['invoice_date'])) ?> · <?= $h($m($s['invoice_amount'] ?? $s['amount'])) ?><?= ($s['invoice_by'] ?? '') === 'office' ? ' <span class="muted small">(' . $h($t('cm_by_office')) . ')</span>' : '' ?>
          <?php if (!empty($s['invoice_path'])): ?><br><a href="?cmf=<?= $id ?>&amp;w=invoice" target="_blank">📄 <?= $h($s['invoice_name'] ?: $t('cm_download')) ?></a><?php endif; ?></dd>
        <?php if (!empty($s['invoice_note'])): ?><dt><?= $h($t('cm_inv_note')) ?></dt><dd><?= $h($s['invoice_note']) ?></dd><?php endif; ?>
      <?php endif; ?>
      <?php if ($st === 'paid'): ?>
        <dt><?= $h($t('cm_paid_h')) ?></dt><dd><b style="color:var(--green)"><?= $h($m($s['paid_amount'] ?? $s['amount'])) ?></b> <?= $h($t('cm_on')) ?> <?= $h($d($s['paid_on'])) ?><?= !empty($s['payment_ref']) ? ' · ' . $h($s['payment_ref']) : '' ?></dd>
      <?php endif; ?>
    </dl>
    <?php if (!empty($s['notes'])): ?><div class="cm-note"><?= $h($s['notes']) ?></div><?php endif; ?>
    <?php if (!empty($s['accruals'])): ?>
      <div class="muted small" style="margin-top:8px"><?= $h($t('cm_covers')) ?></div>
      <ul class="small" style="margin:4px 0 0 18px;padding:0">
        <?php foreach ($s['accruals'] as $a): ?><li><?= $h($a['customer_name'] ?: ($a['deal_title'] ?: ('#' . (int)$a['id']))) ?> — <?= $h($m($a['amount'])) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?= $actions ?>
  </div>
</details>
<?php return (string)ob_get_clean();
}

/**
 * The invoice form for a statement. For the payee ($office false): while the
 * statement waits for it, and folded away to replace it until it is paid. For
 * the office ($office true): to record an invoice that arrived by email. After
 * a rejection the fields come back filled in and the file already sent may be
 * kept. $do is the POST action of the page it sits on.
 */
function commission_invoice_form(array $s, callable $t, callable $h, string $do, bool $office = false): string {
    $st = (string)$s['status'];
    if (!in_array($st, ['sent', 'invoiced'], true)) {
        return '';
    }
    $id      = (int)$s['id'];
    $hasFile = !empty($s['invoice_path']);
    $amount  = number_format((float)($s['invoice_amount'] ?? $s['amount']), 2, ',', '');
    $form = '<form method="post" enctype="multipart/form-data" class="cm-form">'
        . '<input type="hidden" name="do" value="' . $h($do) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<b class="small">' . $h($t($office ? 'cm_inv_office_h' : ($st === 'invoiced' ? 'cm_inv_replace' : 'cm_inv_send_h'))) . '</b>'
        . '<p class="muted small" style="margin:4px 0 10px">' . $h($t($office ? 'cm_inv_office_hint' : 'cm_inv_hint')) . '</p>'
        . '<div class="row">'
        . '<label class="fld"><span>' . $h($t('cm_inv_file')) . ($hasFile ? '' : ' *') . '</span><input type="file" name="invoice"'
        . ($hasFile ? '' : ' required') . ' accept=".pdf,.xml,.p7m,image/*"></label>'
        . '<label class="fld"><span>' . $h($t('cm_inv_number')) . ' *</span><input name="invoice_number" required maxlength="60" value="' . $h($s['invoice_number'] ?? '') . '"></label>'
        . '</div><div class="row">'
        . '<label class="fld"><span>' . $h($t('cm_inv_date')) . '</span><input type="date" name="invoice_date" value="' . $h($s['invoice_date'] ?? date('Y-m-d')) . '"></label>'
        . '<label class="fld"><span>' . $h($t('cm_inv_amount')) . '</span><input name="invoice_amount" inputmode="decimal" value="' . $h($amount) . '"></label>'
        . '</div>'
        . '<label class="fld"><span>' . $h($t('cm_inv_note')) . '</span><input name="invoice_note" maxlength="500" value="' . $h($s['invoice_note'] ?? '') . '"></label>'
        . '<button class="btn tiny">' . svg('send') . ' ' . $h($t($office ? 'cm_inv_office_btn' : 'cm_inv_send')) . '</button></form>';
    // Already invoiced: replacing it is the exception, so it stays folded away.
    if ($st === 'invoiced') {
        return '<details style="margin-top:10px"><summary class="btn ghost tiny">' . $h($t('cm_inv_replace')) . '</summary>' . $form . '</details>';
    }
    return $form;
}
