<?php
/**
 * Chat del team — staff talking to staff inside the CRM, and to the AI
 * assistant. Same two-pane inbox as the customer chat (Tickets): the list on
 * the left, the open conversation on the right, a reply bar that takes text,
 * a file, a voice note or a video. The assistant's conversation is pinned at
 * the top of the list and answers in place, without a page load.
 *
 * In scope: $t, $h, $uid, $lang, $isAgent, $isTech. Chats are scoped by
 * membership only (Team\Chat::isMember), whatever the role.
 */

use Glue\Ai\Assistant;
use Glue\Team\Chat;

$me = (int)$uid;
$myName = trim((string)($_SESSION['glue_user']['full_name'] ?? '')) ?: (string)($_SESSION['glue_user']['username'] ?? '');
?>
<?php if ($me <= 0): ?>
  <h2><?= $h($t('nav_team')) ?></h2>
  <div class="card"><div class="empty"><?= $h($t('tm_need_account')) ?></div></div>
  <?php return; ?>
<?php endif; ?>
<?php
$chats  = Chat::listFor($me);
$aiId   = Chat::ai($me);
$selRaw = (string)($_GET['c'] ?? '');
$sel    = $selRaw === 'ai' ? $aiId : (int)$selRaw;
if ($sel > 0 && $sel !== $aiId && !Chat::isMember($sel, $me)) {
    $sel = 0;
}
if ($sel <= 0) {
    $sel = $chats ? (int)$chats[0]['id'] : $aiId;
}
$cur     = Chat::find($sel);
$isAi    = $cur && $cur['kind'] === 'ai';
$members = $cur ? Chat::members($sel) : [];
$thread  = $cur ? Chat::recent($sel, 200) : [];
$lastId  = 0;
foreach ($thread as $m) { $lastId = max($lastId, (int)$m['id']); }
if ($cur) { Chat::markRead($sel, $me, $lastId); }
$people  = Chat::people($me);
$limitMb = round(Chat::uploadLimitBytes() / 1048576);
$title = static function (array $c) use ($t): string {
    if ($c['kind'] === 'group') { return (string)$c['name']; }
    return (string)($c['other_name'] ?? $t('tm_direct'));
};
$curTitle = $cur ? ($isAi ? $t('tm_ai') : ($cur['kind'] === 'group' ? (string)$cur['name']
    : (string)(array_values(array_filter($members, fn($u) => (int)$u['id'] !== $me))[0]['full_name'] ?? $t('tm_direct')))) : '';
if ($cur && !$isAi && $cur['kind'] === 'direct') {
    foreach ($members as $u) {
        if ((int)$u['id'] !== $me) { $curTitle = trim((string)$u['full_name']) ?: (string)$u['username']; }
    }
}
$roleLabel = fn(string $r): string => $t('role_' . $r) !== 'role_' . $r ? $t('role_' . $r) : $r;
$aiOn = Assistant::configured();
?>
<div class="tk-topbar">
  <h2 style="margin:0"><?= $h($t('nav_team')) ?></h2>
  <button class="btn tiny" type="button" onclick="document.getElementById('tm-new').classList.toggle('show')">+ <?= $h($t('tm_new')) ?></button>
</div>

<div id="tm-new" class="card tm-newcard">
  <form method="post">
    <input type="hidden" name="do" value="team_new">
    <p class="muted small" style="margin:0 0 10px"><?= $h($t('tm_new_help')) ?></p>
    <div class="tm-people">
      <?php foreach ($people as $p): ?>
        <label class="tm-person"><input type="checkbox" name="users[]" value="<?= (int)$p['id'] ?>">
          <?= avatar($h, $p['full_name'] ?: $p['username']) ?> <b><?= $h(trim((string)$p['full_name']) ?: $p['username']) ?></b>
          <span class="muted small"><?= $h($roleLabel((string)$p['role'])) ?></span></label>
      <?php endforeach; ?>
      <?php if (!$people): ?><span class="muted small"><?= $h($t('tm_nobody')) ?></span><?php endif; ?>
    </div>
    <div class="row" style="margin-top:10px">
      <label class="fld"><span><?= $h($t('tm_group_name')) ?></span><input name="name" placeholder="<?= $h($t('tm_group_name_ph')) ?>"></label>
    </div>
    <button class="btn tiny"><?= $h($t('tm_start')) ?></button>
  </form>
</div>

