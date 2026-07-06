<?php
/**
 * Leads — kanban board (drag a card to change stage), a create form, and an
 * expandable list where each lead can be assigned to a seller, moved, converted
 * to a deal, annotated, and its timeline read. In scope: $t, $h, $pdo, $agents, $uid.
 */
$stages = \Glue\Crm\Pipelines::stagesForEntity('lead');
$byStage = \Glue\Crm\Leads::byStage($scopeId ?? null);
$rows = \Glue\Crm\Leads::all(300, $scopeId ?? null);
?>
<h2><?= $h($t('nav_leads')) ?></h2>

<?php if (empty($isAgent)): ?>
<details class="drawer">
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('leads') ?> <?= $h($t('lead_new')) ?></summary>
  <form method="post" class="card" style="margin-top:12px">
    <input type="hidden" name="do" value="lead_create">
    <div class="row">
      <label class="fld"><span><?= $h($t('f_name')) ?></span><input name="name" required></label>
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone"></label>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_company')) ?></span><input name="company"></label>
      <label class="fld"><span><?= $h($t('f_source')) ?></span><input name="source" value="manual"></label>
      <label class="fld"><span><?= $h($t('f_lang')) ?></span>
        <select name="lang"><option value="">—</option><option value="it">IT</option><option value="en">EN</option></select></label>
    </div>
    <label class="fld"><span><?= $h($t('f_message')) ?></span><textarea name="comments" rows="2"></textarea></label>
    <button class="btn"><?= $h($t('save')) ?></button>
  </form>
</details>
<?php endif; ?>

<div class="kanban" id="kb-lead">
  <?php foreach ($stages as $s): $cards = $byStage[$s['code']] ?? []; ?>
    <div class="kcol">
      <div class="kcol-h">
        <span><span class="dotc" style="background:<?= $h($s['color'] ?: '#5b6cff') ?>"></span><?= $h(stage_label($t, $s['code'], $s['name'])) ?></span>
        <span class="cnt"><?= count($cards) ?></span>
      </div>
      <div class="kbody" data-stage="<?= $h($s['code']) ?>">
        <?php foreach ($cards as $c): $nm = $c['customer_name'] ?: ('#' . $c['id']); $ag = $c['agent_name'] ?: $c['agent_username']; ?>
          <div class="kcard" draggable="true" data-id="<?= $h($c['id']) ?>">
            <b><?= $h($nm) ?></b>
            <div class="meta">
              <span><?= $h($c['source']) ?></span>
              <?php if ($ag): ?><span><?= avatar($h, $ag) ?> <?= $h($ag) ?></span><?php endif; ?>
            </div>
            <?php $cmsg = trim((string)($c['comments'] ?? '')); if ($cmsg !== ''): ?>
              <div class="muted small" style="margin-top:7px;font-style:italic;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">“<?= $h($cmsg) ?>”</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<h3 style="margin-top:22px"><?= $h($t('all')) ?> · <?= count($rows) ?></h3>
