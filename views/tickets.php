<?php
/**
 * Tickets — customer support threads. Each ticket is a conversation between a
 * customer and the assigned agent; reply and change status here. Agents see only
 * tickets assigned to them (scoped); admins see all. In scope: $t, $h, $scopeId, $isAgent.
 */
$rows = \Glue\Crm\Tickets::forStaff($scopeId ?? null, 300);
?>
<h2><?= $h($t('nav_tickets')) ?></h2>
<p class="muted"><?= $h($t('tickets_intro')) ?></p>

<?php if (!$rows): ?><div class="empty"><?= $h($t('none_yet')) ?></div><?php endif; ?>

<?php foreach ($rows as $r):
    $thread = \Glue\Crm\Tickets::thread((int)$r['id']);
    $ag = $r['agent_name'] ?: $r['agent_username'];
    $waiting = $r['last_sender'] === 'customer' && $r['status'] !== 'closed'; ?>
  <details class="drawer card" style="padding:0;margin-bottom:8px"<?= $waiting ? ' open' : '' ?>>
    <summary style="display:flex;align-items:center;gap:12px;padding:13px 18px;cursor:pointer">
      <span style="flex:1"><b><?= $h($r['subject']) ?></b>
        <span class="muted small"> · <?= $h($r['customer_name']) ?></span>
        <?php if ($waiting): ?><span class="pill pill-open" style="margin-left:6px"><?= $h($t('tk_new_reply')) ?></span><?php endif; ?>
      </span>
      <span class="muted small"><?= $h(short_time($r['updated_at'])) ?></span>
      <?= pill($h, $r['status'], $t) ?>
      <span class="muted small"><?= $ag ? $h($ag) : $h($t('unassigned')) ?></span>
    </summary>
    <div style="padding:14px 18px;border-top:1px solid var(--line)">
      <div class="chat">
        <?php foreach ($thread as $m): $mine = $m['sender_type'] !== 'customer'; ?>
          <div class="msg <?= $mine ? 'staff' : 'cust' ?>">
            <div class="msg-b"><?= nl2br($h($m['body'])) ?></div>
            <div class="msg-m"><?= $h($m['sender_name'] ?: ($mine ? $t('tk_staff') : $t('th_customer'))) ?> · <?= $h(short_time($m['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($r['status'] !== 'closed'): ?>
        <form method="post" style="margin-top:12px">
          <input type="hidden" name="do" value="ticket_reply"><input type="hidden" name="id" value="<?= $h($r['id']) ?>">
          <label class="fld"><span><?= $h($t('tk_reply')) ?></span><textarea name="body" rows="2" required></textarea></label>
          <button class="btn tiny"><?= svg('send') ?> <?= $h($t('tk_send')) ?></button>
        </form>
        <form method="post" class="inline" style="margin-top:6px"><input type="hidden" name="do" value="ticket_status">
          <input type="hidden" name="id" value="<?= $h($r['id']) ?>"><input type="hidden" name="status" value="closed">
          <button class="btn tiny ghost"><?= $h($t('tk_close')) ?></button></form>
      <?php else: ?>
        <form method="post" class="inline" style="margin-top:10px"><input type="hidden" name="do" value="ticket_status">
          <input type="hidden" name="id" value="<?= $h($r['id']) ?>"><input type="hidden" name="status" value="open">
          <button class="btn tiny ghost"><?= $h($t('tk_reopen')) ?></button></form>
      <?php endif; ?>
    </div>
  </details>
<?php endforeach; ?>

<style>
.chat{display:flex;flex-direction:column;gap:8px;max-height:420px;overflow-y:auto;padding:4px}
.msg{max-width:78%;padding:9px 13px;border-radius:12px;font-size:13.5px;line-height:1.5}
.msg-m{font-size:11px;color:var(--muted);margin-top:5px}
.msg.cust{align-self:flex-start;background:var(--surface2);border:1px solid var(--line);border-bottom-left-radius:3px}
.msg.staff{align-self:flex-end;background:var(--accent-soft);border:1px solid var(--line);border-bottom-right-radius:3px}
</style>