<div class="tk-wrap">
  <aside class="tk-list">
    <a class="tk-item tm-ai<?= $isAi ? ' on' : '' ?>" href="?tab=team&c=ai">
      <span class="tk-row1"><b>🤖 <?= $h($t('tm_ai')) ?></b></span>
      <span class="tk-row2"><span class="tk-subj"><?= $h($aiOn ? $t('tm_ai_sub') : $t('tm_ai_off')) ?></span></span>
    </a>
    <?php foreach ($chats as $c): $unread = (int)$c['unread']; ?>
      <a class="tk-item<?= (int)$c['id'] === $sel ? ' on' : '' ?><?= $unread ? ' wait' : '' ?>" href="?tab=team&c=<?= (int)$c['id'] ?>">
        <span class="tk-row1"><b><?= $c['kind'] === 'group' ? '👥 ' : '' ?><?= $h($title($c)) ?></b>
          <span class="tk-time"><?= $h(short_time($c['last_message_at'] ?: $c['created_at'])) ?></span></span>
        <span class="tk-row2">
          <span class="tk-subj"><?= $h($c['last_kind'] ? ['audio' => '🎤 ', 'video' => '🎥 ', 'image' => '🖼 ', 'file' => '📎 '][$c['last_kind']] ?? '' : '') ?><?= $h(mb_substr((string)($c['last_body'] ?? ''), 0, 60)) ?></span>
          <?php if ($unread): ?><span class="tm-n"><?= $unread ?></span><?php endif; ?>
        </span>
        <?php if ($c['kind'] === 'group'): ?><span class="tk-row3 muted small"><?= (int)$c['member_count'] ?> <?= $h($t('tm_members')) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <?php if (!$chats): ?><div class="muted small" style="padding:14px"><?= $h($t('tm_none')) ?></div><?php endif; ?>
  </aside>

  <section class="tk-pane">
    <?php if ($cur): ?>
      <div class="tk-head">
        <div style="min-width:0">
          <b><?= $isAi ? '🤖 ' : ($cur['kind'] === 'group' ? '👥 ' : '') ?><?= $h($curTitle) ?></b>
          <div class="muted small">
            <?php if ($isAi): ?>
              <?= $h($t('tm_ai_head')) ?>
            <?php else: ?>
              <?= $h(implode(', ', array_map(fn($u) => (trim((string)$u['full_name']) ?: $u['username']) . ((int)$u['id'] === $me ? ' (' . $t('tm_you') . ')' : ''), $members))) ?>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!$isAi && $cur['kind'] === 'group'): ?>
          <div class="tk-head-r">
            <details class="drawer" style="display:inline-block">
              <summary class="btn ghost tiny">+ <?= $h($t('tm_add')) ?></summary>
              <form method="post" class="card" style="margin-top:8px;min-width:240px;position:absolute;right:18px;z-index:5">
                <input type="hidden" name="do" value="team_add"><input type="hidden" name="id" value="<?= $sel ?>">
                <?php $inChat = array_map(fn($u) => (int)$u['id'], $members); $addable = array_filter($people, fn($p) => !in_array((int)$p['id'], $inChat, true)); ?>
                <?php foreach ($addable as $p): ?>
                  <label class="tm-person"><input type="checkbox" name="users[]" value="<?= (int)$p['id'] ?>"> <?= $h(trim((string)$p['full_name']) ?: $p['username']) ?></label>
                <?php endforeach; ?>
                <?php if (!$addable): ?><span class="muted small"><?= $h($t('tm_nobody')) ?></span><?php endif; ?>
                <button class="btn tiny" style="margin-top:8px"><?= $h($t('tm_add')) ?></button>
              </form>
            </details>
            <form method="post" class="inline" onsubmit="return confirm('<?= $h($t('tm_leave_confirm')) ?>')">
              <input type="hidden" name="do" value="team_leave"><input type="hidden" name="id" value="<?= $sel ?>">
              <button class="btn ghost tiny"><?= $h($t('tm_leave')) ?></button>
            </form>
          </div>
        <?php endif; ?>
      </div>

      <div class="chat" id="tm-chat" data-c="<?= $sel ?>" data-last="<?= $lastId ?>" data-me="<?= $me ?>">
        <?php if ($isAi && !$thread): ?>
          <div class="tm-hello">
            <p><?= $h($t('tm_ai_hello')) ?></p>
            <div class="tm-chips">
              <?php foreach (['tm_ex1', 'tm_ex2', 'tm_ex3', 'tm_ex4', 'tm_ex5'] as $k): ?>
                <button type="button" class="tm-chip" data-q="<?= $h($t($k)) ?>"><?= $h($t($k)) ?></button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <?php foreach ($thread as $m) { echo team_bubble($m, $t, $h, $me); } ?>
      </div>

      <form method="post" enctype="multipart/form-data" class="tk-replybar" id="tm-form" data-ai="<?= $isAi ? '1' : '0' ?>">
        <input type="hidden" name="do" value="<?= $isAi ? 'ai_ask' : 'team_send' ?>"><input type="hidden" name="id" value="<?= $sel ?>">
        <?php if (!$isAi): ?>
          <label class="tk-att" title="<?= $h($t('tk_attach')) ?>">📎
            <input type="file" name="attachment"
              onchange="this.closest('form').querySelector('.tk-fn').textContent=this.files.length?this.files[0].name:''">
          </label>
          <button type="button" class="tk-att tk-mic" data-mic title="<?= $h($t('tk_rec')) ?>" hidden>🎤</button>
          <button type="button" class="tk-att" data-video title="<?= $h($t('tm_video')) ?>">🎥</button>
        <?php endif; ?>
        <textarea name="body" rows="1" placeholder="<?= $h($isAi ? $t('tm_ask_ph') : $t('tm_write_ph')) ?>…"></textarea>
        <button class="btn tiny" id="tm-send"><?= svg('send') ?> <?= $h($t('tk_send')) ?></button>
        <div class="tk-fn small muted"></div>
        <?php if (!$isAi): ?><div class="small muted tm-limit"><?= $h(str_replace('{mb}', (string)$limitMb, $t('tm_limit'))) ?></div><?php endif; ?>
      </form>
    <?php else: ?>
      <div class="empty" style="margin:30px"><?= $h($t('tm_pick')) ?></div>
    <?php endif; ?>
  </section>
