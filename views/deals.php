<?php
/**
 * Deals (Pipeline) — kanban by stage, value totals, create form, and a per-deal
 * drawer (assign, move, note, timeline). Moving into the quote stage starts the
 * signing reminders; the won stage fires thank-you + logistics (Crm\Deals).
 * In scope: $t, $h, $pdo, $agents, $money, $uid.
 */
$stages = \Glue\Crm\Pipelines::stagesForEntity('deal');
$byStage = \Glue\Crm\Deals::byStage($scopeId ?? null);
$rows = \Glue\Crm\Deals::all(300, $scopeId ?? null);

$stageTotals = [];
foreach ($byStage as $code => $cards) {
    $stageTotals[$code] = array_sum(array_map(fn($c) => (float)$c['amount'], $cards));
}
?>
<h2><?= $h($t('nav_deals')) ?></h2>

<?php if (empty($isAgent)): ?>
<details class="drawer">
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('deals') ?> <?= $h($t('deal_new')) ?></summary>
  <form method="post" class="card" style="margin-top:12px">
    <input type="hidden" name="do" value="deal_create">
    <label class="fld"><span><?= $h($t('f_title')) ?></span><input name="title" required></label>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_amount')) ?></span><input name="amount" type="number" step="0.01" value="0"></label>
      <label class="fld"><span><?= $h($t('f_close')) ?></span><input name="expected_close_date" type="date"></label>
      <label class="fld"><span><?= $h($t('f_sign_due')) ?></span><input name="sign_due_date" type="date"><small class="muted"><?= $h($t('f_sign_due_h')) ?></small></label>
      <label class="fld"><span><?= $h($t('th_agent')) ?></span><?php agent_select($h, $agents, 'assigned_to', null, $t('unassigned')); ?></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_name')) ?></span><input name="name"></label>
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone"></label>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email"></label>
    </div>
    <button class="btn"><?= $h($t('save')) ?></button>
  </form>
</details>
<?php endif; ?>

<div class="kanban" id="kb-deal">
  <?php foreach ($stages as $s): $cards = $byStage[$s['code']] ?? []; ?>
    <div class="kcol">
      <div class="kcol-h">
        <span><span class="dotc" style="background:<?= $h($s['color'] ?: '#5b6cff') ?>"></span><?= $h(stage_label($t, $s['code'], $s['name'])) ?></span>
        <span class="cnt"><?= count($cards) ?> · <?= $h($money($stageTotals[$s['code']] ?? 0)) ?></span>
      </div>
      <div class="kbody" data-stage="<?= $h($s['code']) ?>">
        <?php foreach ($cards as $c): $ag = $c['agent_name'] ?: $c['agent_username']; ?>
          <div class="kcard" draggable="true" data-id="<?= $h($c['id']) ?>">
            <b><?= $h($c['title']) ?></b>
            <div class="meta">
              <span class="amt"><?= $h($money($c['amount'], $c['currency'])) ?></span>
              <?php if ($ag): ?><span><?= avatar($h, $ag) ?> <?= $h($ag) ?></span><?php endif; ?>
            </div>
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
    $timeline = \Glue\Crm\Activities::forEntity('deal', (int)$r['id'], 20); ?>
  <details class="drawer card" style="padding:0;margin-bottom:8px">
    <summary style="display:flex;align-items:center;gap:12px;padding:13px 18px;cursor:pointer">
      <span style="flex:1"><b><?= $h($r['title']) ?></b>
        <span class="muted small"> · <?= $h($r['customer_name']) ?></span></span>
      <span class="amt" style="color:var(--green);font-weight:600"><?= $h($money($r['amount'], $r['currency'])) ?></span>
      <span class="pill"><?= $h(stage_label($t, $r['stage_code'], \Glue\Crm\Pipelines::label('deal', $r['stage_code']))) ?></span>
      <?= pill($h, $r['status'], $t) ?>
      <span class="muted small"><?= $ag ? $h($ag) : $h($t('unassigned')) ?></span>
    </summary>
    <div style="padding:4px 18px 18px;border-top:1px solid var(--line)">
      <div class="cols c-1-1" style="margin-bottom:0">
        <div>
          <h3><?= $h($t('actions')) ?></h3>
          <?php if (empty($isAgent)): ?>
          <form method="post" class="inline"><input type="hidden" name="do" value="deal_assign">
            <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <?php agent_select($h, $agents, 'agent_id', $r['assigned_to'], $t('assign_seller')); ?>
            <button class="btn tiny"><?= $h($t('assign')) ?></button></form>
          <?php endif; ?>
          <form method="post" class="inline"><input type="hidden" name="do" value="deal_move">
            <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <select name="stage">
              <?php foreach ($stages as $s): ?>
                <option value="<?= $h($s['code']) ?>"<?= $s['code'] === $r['stage_code'] ? ' selected' : '' ?>><?= $h(stage_label($t, $s['code'], $s['name'])) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="date" name="sign_due_date" value="<?= $h($r['sign_due_date'] ?? '') ?>" title="<?= $h($t('f_sign_due_h')) ?>">
            <button class="btn tiny ghost"><?= $h($t('move')) ?></button></form>
          <form method="post" style="margin-top:12px"><input type="hidden" name="do" value="deal_note">
            <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <label class="fld"><span><?= $h($t('add_note')) ?></span><textarea name="body" rows="2" required></textarea></label>
            <button class="btn tiny ghost"><?= $h($t('save')) ?></button></form>
        </div>
        <div>
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
  document.querySelectorAll('#kb-deal .kcard').forEach(card=>{
    card.addEventListener('dragstart',e=>{dragId=card.dataset.id;e.dataTransfer.effectAllowed='move';});
  });
  document.querySelectorAll('#kb-deal .kbody').forEach(body=>{
    body.addEventListener('dragover',e=>{e.preventDefault();body.classList.add('drag');});
    body.addEventListener('dragleave',()=>body.classList.remove('drag'));
    body.addEventListener('drop',e=>{
      e.preventDefault();body.classList.remove('drag');
      if(!dragId)return;
      const fd=new FormData();fd.append('do','deal_move');fd.append('ajax','1');fd.append('id',dragId);fd.append('stage',body.dataset.stage);
      fetch('?',{method:'POST',body:fd}).then(r=>r.json()).then(()=>location.reload());
    });
  });
})();
</script>
