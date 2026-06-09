<?php
/**
 * Overview — CRM KPIs, message volume, lead funnel, recent activity, upcoming
 * reminders, the agent KPI leaderboard and a connection-status strip.
 * In scope: $t, $h, $pdo, $cfg, $count, $money, $agents, $lang.
 */
use Glue\Crm\Deals;
use Glue\Crm\Tasks;
use Glue\Notify\TextMeBot;

// Agent scope: when set, every KPI is limited to records assigned to this seller.
$scope  = !empty($scopeId) ? (int)$scopeId : null;
$sLead  = $scope ? " AND assigned_to = $scope" : ''; // leads / deals / tasks
$sAppt  = $scope ? " AND agent_id = $scope" : '';    // appointments
$leadsW = $scope ? " WHERE assigned_to = $scope" : '';

$openLeads = $count("SELECT COUNT(*) FROM leads WHERE status='open'$sLead");
$openDeals = $count("SELECT COUNT(*) FROM deals WHERE status='open'$sLead");
$pipeVal   = Deals::openValue($scope);
$apptUpcoming = $count("SELECT COUNT(*) FROM appointments WHERE status IN('requested','confirmed') AND (starts_at IS NULL OR starts_at >= NOW()-INTERVAL 1 DAY)$sAppt");
$tasksOpen = $count("SELECT COUNT(*) FROM tasks WHERE status='open'$sLead");
$tasksOverdue = $count("SELECT COUNT(*) FROM tasks WHERE status='open' AND due_at IS NOT NULL AND due_at < NOW()$sLead");
$wonCount  = $count("SELECT COUNT(*) FROM deals WHERE status='won'$sLead");
$convBase  = $count("SELECT COUNT(*) FROM leads$leadsW");
$conv = $convBase > 0 ? round(100 * $count("SELECT COUNT(*) FROM leads WHERE status='converted'$sLead") / $convBase) : 0;