</div>

<script>
(function(){
  var c=document.getElementById('tm-chat'); if(!c) return;
  var form=document.getElementById('tm-form'), isAi=form&&form.dataset.ai==='1';
  var L=<?= json_encode(['thinking' => $t('tm_thinking'), 'fail' => $t('test_fail'), 'confirm' => $t('tm_confirm'),
      'cancel' => $t('tm_cancel'), 'done' => $t('tm_done'), 'cancelled' => $t('tm_cancelled')], JSON_UNESCAPED_UNICODE) ?>;
  c.scrollTop=c.scrollHeight;
  function append(html){var near=c.scrollHeight-c.scrollTop-c.clientHeight<80;c.insertAdjacentHTML('beforeend',html);if(near||isAi)c.scrollTop=c.scrollHeight;}
  function bump(id){if(id>+c.dataset.last)c.dataset.last=id;}

  // Live updates: new messages from the others, without a refresh.
  var busy=false, asking=false;
  function poll(){
    if(busy||asking||document.hidden) return; busy=true;
    fetch('?poll=team&c='+encodeURIComponent(c.dataset.c)+'&after='+encodeURIComponent(c.dataset.last),{headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.ok?r.json():null;})
      .then(function(d){busy=false;if(!d||!d.messages)return;
        d.messages.forEach(function(m){if(c.querySelector('[data-mid="'+m.id+'"]'))return;append(m.html);bump(m.id);});})
      .catch(function(){busy=false;});
  }
  setInterval(poll,4000);
  document.addEventListener('visibilitychange',function(){if(!document.hidden)poll();});

  // Enter sends, Shift+Enter is a new line.
  var ta=form&&form.querySelector('textarea');
  if(ta){ta.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();
    if(isAi)ask();else form.requestSubmit();}});}

  // The assistant answers in place: post the question, show a typing bubble,
  // swap in the real bubbles when they arrive.
  function ask(q){
    if(!isAi) return;
    q=(q!==undefined?q:ta.value).trim(); if(!q) return;
    ta.value=''; var hello=c.querySelector('.tm-hello'); if(hello)hello.remove();
    var btn=document.getElementById('tm-send'); btn.disabled=true; asking=true;
    var wait=document.createElement('div'); wait.className='msg cust ai tm-wait'; wait.innerHTML='<span class="tm-dots"><i></i><i></i><i></i></span> '+L.thinking;
    var fd=new FormData(); fd.append('do','ai_ask'); fd.append('ajax','1'); fd.append('id',c.dataset.c); fd.append('body',q);
    fetch('?',{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.json();})
      .then(function(d){
        wait.remove(); btn.disabled=false; asking=false; c.querySelectorAll('.tm-mine-tmp').forEach(function(x){x.remove();});
        if(!d||!d.messages){append('<div class="msg cust ai">⚠️ '+L.fail+'</div>');return;}
        d.messages.forEach(function(m){if(c.querySelector('[data-mid="'+m.id+'"]'))return;append(m.html);bump(m.id);});
        c.scrollTop=c.scrollHeight;
      })
      .catch(function(){wait.remove();btn.disabled=false;asking=false;c.querySelectorAll('.tm-mine-tmp').forEach(function(x){x.remove();});append('<div class="msg cust ai">⚠️ '+L.fail+'</div>');});
    // the question shows at once; the server's copy replaces it by id when it arrives
    append('<div class="msg staff tm-mine-tmp">'+q.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/\n/g,'<br>')+'</div>');
    c.appendChild(wait); c.scrollTop=c.scrollHeight;
  }
  if(isAi){form.addEventListener('submit',function(e){e.preventDefault();ask();});
    c.addEventListener('click',function(e){var ch=e.target.closest('.tm-chip');if(ch){ask(ch.dataset.q);}});}

  // Confirm / cancel a proposed action: the card updates, the outcome lands as a note.
  c.addEventListener('click',function(e){
    var b=e.target.closest('[data-act]'); if(!b) return;
    var card=b.closest('.ai-act'), mid=card.dataset.mid, aid=card.dataset.aid, what=b.dataset.act;
    card.querySelectorAll('button').forEach(function(x){x.disabled=true;});
    var fd=new FormData(); fd.append('do',what==='ok'?'ai_confirm':'ai_cancel'); fd.append('ajax','1'); fd.append('id',c.dataset.c); fd.append('mid',mid); fd.append('aid',aid);
    fetch('?',{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.json();})
      .then(function(d){
        if(d&&d.card){card.outerHTML=d.card;}
        if(d&&d.note){append(d.note.html);bump(d.note.id);}
        if(!d||!d.ok){card.querySelectorAll('button').forEach(function(x){x.disabled=false;});}
      })
      .catch(function(){card.querySelectorAll('button').forEach(function(x){x.disabled=false;});});
  });

  // 🎥: the phone's camera (capture) or, on a desktop, a video file — dropped
  // into the attachment input so sending works exactly like attaching.
  var vb=form&&form.querySelector('[data-video]');
  if(vb){var cap=document.createElement('input');cap.type='file';cap.accept='video/*';cap.setAttribute('capture','environment');cap.style.display='none';form.appendChild(cap);
    vb.addEventListener('click',function(){cap.click();});
    cap.addEventListener('change',function(){if(!cap.files||!cap.files.length)return;var input=form.querySelector('input[type=file][name=attachment]');
      try{var dt=new DataTransfer();dt.items.add(cap.files[0]);input.files=dt.files;}catch(e){}
      form.querySelector('.tk-fn').textContent='🎥 '+cap.files[0].name;});}
})();
</script>
<?php if ($cur && !$isAi): ?>
<?= chat_recorder_js($t('tk_rec'), $t('tk_rec_stop'), $t('tk_rec_ready'), $t('tk_rec_deny')) ?>
<?php endif; ?>
<script>window.addEventListener('pageshow',function(e){if(e.persisted)location.reload();});</script>

