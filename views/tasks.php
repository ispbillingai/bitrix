<?php
/**
 * Tasks — assign work to sellers, complete with a KPI score, and an aggregate
 * leaderboard (the doc's "Score Evaluation (KPI)"). In scope: $t, $h, $agents, $uid.
 */
$rows = \Glue\Crm\Tasks::all(300);
$board = \Glue\Crm\Tasks::leaderboard();
?>
<h2><?= $h($t('nav_tasks')) ?></h2>

<div class="cols c-2-1">
  <div>
    <details class="drawer">
      <summary class="btn ghost" style="margin-bottom:14px"><?= svg('tasks') ?> <?= $h($t('task_new')) ?></summary>
      <form method="post" class="card" style="margin-top:12px">
        <input type="hidden" name="do" value="task_create">
        <label class="fld"><span><?= $h($t('f_title')) ?></span><input name="title" required></label>
        <div class="row">
          <label class="fld"><span><?= $h($t('th_agent')) ?></span><?php agent_select($h, $agents, 'assigned_to', null, $t('unassigned')); ?></label>
          <label class="fld"><span><?= $h($t('task_due')) ?></span><input type="datetime-local" name="due_at"></label>
          <label class="fld"><span><?= $h($t('task_priority')) ?></span>
            <select name="priority"><option value="normal"><?= $h($t('prio_normal')) ?></option>
              <option value="high"><?= $h($t('prio_high')) ?></option><option value="low"><?= $h($t('prio_low')) ?></option></select></label>
          <label class="fld"><span><?= $h($t('kpi_weight')) ?></span><input type="number" name="kpi_weight" value="1" min="1"></label>
        </div>
        <label class="fld"><span><?= $h($t('f_message')) ?></span><textarea name="description" rows="2"></textarea></label>
        <button class="btn"><?= $h($t('save')) ?></button>
      </form>
    </details>

    <table><thead><tr>
      <th><?= $h($t('f_title')) ?></th><th><?= $h($t('th_agent')) ?></th><th><?= $h($t('task_due')) ?></th>
      <th><?= $h($t('kpi_score')) ?></th><th><?= $h($t('th_status')) ?></th><th></th>
    </tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $ag = $r['agent_name'] ?: $r['agent_username'];
        $overdue = $r['status'] === 'open' && $r['due_at'] && strtotime($r['due_at']) < time(); ?>
      <tr>
        <td><b><?= $h($r['title']) ?></b>
          <?php if ($r['priority'] === 'high'): ?> <span class="pill pill-failed"><?= $h($t('prio_high')) ?></span><?php endif; ?></td>
        <td><?= $ag ? $h($ag) : '<span class="muted">—</span>' ?></td>
        <td class="small <?= $overdue ? '' : 'muted' ?>" style="<?= $overdue ? 'color:var(--red)' : '' ?>">
          <?= $r['due_at'] ? $h(short_time($r['due_at'])) : '—' ?></td>
        <td><?= $r['kpi_score'] !== null ? '<b>' . $h($r['kpi_score']) . '</b>' : '<span class="muted">—</span>' ?></td>
        <td><?= pill($h, $r['status'], $t) ?></td>
        <td style="white-space:nowrap">
          <?php if ($r['status'] === 'open'): ?>
            <details class="drawer"><summary class="btn ghost tiny"><?= $h($t('task_complete')) ?></summary>
              <form method="post" class="card" style="margin-top:10px;min-width:240px"><input type="hidden" name="do" value="task_complete">
                <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
                <label class="fld"><span><?= $h($t('kpi_score')) ?> (0–100)</span><input type="number" name="kpi_score" min="0" max="100"></label>
                <button class="btn tiny"><?= svg('check') ?> <?= $h($t('task_complete')) ?></button></form>
            </details>
          <?php else: ?><span class="muted small"><?= $h(short_time($r['completed_at'])) ?></span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>

  <div class="panel">
    <div class="panel-h"><h3><?= svg('trophy') ?><?= $h($t('ov_leaderboard')) ?></h3></div>
    <?php if (!$board): ?><div class="empty"><?= $h($t('none_yet')) ?></div><?php endif; ?>
    <?php foreach ($board as $b): $nm = trim((string)($b['full_name'] ?? '')) ?: $b['username']; ?>
      <div class="lb">
        <?= avatar($h, $nm) ?>
        <div class="nm"><?= $h($nm) ?><div class="mini"><?= (int)$b['done'] ?>/<?= (int)$b['total'] ?> <?= $h($t('lb_done')) ?> · <?= (int)$b['overdue'] ?> <?= $h($t('ov_overdue')) ?></div></div>
        <div class="sc"><?= $b['kpi'] !== null ? $h($b['kpi']) : '—' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