<?php if (!$rows): ?><div class="empty"><?= $h($t('none_yet')) ?></div><?php endif; ?>
<?php foreach ($rows as $r):
    $ag = $r['agent_name'] ?: $r['agent_username'];
    $msg = trim((string)($r['comments'] ?? ''));
    $timeline = \Glue\Crm\Activities::forEntity('lead', (int)$r['id'], 20); ?>
  <details class="drawer card" style="padding:0;margin-bottom:8px">
    <summary style="display:flex;align-items:center;gap:12px;padding:13px 18px;cursor:pointer">
      <?= avatar($h, $r['customer_name']) ?>
      <span style="flex:1;min-width:0"><b><?= $h($r['customer_name'] ?: ('#' . $r['id'])) ?></b>
        <span class="muted small"> · <?= $h($r['customer_phone']) ?> <?= $h($r['customer_email']) ?></span>
        <?php if ($msg !== ''): ?><span class="muted small" style="display:block;font-style:italic;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px">“<?= $h($msg) ?>”</span><?php endif; ?></span>
      <span class="pill"><?= $h(stage_label($t, $r['stage_code'], \Glue\Crm\Pipelines::label('lead', $r['stage_code']))) ?></span>
      <?= pill($h, $r['status'], $t) ?>
      <span class="muted small"><?= $ag ? $h($ag) : $h($t('unassigned')) ?></span>
      <?php $acc = \Glue\Portal\Account::accessStats((int)$r['contact_id']); if ($acc['count'] > 0): ?>
        <span class="pill" title="<?= $h($t('portal_access_title')) ?>" style="background:var(--accent-soft,rgba(91,108,255,.14));color:var(--accent,#5b6cff)">
          <?= svg('users') ?> <?= (int)$acc['count'] ?></span>
      <?php endif; ?>
    </summary>
    <div style="padding:4px 18px 18px;border-top:1px solid var(--line)">
      <?php if ($msg !== ''): ?>
        <div style="background:var(--surface2);border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin:8px 0 4px">
          <div class="muted small" style="margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;font-weight:600"><?= $h($t('f_message')) ?></div>
          <div style="white-space:pre-wrap;line-height:1.55"><?= nl2br($h($msg)) ?></div>
        </div>
      <?php endif; ?>
      <div class="cols c-1-1" style="margin-bottom:0">
        <div>
          <h3><?= $h($t('actions')) ?></h3>
          <?php if (empty($isAgent)): ?>
          <form method="post" class="inline"><input type="hidden" name="do" value="lead_assign">
            <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <?php agent_select($h, $agents, 'agent_id', $r['assigned_to'], $t('assign_seller')); ?>
            <button class="btn tiny"><?= $h($t('assign')) ?></button></form>
          <?php endif; ?>
          <form method="post" class="inline"><input type="hidden" name="do" value="lead_move">
            <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <select name="stage">
              <?php foreach ($stages as $s): ?>
                <option value="<?= $h($s['code']) ?>"<?= $s['code'] === $r['stage_code'] ? ' selected' : '' ?>><?= $h(stage_label($t, $s['code'], $s['name'])) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn tiny ghost"><?= $h($t('move')) ?></button></form>
          <?php if ($r['status'] === 'open'): ?>
          <form method="post" class="inline" onsubmit="return confirm('<?= $h($t('confirm_convert')) ?>')">
            <input type="hidden" name="do" value="lead_convert"><input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <button class="btn tiny"><?= svg('deals') ?> <?= $h($t('convert')) ?></button></form>
          <?php endif; ?>
          <form method="post" style="margin-top:12px"><input type="hidden" name="do" value="lead_note">
            <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <label class="fld"><span><?= $h($t('add_note')) ?></span>
              <textarea name="body" rows="2" required></textarea></label>
            <button class="btn tiny ghost"><?= $h($t('save')) ?></button></form>
        </div>
        <div>
          <?php $acc = \Glue\Portal\Account::accessStats((int)$r['contact_id']); ?>
          <h3><?= $h($t('portal_access_h')) ?></h3>
          <div class="muted small" style="margin:-4px 0 14px">
            <?php if ($acc['count'] > 0): ?>
              <?= $h($t('portal_access_count')) ?>: <strong><?= (int)$acc['count'] ?></strong>
              · <?= $h($t('portal_access_last')) ?>: <?= $h(short_time($acc['last'])) ?>
            <?php else: ?>
              <?= $h($t('portal_access_never')) ?>
            <?php endif; ?>
          </div>
          <h3><?= $h($t('timeline')) ?></h3>
          <div class="tl">
            <?php if (!$timeline): ?><div class="empty"><?= $h($t('none_yet')) ?></div><?php endif; ?>
            <?php foreach ($timeline as $a): ?>
              <div class="tl-row"><div class="tl-ic"><?= svg($a['type'] === 'note' ? 'messages' : ($a['type'] === 'stage' ? 'pipeline' : 'events')) ?></div>
                <div class="tl-main"><?= $h($a['body']) ?>
                  <div class="meta"><?= $h($a['full_name'] ?: $a['username'] ?: $t('system')) ?> · <?= $h(short_time($a['created_at'])) ?></div></div></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </details>
<?php endforeach; ?>

<script>
(function(){
  let dragId = null;
  document.querySelectorAll('#kb-lead .kcard').forEach(card=>{
    card.addEventListener('dragstart',e=>{dragId=card.dataset.id;e.dataTransfer.effectAllowed='move';});
  });
  document.querySelectorAll('#kb-lead .kbody').forEach(body=>{
    body.addEventListener('dragover',e=>{e.preventDefault();body.classList.add('drag');});
    body.addEventListener('dragleave',()=>body.classList.remove('drag'));
    body.addEventListener('drop',e=>{
      e.preventDefault();body.classList.remove('drag');
      if(!dragId)return;
      const fd=new FormData();fd.append('do','lead_move');fd.append('ajax','1');fd.append('id',dragId);fd.append('stage',body.dataset.stage);
      fetch('?',{method:'POST',body:fd}).then(r=>r.json()).then(()=>location.reload());
    });
  });
})();
</script>