<style>
.tk-wrap{display:flex;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--surface);height:calc(100vh - 215px);min-height:440px}
.tk-list{width:300px;flex-shrink:0;border-right:1px solid var(--line);overflow-y:auto}
.tk-topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.tm-newcard{display:none;margin-bottom:14px}.tm-newcard.show{display:block}
.tm-people{display:flex;flex-wrap:wrap;gap:8px}
.tm-person{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border:1px solid var(--line);border-radius:9px;background:var(--surface2);cursor:pointer;font-size:13px}
.tm-person input{width:auto;margin:0}
.tk-item{display:block;padding:12px 14px;border-bottom:1px solid var(--line);text-decoration:none;color:var(--txt)}
.tk-item:hover{background:var(--surface2)}
.tk-item.on{background:var(--surface2);box-shadow:inset 3px 0 0 var(--accent)}
.tk-item.tm-ai{background:var(--accent-soft)}
.tk-row1{display:flex;justify-content:space-between;align-items:baseline;gap:8px;font-size:13.5px}
.tk-time{color:var(--muted);font-size:11.5px;white-space:nowrap}
.tk-row2{display:flex;align-items:center;justify-content:space-between;gap:7px;margin:3px 0 2px}
.tk-subj{color:var(--muted);font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tk-item.wait .tk-subj{color:var(--txt);font-weight:600}
.tm-n{flex-shrink:0;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:var(--accent);color:#fff;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;justify-content:center}
.tk-row3{display:flex;align-items:center;gap:8px}
.tk-pane{flex:1;display:flex;flex-direction:column;min-width:0}
.tk-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px 18px;border-bottom:1px solid var(--line);position:relative}
.tk-head-r{display:flex;align-items:center;gap:10px}
.chat{flex:1;display:flex;flex-direction:column;gap:8px;overflow-y:auto;padding:16px 18px}
.msg{max-width:72%;padding:9px 13px;border-radius:12px;font-size:13.5px;line-height:1.5}
.msg-m{font-size:11px;color:var(--muted);margin-top:5px}
.msg-b a{color:var(--accent);font-weight:600}
.msg.cust{align-self:flex-start;background:var(--surface2);border:1px solid var(--line);border-bottom-left-radius:3px}
.msg.staff{align-self:flex-end;background:var(--accent-soft);border:1px solid var(--line);border-bottom-right-radius:3px}
.msg.ai{max-width:86%}
.msg.ai p{margin:0 0 8px}.msg.ai p:last-child{margin-bottom:0}
.msg.ai ul,.msg.ai ol{margin:4px 0 8px 18px;padding:0}.msg.ai li{margin:2px 0}
.msg.ai h2,.msg.ai h3,.msg.ai h4{margin:8px 0 4px;font-size:14px}
.msg.ai code{background:var(--surface);padding:1px 5px;border-radius:5px;font-size:12.5px}
.ai-code{background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:12.5px;white-space:pre-wrap;overflow-x:auto}
.ai-tablewrap{overflow-x:auto;margin:6px 0}
.ai-table{border-collapse:collapse;font-size:12.5px;min-width:auto}
.ai-table th,.ai-table td{border:1px solid var(--line);padding:4px 8px;text-align:left;white-space:nowrap}
.ai-act{margin-top:8px;padding:9px 11px;border:1px solid var(--accent);border-radius:9px;background:var(--surface)}
.ai-act .ai-act-l{font-size:13px}
.ai-act .ai-act-b{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.ai-act.done{border-color:var(--green)}.ai-act.failed{border-color:var(--red)}.ai-act.cancelled{border-color:var(--line);opacity:.7}
.ai-act .ai-act-s{font-size:12px;margin-top:5px}
.ai-tools{font-size:11px;color:var(--muted);margin-top:6px}
.tm-sys{align-self:center;font-size:12px;color:var(--muted);background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:4px 10px;max-width:86%;text-align:center}
.tm-hello{align-self:stretch;color:var(--muted);font-size:13.5px;padding:8px 4px}
.tm-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.tm-chip{border:1px solid var(--line);background:var(--surface2);color:var(--txt);border-radius:9px;padding:7px 11px;font:inherit;font-size:12.5px;cursor:pointer;text-align:left}
.tm-chip:hover{border-color:var(--accent)}
.tm-dots i{display:inline-block;width:6px;height:6px;margin-right:3px;border-radius:50%;background:var(--muted);animation:tmb 1s infinite}
.tm-dots i:nth-child(2){animation-delay:.2s}.tm-dots i:nth-child(3){animation-delay:.4s}
@keyframes tmb{50%{opacity:.25}}
.msg video{max-width:280px;width:100%;border-radius:8px;display:block}
.msg img.tm-img{max-width:260px;max-height:260px;border-radius:8px;display:block}
.tk-replybar{display:flex;align-items:center;gap:9px;padding:12px 14px;border-top:1px solid var(--line);flex-wrap:wrap}
.tk-replybar textarea{flex:1;min-width:0;resize:none;margin:0}
.tk-replybar .btn{flex-shrink:0;margin:0}
.tk-att{width:38px;height:38px;flex-shrink:0;display:flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:9px;background:var(--surface2);cursor:pointer;font-size:15px}
.tk-att:hover{border-color:var(--line2)}
.tk-att input{display:none}
.tk-mic.rec{border-color:var(--red,#e5616e);background:rgba(229,97,110,.15);color:var(--red,#e5616e);animation:tkpulse 1s infinite}
@keyframes tkpulse{50%{opacity:.55}}
.tk-fn{flex-basis:100%;padding-left:47px}.tk-fn:empty{display:none}
.tm-limit{flex-basis:100%;padding-left:47px}
@media (max-width:900px){.tk-wrap{flex-direction:column;height:auto}.tk-list{width:100%;max-height:240px;border-right:none;border-bottom:1px solid var(--line)}.chat{min-height:320px;max-height:60vh}.msg{max-width:88%}.msg.ai{max-width:96%}}
</style>