// messages per day (14d)
$days = [];
for ($i = 13; $i >= 0; $i--) { $days[date('Y-m-d', strtotime("-$i day"))] = ['sent' => 0, 'failed' => 0]; }
foreach ($pdo->query("SELECT DATE(created_at) d, status, COUNT(*) c FROM messages
                      WHERE created_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY d, status") as $r) {
    if (isset($days[$r['d']][$r['status']])) { $days[$r['d']][$r['status']] = (int)$r['c']; }
}
$chart = [
    'labels' => array_map(fn($d) => date('M j', strtotime($d)), array_keys($days)),
    'sent'   => array_map(fn($v) => $v['sent'], array_values($days)),
    'failed' => array_map(fn($v) => $v['failed'], array_values($days)),
    'tSent' => $t('lg_sent'), 'tFailed' => $t('lg_failed'),
];

// lead funnel by stage
$leadStages = \Glue\Crm\Pipelines::stagesForEntity('lead');
$leadCounts = $pdo->query("SELECT stage_code, COUNT(*) c FROM leads WHERE status='open'$sLead GROUP BY stage_code")
    ->fetchAll(PDO::FETCH_KEY_PAIR);

$events = $pdo->query("SELECT * FROM events ORDER BY id DESC LIMIT 8")->fetchAll();
$upcoming = $pdo->query("SELECT * FROM reminders WHERE status='pending' ORDER BY due_at ASC LIMIT 8")->fetchAll();
$board = Tasks::leaderboard();

$bitrixOk = ($u = (string)$cfg('bitrix.base_url', '')) !== '' && !str_contains($u, 'CHANGE_ME') && $cfg('bitrix.sync_enabled', false);
$waOk = (new TextMeBot())->enabled();
$mailOk = (string)$cfg('mail.from_email', '') !== '';
?>
<h2><?= $h($t('ov_title')) ?></h2>

<div class="grid">
  <?php
  num_card($h, 'leads', $t('ov_open_leads'), $openLeads, $t('ov_open_leads_sub'));
  num_card($h, 'deals', $t('ov_open_deals'), $openDeals, $h($money($pipeVal)));
  num_card($h, 'appointments', $t('ov_appts'), $apptUpcoming, $t('ov_appts_sub'));
  num_card($h, 'tasks', $t('ov_tasks'), $tasksOpen, $tasksOverdue . ' ' . $t('ov_overdue'));
  num_card($h, 'trophy', $t('ov_conv'), $conv . '%', $wonCount . ' ' . $t('ov_won'));
  ?>
</div>

<div class="cols c-2-1">
  <?php if (empty($isAgent)): ?>
  <div class="panel">
    <div class="panel-h"><h3><?= svg('messages') ?><?= $h($t('ch_messages_title')) ?></h3></div>
    <div class="chart-wrap"><canvas id="chMsg"></canvas></div>
  </div>
  <?php endif; ?>
  <div class="panel">
    <div class="panel-h"><h3><?= svg('leads') ?><?= $h($t('ov_funnel')) ?></h3></div>
    <?php if (!$leadStages): ?><div class="empty"><?= $h($t('none_yet')) ?></div><?php endif; ?>
    <?php foreach ($leadStages as $s): $c = (int)($leadCounts[$s['code']] ?? 0); $pct = $openLeads > 0 ? round(100 * $c / $openLeads) : 0; ?>
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
          <span><span class="dotc" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $h($s['color'] ?: '#5b6cff') ?>;margin-right:7px"></span><?= $h(stage_label($t, $s['code'], $s['name'])) ?></span>
          <span class="muted"><?= $c ?></span>
        </div>
        <div style="height:7px;background:var(--surface2);border-radius:6px;overflow:hidden">
          <div style="height:100%;width:<?= $pct ?>%;background:<?= $h($s['color'] ?: '#5b6cff') ?>"></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if (empty($isAgent)): ?>
<div class="cols c-1-1">
  <div class="panel">
    <div class="panel-h"><h3><?= svg('events') ?><?= $h($t('ov_recent')) ?></h3>
      <a class="btn ghost tiny" href="?tab=events"><?= $h($t('filter_all')) ?></a></div>
    <div class="feed">
      <?php if (!$events): ?><div class="empty"><?= $h($t('none_yet')) ?></div><?php endif; ?>
      <?php foreach ($events as $e): ?>
        <div class="feed-row">
          <div class="feed-ic"><?= svg(feed_icon($e['source'])) ?></div>
          <div class="feed-main"><b><?= $h(code_label($t, 'evt_', $e['event_type'])) ?></b>
            <div class="meta"><?= $h(code_label($t, 'src_', $e['source'])) ?><?= $e['entity_type'] ? ' · ' . $h($e['entity_type']) . ' ' . $h($e['entity_id']) : '' ?></div></div>
          <div class="feed-time"><?= $h(short_time($e['created_at'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="panel">
    <div class="panel-h"><h3><?= svg('trophy') ?><?= $h($t('ov_leaderboard')) ?></h3>
      <a class="btn ghost tiny" href="?tab=tasks"><?= $h($t('filter_all')) ?></a></div>
    <?php if (!$board): ?><div class="empty"><?= $h($t('none_yet')) ?></div><?php endif; ?>
    <?php foreach ($board as $b): $nm = trim((string)($b['full_name'] ?? '')) ?: $b['username']; ?>
      <div class="lb">
        <?= avatar($h, $nm) ?>
        <div class="nm"><?= $h($nm) ?><div class="mini"><?= (int)$b['done'] ?> <?= $h($t('lb_done')) ?> · <?= (int)$b['overdue'] ?> <?= $h($t('ov_overdue')) ?></div></div>
        <div class="sc"><?= $b['kpi'] !== null ? $h($b['kpi']) : '—' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel" style="margin-bottom:16px">
  <div class="panel-h"><h3><?= svg('settings') ?><?= $h($t('ov_status')) ?></h3>
    <form method="post" class="inline" style="margin:0"><input type="hidden" name="do" value="run_scheduler">
      <button class="btn ghost tiny"><?= $h($t('ov_run')) ?></button></form></div>
  <div class="grid" style="margin-bottom:0">
    <?php
    stat_card($h, 'database', $t('st_db'), $t('configured'), true);
    stat_card($h, 'chat', $t('st_whatsapp'), $waOk ? $t('configured') : $t('not_configured'), $waOk);
    stat_card($h, 'mail', $t('st_mail'), $mailOk ? $t('configured') : $t('not_configured'), $mailOk);
    stat_card($h, 'link', $t('st_bitrix'), $bitrixOk ? $t('st_sync_on') : $t('st_sync_off'), (bool)$bitrixOk);
    ?>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  const d = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;
  const css = getComputedStyle(document.documentElement); const c = n => css.getPropertyValue(n).trim();
  const grid = 'rgba(255,255,255,.06)';
  const chMsg = document.getElementById('chMsg');
  if (!chMsg) return; // chart hidden for agents
  Chart.defaults.color = c('--muted'); Chart.defaults.font.family = 'Inter, sans-serif';
  new Chart(chMsg, {
    type:'bar',
    data:{labels:d.labels,datasets:[
      {label:d.tSent,data:d.sent,backgroundColor:c('--green'),borderRadius:5,maxBarThickness:26},
      {label:d.tFailed,data:d.failed,backgroundColor:c('--red'),borderRadius:5,maxBarThickness:26}]},
    options:{maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:12,padding:16}}},
      scales:{x:{grid:{display:false},border:{display:false}},
              y:{beginAtZero:true,ticks:{precision:0},grid:{color:grid},border:{display:false}}}}
  });
})();
</script>
