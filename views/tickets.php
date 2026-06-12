<?php
/**
 * Tickets — customer conversations as a two-pane inbox: conversation list on the
 * left, the selected thread with a compact reply bar on the right. Agents see
 * only tickets assigned to them (scoped); admins see all.
 * In scope: $t, $h, $scopeId, $isAgent. Set $ticketBack='messages' before
 * including to keep navigation on the Messages tab.
 */
$backTab = !empty($ticketBack) ? (string)$ticketBack : 'tickets';
$rows = \Glue\Crm\Tickets::forStaff($scopeId ?? null, 300);

$sel = (int)($_GET['tk'] ?? ($_POST['id'] ?? 0));
$cur = null;
foreach ($rows as $r) {
    if ((int)$r['id'] === $sel) { $cur = $r; break; }
}
if (!$cur && $rows) { $cur = $rows[0]; $sel = (int)$cur['id']; }
$thread = $cur ? \Glue\Crm\Tickets::thread($sel) : [];
?>
<h2 style="margin-bottom:12px"><?= $h($t('nav_tickets')) ?></h2>

<?php if (!$rows): ?>
  <div class="empty"><?= $h($t('none_yet')) ?></div>
<?php else: ?>
<div class="tk-wrap">
  <aside class="tk-list">
    <?php foreach ($rows as $r):
        $waiting = $r['last_sender'] === 'customer' && $r['status'] !== 'closed'; ?>
      <a class="tk-item<?= (int)$r['id'] === $sel ? ' on' : '' ?><?= $waiting ? ' wait' : '' ?>"
         href="?tab=<?= $h($backTab) ?>&tk=<?= $h($r['id']) ?>">
        <span class="tk-row1"><b><?= $h($r['customer_name'] ?: '#' . $r['contact_id']) ?></b>
          <span class="tk-time"><?= $h(short_time($r['updated_at'])) ?></span></span>
        <span class="tk-row2"><span class="tk-subj"><?= $h($r['subject']) ?></span>
          <?php if ($waiting): ?><span class="tk-dot" title="<?= $h($t('tk_new_reply')) ?>"></span><?php endif; ?>
        </span>
        <span class="tk-row3"><?= pill($h, $r['status'], $t) ?>
          <?php if (empty($isAgent)): ?><span class="muted small"><?= $h($r['agent_name'] ?: $r['agent_username'] ?: $t('unassigned')) ?></span><?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </aside>

  <section class="tk-pane">
    <?php if ($cur): ?>
      <div class="tk-head">
        <div>
          <b><?= $h($cur['subject']) ?></b>
          <div class="muted small"><?= $h($cur['customer_name']) ?>
            <?= $cur['customer_phone'] ? ' · ' . $h($cur['customer_phone']) : '' ?>
            <?= $cur['customer_email'] ? ' · ' . $h($cur['customer_email']) : '' ?></div>
        </div>
        <div class="tk-head-r">
          <?= pill($h, $cur['status'], $t) ?>
          <form method="post" class="inline"><input type="hidden" name="do" value="ticket_status">
            <input type="hidden" name="id" value="<?= $h($cur['id']) ?>"><input type="hidden" name="back" value="<?= $h($backTab) ?>">
            <?php if ($cur['status'] !== 'closed'): ?>
              <input type="hidden" name="status" value="closed">
              <button class="btn tiny ghost"><?= $h($t('tk_close')) ?></button>
            <?php else: ?>
              <input type="hidden" name="status" value="open">
              <button class="btn tiny ghost"><?= $h($t('tk_reopen')) ?></button>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <div class="chat" id="tk-chat">
        <?php foreach ($thread as $m): $mine = $m['sender_type'] !== 'customer'; ?>
          <div class="msg <?= $mine ? 'staff' : 'cust' ?>">
            <?php if ((string)$m['body'] !== ''): ?><div class="msg-b"><?= nl2br($h($m['body'])) ?></div><?php endif; ?>
            <?php if (!empty($m['attachment_path'])): ?>
              <div class="msg-b"><a href="?dl=<?= $h($m['id']) ?>">📎 <?= $h($m['attachment_name'] ?: $t('tk_attachment')) ?></a></div>
            <?php endif; ?>
            <div class="msg-m"><?= $h($m['sender_name'] ?: ($mine ? $t('tk_staff') : $t('th_customer'))) ?> · <?= $h(short_time($m['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($cur['status'] !== 'closed'): ?>
        <form method="post" enctype="multipart/form-data" class="tk-replybar">
          <input type="hidden" name="do" value="ticket_reply"><input type="hidden" name="id" value="<?= $h($cur['id']) ?>">
          <input type="hidden" name="back" value="<?= $h($backTab) ?>">
          <label class="tk-att" title="<?= $h($t('tk_attach')) ?>">📎
            <input type="file" name="attachment"
              onchange="this.closest('form').querySelector('.tk-fn').textContent=this.files.length?this.files[0].name:''">
          </label>
          <textarea name="body" rows="1" placeholder="<?= $h($t('tk_reply')) ?>…"></textarea>
          <button class="btn tiny"><?= svg('send') ?> <?= $h($t('tk_send')) ?></button>
          <div class="tk-fn small muted"></div>
        </form>
      <?php else: ?>
        <div class="tk-closed muted small"><?= $h($t('tk_close')) ?>d</div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
<script>(function(){var c=document.getElementById('tk-chat');if(c)c.scrollTop=c.scrollHeight;})();</script>
<?php endif; ?>

<style>
.tk-wrap{display:flex;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--surface);height:calc(100vh - 215px);min-height:440px}
.tk-list{width:300px;flex-shrink:0;border-right:1px solid var(--line);overflow-y:auto}
.tk-item{display:block;padding:12px 14px;border-bottom:1px solid var(--line);text-decoration:none;color:var(--txt)}
.tk-item:hover{background:var(--surface2)}
.tk-item.on{background:var(--surface2);box-shadow:inset 3px 0 0 var(--accent)}
.tk-row1{display:flex;justify-content:space-between;align-items:baseline;gap:8px;font-size:13.5px}
.tk-time{color:var(--muted);font-size:11.5px;white-space:nowrap}
.tk-row2{display:flex;align-items:center;gap:7px;margin:3px 0 6px}
.tk-subj{color:var(--muted);font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tk-item.wait .tk-subj{color:var(--txt);font-weight:600}
.tk-dot{width:8px;height:8px;border-radius:50%;background:var(--accent);flex-shrink:0}
.tk-row3{display:flex;align-items:center;gap:8px}
.tk-pane{flex:1;display:flex;flex-direction:column;min-width:0}
.tk-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px 18px;border-bottom:1px solid var(--line)}
.tk-head-r{display:flex;align-items:center;gap:10px}
.chat{flex:1;display:flex;flex-direction:column;gap:8px;overflow-y:auto;padding:16px 18px}
.msg{max-width:72%;padding:9px 13px;border-radius:12px;font-size:13.5px;line-height:1.5}
.msg-m{font-size:11px;color:var(--muted);margin-top:5px}
.msg-b a{color:var(--accent);font-weight:600}
.msg.cust{align-self:flex-start;background:var(--surface2);border:1px solid var(--line);border-bottom-left-radius:3px}
.msg.staff{align-self:flex-end;background:var(--accent-soft);border:1px solid var(--line);border-bottom-right-radius:3px}
.tk-replybar{display:flex;align-items:center;gap:9px;padding:12px 14px;border-top:1px solid var(--line);flex-wrap:wrap}
.tk-replybar textarea{flex:1;min-width:0;resize:none;margin:0}
.tk-replybar .btn{flex-shrink:0;margin:0}
.tk-att{width:38px;height:38px;flex-shrink:0;display:flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:9px;background:var(--surface2);cursor:pointer;font-size:15px}
.tk-att:hover{border-color:var(--line2)}
.tk-att input{display:none}
.tk-fn{flex-basis:100%;padding-left:47px}.tk-fn:empty{display:none}
.tk-closed{padding:13px 18px;border-top:1px solid var(--line)}
@media (max-width:900px){.tk-wrap{flex-direction:column;height:auto}.tk-list{width:100%;max-height:260px;border-right:none;border-bottom:1px solid var(--line)}}
</style>
