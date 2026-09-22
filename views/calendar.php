<?php
/**
 * Calendar — the CRM's own diary: day, week and month, the unassigned pool, and
 * the form that books into it.
 *
 * Deliberately not a Google Calendar embed: everything here already lives in
 * the appointments table, the permissions are the CRM's own, and nobody has to
 * hand over an account. What a phone needs it gets from the .ics feed at the
 * bottom of the page.
 *
 * Who sees what: the office picks a person (or "everyone"); a technician or a
 * seller sees their own round and the pool, because the pool is where they take
 * work from. The pool is `agent_id IS NULL` — booked with the customer, not yet
 * resourced.
 *
 * In scope: $t, $h, $lang, $uid, $isAgent, $isTech, $agents, $pdo.
 */

use Glue\Crm\AppointmentTypes;
use Glue\Crm\Booking;
use Glue\Crm\Calendar;
use Glue\Crm\DayPlanner;
use Glue\Crm\Interventions;

$isAdminHere = !$isAgent && !$isTech;

// ---- what we are looking at -------------------------------------------------
$view  = in_array($_GET['v'] ?? '', ['day', 'week', 'month'], true) ? (string)$_GET['v'] : 'week';
$anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['d'] ?? '')) ? (string)$_GET['d'] : date('Y-m-d');
$month  = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : substr($anchor, 0, 7);
$typeF  = trim((string)($_GET['ty'] ?? ''));
$zoneF  = trim((string)($_GET['z'] ?? ''));
$who    = $isAdminHere ? (int)($_GET['u'] ?? 0) : (int)$uid;

$anchorTs = (int)strtotime($anchor);
$types    = AppointmentTypes::all();
$zones    = Booking::zones();
$techs    = \Glue\Auth::technicians();

// The span the grid covers. Month keeps its own anchor so paging a month and
// paging a week do not fight over the same parameter.
if ($view === 'month') {
    $fromTs = (int)strtotime(date('Y-m-01', (int)strtotime($month . '-01')));
    $toTs   = (int)strtotime(date('Y-m-t', $fromTs));
} elseif ($view === 'week') {
    $fromTs = (int)strtotime('monday this week', $anchorTs);
    $toTs   = (int)strtotime('+6 days', $fromTs);
} else {
    $fromTs = $toTs = $anchorTs;
}

// ---- the rows ---------------------------------------------------------------
$sql =
    'SELECT a.id, a.kind, a.type_code, a.title, a.location, a.`zone`, a.starts_at, a.ends_at,
            a.status, a.customer_name, a.customer_phone, a.contact_id, a.notes, a.agent_id,
            a.assist_request_id, a.install_report_id,
            u.full_name AS staff_name, u.username AS staff_username
       FROM appointments a
       LEFT JOIN users u ON u.id = a.agent_id
      WHERE a.starts_at BETWEEN ? AND ?
        AND a.status IN ("confirmed", "done")
        AND a.agent_id IS NOT NULL';
$args = [date('Y-m-d 00:00:00', $fromTs), date('Y-m-d 23:59:59', $toTs)];
if ($who)            { $sql .= ' AND a.agent_id = ?';  $args[] = $who; }
if ($typeF !== '')   { $sql .= ' AND a.type_code = ?'; $args[] = $typeF; }
if ($zoneF !== '')   { $sql .= ' AND a.`zone` = ?';    $args[] = $zoneF; }
$sql .= ' ORDER BY a.starts_at';
$q = $pdo->prepare($sql);
$q->execute($args);
$rows = $q->fetchAll() ?: [];

$byDay = [];
foreach ($rows as $r) {
    $byDay[date('Y-m-d', (int)strtotime((string)$r['starts_at']))][] = $r;
}
$pool = Booking::pool(100, $zoneF);

// ---- navigation links -------------------------------------------------------
$base = function (array $over = []) use ($view, $anchor, $month, $typeF, $zoneF, $who, $isAdminHere) {
    $p = ['tab' => 'calendar', 'v' => $view, 'd' => $anchor, 'm' => $month];
    if ($typeF !== '') { $p['ty'] = $typeF; }
    if ($zoneF !== '') { $p['z']  = $zoneF; }
    if ($isAdminHere && $who) { $p['u'] = $who; }
    return '?' . http_build_query(array_merge($p, $over));
};
// What every form posts back, so a save returns to this exact screen.
$backQs = http_build_query(array_filter([
    'v' => $view, 'd' => $anchor, 'm' => $month,
    'ty' => $typeF, 'z' => $zoneF, 'u' => $isAdminHere ? (string)$who : '',
], static fn($v) => $v !== '' && $v !== '0'));

$step = $view === 'month' ? 'month' : ($view === 'week' ? 'week' : 'day');
if ($view === 'month') {
    $prev = $base(['m' => date('Y-m', (int)strtotime('-1 month', (int)strtotime($month . '-01')))]);
    $next = $base(['m' => date('Y-m', (int)strtotime('+1 month', (int)strtotime($month . '-01')))]);
    $today = $base(['m' => date('Y-m')]);
} else {
    $prev = $base(['d' => date('Y-m-d', (int)strtotime("-1 $step", $anchorTs))]);
    $next = $base(['d' => date('Y-m-d', (int)strtotime("+1 $step", $anchorTs))]);
    $today = $base(['d' => date('Y-m-d')]);
}

$monthNames = $lang === 'it'
    ? ['gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre']
    : ['January','February','March','April','May','June','July','August','September','October','November','December'];
$dowNames = $lang === 'it'
    ? ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'] : ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$heading = match ($view) {
    'month' => $monthNames[(int)date('n', (int)strtotime($month . '-01')) - 1] . ' ' . date('Y', (int)strtotime($month . '-01')),
    'week'  => date('j', $fromTs) . '–' . date('j', $toTs) . ' ' . $monthNames[(int)date('n', $toTs) - 1] . ' ' . date('Y', $toTs),
    default => $dowNames[((int)date('N', $anchorTs)) - 1] . ' ' . date('j', $anchorTs) . ' '
               . $monthNames[(int)date('n', $anchorTs) - 1] . ' ' . date('Y', $anchorTs),
};

// The working window the day/week grids draw. Widened when something is booked
// outside it, so an early job is never invisible.
$hFrom = 7; $hTo = 20;
foreach ($rows as $r) {
    $hFrom = min($hFrom, (int)date('G', (int)strtotime((string)$r['starts_at'])));
    $hTo   = max($hTo, (int)date('G', (int)strtotime((string)($r['ends_at'] ?: $r['starts_at']))) + 1);
}
$hTo = min(24, max($hTo, $hFrom + 4));

$planDate   = date('Y-m-d', strtotime('+1 day'));
$planStatus = $uid ? DayPlanner::status((int)$uid, $planDate) : null;
?>
<style>
  .cal-top{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}
  .cal-top h3{margin:0;min-width:140px;text-transform:capitalize;font-size:17px}
  .cal-seg{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden}
  .cal-seg a{padding:5px 11px;font-size:13px;text-decoration:none;color:var(--txt)}
  .cal-seg a.on{background:var(--accent);color:#fff}
  .cal-filters{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
  .cal-chip{display:inline-flex;align-items:center;gap:5px;font-size:12px;padding:3px 9px;border-radius:999px;
            border:1px solid var(--line);text-decoration:none;color:var(--txt)}
  .cal-chip .dot{width:9px;height:9px;border-radius:50%;flex:0 0 auto}
  .cal-chip.on{border-color:var(--accent);box-shadow:inset 0 0 0 1px var(--accent)}

  /* month */
  .cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}
  .cal-dow{font-size:11px;text-transform:uppercase;letter-spacing:.04em;padding:4px 2px;text-align:center;color:var(--muted)}
  .cal-cell{min-height:94px;border:1px solid var(--line);border-radius:8px;padding:4px;overflow:hidden}
  .cal-cell.pad{border:0}
  .cal-cell.today{border-color:var(--accent);border-width:2px}
  .cal-cell.drop{background:color-mix(in srgb,var(--accent) 12%,transparent)}
  .cal-num{font-size:11px;font-weight:600;opacity:.6}

  /* day / week time grid */
  .cal-tg{display:grid;gap:0;border:1px solid var(--line);border-radius:10px;overflow:hidden}
  .cal-tg .hh{font-size:11px;color:var(--muted);padding:2px 6px;border-top:1px solid var(--line);text-align:right}
  .cal-col{position:relative;border-left:1px solid var(--line)}
  .cal-colhead{font-size:12px;text-align:center;padding:6px 2px;border-bottom:1px solid var(--line);position:sticky;top:0;
               background:var(--surface);z-index:2}
  .cal-colhead.today{color:var(--accent);font-weight:700}
  .cal-slot{border-top:1px solid var(--line);height:26px}
  .cal-slot.drop{background:color-mix(in srgb,var(--accent) 16%,transparent)}
  .cal-ev-abs{position:absolute;left:2px;right:2px;border-radius:6px;padding:2px 5px;font-size:11px;line-height:1.2;
              color:#fff;overflow:hidden;cursor:grab;z-index:3}
  .cal-ev{display:block;font-size:11px;line-height:1.25;margin-top:3px;padding:2px 5px;border-radius:5px;
          color:#fff;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:grab}
  .cal-more{font-size:10px;opacity:.6;margin-top:2px}

  /* pool */
  .pool-card{border:1px dashed var(--line);border-radius:10px;padding:12px 14px;margin-bottom:14px}
  .pool-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:7px 0;border-top:1px solid var(--line)}
  .pool-row:first-of-type{border-top:0}
  .pool-row .who{flex:1;min-width:180px}

  /* modal */
  .cm-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:60;align-items:center;justify-content:center;padding:16px}
  .cm-bg.show{display:flex}
  .cm-box{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:20px;
          width:min(540px,96vw);max-height:92dvh;overflow:auto}
  .cm-box h3{margin:0 0 14px;font-size:17px}
  .cm-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:6px}
  .ac-wrap{position:relative}
  .ac-list{position:absolute;left:0;right:0;top:100%;z-index:5;background:var(--surface);border:1px solid var(--line);
           border-radius:8px;margin-top:2px;max-height:230px;overflow:auto;display:none}
  .ac-list.show{display:block}
  .ac-item{padding:7px 10px;cursor:pointer;font-size:13px}
  .ac-item:hover,.ac-item.sel{background:var(--surface2)}
  .ac-item .sub{display:block;font-size:11px;color:var(--muted)}
  .ac-pick{display:flex;gap:8px;align-items:center;margin-top:6px;font-size:13px}
  .plan-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;border:1px solid var(--amber);border-radius:10px;
            padding:9px 13px;margin-bottom:14px;font-size:13px}
  .plan-bar.done{border-color:var(--green)}
  @media(max-width:720px){
    .cal-grid,.cal-dow,.cal-tg{display:none}
    .cal-agenda{display:block}
  }
  .cal-agenda{display:none}
</style>

<h2><?= $h($t('nav_calendar')) ?></h2>
<p class="muted small" style="margin:-6px 0 12px"><?= $h($t('cal_sub')) ?></p>

<?php // "Have you planned tomorrow?" — the same confirmation the 17:00 message
      // asks for, so someone already inside the CRM does not have to go and
      // find the WhatsApp to press it.
      if ($uid && ($isTech || $isAdminHere === false || $planStatus)): ?>
  <div class="plan-bar <?= $planStatus && $planStatus['confirmed_at'] ? 'done' : '' ?>">
    <?php if ($planStatus && $planStatus['confirmed_at']): ?>
      ✅ <?= $h(sprintf($t('plan_done'), date('d/m/Y', (int)strtotime($planDate)))) ?>
    <?php else: ?>
      📋 <?= $h(sprintf($t('plan_todo'), date('d/m/Y', (int)strtotime($planDate)))) ?>
      <form method="post" class="inline" style="margin-left:auto">
        <input type="hidden" name="do" value="plan_confirm">
        <input type="hidden" name="plan_date" value="<?= $h($planDate) ?>">
        <input type="hidden" name="back" value="<?= $h($backQs) ?>">
        <button class="btn tiny"><?= $h($t('plan_confirm_btn')) ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="cal-top">
  <a class="btn ghost tiny" href="<?= $h($prev) ?>">&larr;</a>
  <h3><?= $h($heading) ?></h3>
  <a class="btn ghost tiny" href="<?= $h($next) ?>">&rarr;</a>
  <a class="btn ghost tiny" href="<?= $h($today) ?>"><?= $h($t('cal_today')) ?></a>
  <span class="cal-seg">
    <?php foreach (['day' => 'cal_v_day', 'week' => 'cal_v_week', 'month' => 'cal_v_month'] as $v => $k): ?>
      <a class="<?= $view === $v ? 'on' : '' ?>" href="<?= $h($base(['v' => $v])) ?>"><?= $h($t($k)) ?></a>
    <?php endforeach; ?>
  </span>
  <span style="flex:1"></span>
  <button class="btn tiny" onclick="calNew()"><?= $h($t('cal_new')) ?></button>
</div>

<div class="cal-filters">
  <a class="cal-chip <?= $typeF === '' ? 'on' : '' ?>" href="<?= $h($base(['ty' => ''])) ?>"><?= $h($t('cal_kind_all')) ?></a>
  <?php foreach ($types as $code => $ty): ?>
    <a class="cal-chip <?= $typeF === $code ? 'on' : '' ?>" href="<?= $h($base(['ty' => $code])) ?>">
      <span class="dot" style="background:<?= $h($ty['color']) ?>"></span>
      <?= $h($lang === 'en' ? $ty['name_en'] : $ty['name_it']) ?></a>
  <?php endforeach; ?>
  <?php if ($zones): ?>
    <form method="get" class="inline" style="display:flex;gap:6px">
      <input type="hidden" name="tab" value="calendar"><input type="hidden" name="v" value="<?= $h($view) ?>">
      <input type="hidden" name="d" value="<?= $h($anchor) ?>"><input type="hidden" name="m" value="<?= $h($month) ?>">
      <?php if ($typeF !== ''): ?><input type="hidden" name="ty" value="<?= $h($typeF) ?>"><?php endif; ?>
      <?php if ($isAdminHere && $who): ?><input type="hidden" name="u" value="<?= (int)$who ?>"><?php endif; ?>
      <select name="z" onchange="this.form.submit()">
        <option value=""><?= $h($t('cal_all_zones')) ?></option>
        <?php foreach ($zones as $z): ?>
          <option value="<?= $h($z) ?>" <?= $zoneF === $z ? 'selected' : '' ?>><?= $h($z) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>
  <?php if ($isAdminHere): ?>
    <form method="get" class="inline" style="display:flex;gap:6px">
      <input type="hidden" name="tab" value="calendar"><input type="hidden" name="v" value="<?= $h($view) ?>">
      <input type="hidden" name="d" value="<?= $h($anchor) ?>"><input type="hidden" name="m" value="<?= $h($month) ?>">
      <?php if ($typeF !== ''): ?><input type="hidden" name="ty" value="<?= $h($typeF) ?>"><?php endif; ?>
      <?php if ($zoneF !== ''): ?><input type="hidden" name="z" value="<?= $h($zoneF) ?>"><?php endif; ?>
      <select name="u" onchange="this.form.submit()">
        <option value="0"><?= $h($t('cal_all_techs')) ?></option>
        <?php foreach ($agents as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= $who === (int)$a['id'] ? 'selected' : '' ?>>
            <?= $h($a['full_name'] ?: $a['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>
</div>

<?php // ---- the pool: booked, nobody driving to it yet ---------------------- ?>
<div class="pool-card">
  <b><?= $h($t('cal_pool_h')) ?></b>
  <span class="muted small">· <?= $h($t('cal_pool_sub')) ?></span>
  <?php if (!$pool): ?>
    <div class="muted small" style="margin-top:6px"><?= $h($t('cal_pool_none')) ?></div>
  <?php endif; ?>
  <?php foreach ($pool as $p): $ty = AppointmentTypes::find($p['type_code']); ?>
    <div class="pool-row">
      <span class="cal-chip" style="background:<?= $h(AppointmentTypes::color($p['type_code'])) ?>;color:#fff;border:0">
        <?= $h(AppointmentTypes::label($p['type_code'], $lang)) ?></span>
      <span class="who">
        <b><?= $h($p['customer_name'] ?: '—') ?></b>
        <div class="muted small">
          <?= $h(\Glue\Reminder\Templates::when((int)strtotime((string)$p['starts_at']), $lang, true)) ?>
          <?php if (!empty($p['zone'])): ?> · 📍 <?= $h($p['zone']) ?><?php endif; ?>
          <?php if (!empty($p['location'])): ?> · <?= $h($p['location']) ?><?php endif; ?>
        </div>
      </span>
      <form method="post" class="inline">
        <input type="hidden" name="do" value="cal_assign">
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="back" value="<?= $h($backQs) ?>">
        <?php if ($isAdminHere): ?>
          <select name="to_id" onchange="this.form.submit()">
            <option value=""><?= $h($t('cal_assign_to')) ?></option>
            <?php foreach ($techs as $tu): ?>
              <option value="<?= (int)$tu['id'] ?>"><?= $h($tu['full_name'] ?: $tu['username']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="hidden" name="to_id" value="<?= (int)$uid ?>">
          <button class="btn tiny"><?= $h($t('cal_take')) ?></button>
        <?php endif; ?>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<?php
// One renderer for an event chip, used by every view.
$evChip = function (array $r, bool $abs = false) use ($h, $lang, $backQs) {
    $ty    = AppointmentTypes::find($r['type_code']);
    $color = AppointmentTypes::color($r['type_code']);
    $startTs = (int)strtotime((string)$r['starts_at']);
    $mins  = \Glue\Crm\Booking::lengthOf($r);
    $title = trim((string)($r['customer_name'] ?? '')) ?: (string)$r['title'];
    $tip   = trim(AppointmentTypes::label($r['type_code'], $lang) . ' · ' . $title
             . ' · ' . date('H:i', $startTs)
             . (!empty($r['zone']) ? ' · ' . $r['zone'] : '')
             . (!empty($r['location']) ? ' · ' . $r['location'] : ''));
    $style = 'background:' . $h($color);
    if ($abs) {
        // 26px per half hour, measured from the grid's first drawn hour.
        $style .= ';top:' . $r['_top'] . 'px;height:' . max(18, (int)round($mins / 30 * 26) - 2) . 'px';
    }
    printf(
        '<div class="%s" style="%s" draggable="true" data-id="%d" data-min="%d"'
        . ' ondragstart="calDragStart(event)" onclick=\'calEdit(%s)\' title="%s">%s %s</div>',
        $abs ? 'cal-ev-abs' : 'cal-ev', $style, (int)$r['id'], $mins,
        $h(json_encode([
            'id' => (int)$r['id'], 'contact_id' => (int)$r['contact_id'],
            'name' => (string)$r['customer_name'], 'type' => (string)$r['type_code'],
            'at' => date('Y-m-d\TH:i', $startTs), 'min' => $mins,
            'zone' => (string)($r['zone'] ?? ''), 'loc' => (string)($r['location'] ?? ''),
            'title' => (string)($r['title'] ?? ''), 'notes' => (string)($r['notes'] ?? ''),
            'agent' => (int)($r['agent_id'] ?? 0),
            'install' => (int)($r['install_report_id'] ?? 0),
        ], JSON_UNESCAPED_UNICODE)),
        $h($tip), $h(date('H:i', $startTs)), $h(mb_substr($title, 0, 22))
    );
};
?>

<?php if ($view === 'month'): ?>
  <?php
    $mTs = (int)strtotime($month . '-01');
    $firstDow = ((int)date('N', $mTs)) - 1;
    $daysIn = (int)date('t', $mTs);
  ?>
  <div class="cal-grid">
    <?php foreach ($dowNames as $d): ?><div class="cal-dow"><?= $h($d) ?></div><?php endforeach; ?>
  </div>
  <div class="cal-grid">
    <?php for ($i = 0; $i < $firstDow; $i++): ?><div class="cal-cell pad"></div><?php endfor; ?>
    <?php for ($d = 1; $d <= $daysIn; $d++):
        $date = date('Y-m-', $mTs) . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
        $evs = $byDay[$date] ?? []; ?>
      <div class="cal-cell<?= $date === date('Y-m-d') ? ' today' : '' ?>" data-date="<?= $h($date) ?>"
           ondragover="calOver(event)" ondragleave="calLeave(event)" ondrop="calDrop(event)">
        <div class="cal-num"><?= $d ?></div>
        <?php foreach (array_slice($evs, 0, 3) as $r) { $evChip($r); } ?>
        <?php if (count($evs) > 3): ?><div class="cal-more">+<?= count($evs) - 3 ?></div><?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
<?php else: ?>
  <?php
    $days = [];
    for ($ts = $fromTs; $ts <= $toTs; $ts = (int)strtotime('+1 day', $ts)) {
        $days[] = $ts;
    }
    $cols = count($days);
  ?>
  <div class="cal-tg" style="grid-template-columns:52px repeat(<?= $cols ?>,minmax(0,1fr))">
    <div class="cal-colhead"></div>
    <?php foreach ($days as $ts): $isToday = date('Y-m-d', $ts) === date('Y-m-d'); ?>
      <div class="cal-colhead <?= $isToday ? 'today' : '' ?>">
        <?= $h($dowNames[((int)date('N', $ts)) - 1]) ?> <?= (int)date('j', $ts) ?></div>
    <?php endforeach; ?>

    <div>
      <?php for ($hh = $hFrom; $hh < $hTo; $hh++): ?>
        <div class="hh" style="height:52px"><?= str_pad((string)$hh, 2, '0', STR_PAD_LEFT) ?>:00</div>
      <?php endfor; ?>
    </div>
    <?php foreach ($days as $ts): $date = date('Y-m-d', $ts); ?>
      <div class="cal-col">
        <?php for ($hh = $hFrom; $hh < $hTo; $hh++): foreach ([0, 30] as $mm): ?>
          <div class="cal-slot" data-date="<?= $h($date) ?>"
               data-time="<?= str_pad((string)$hh, 2, '0', STR_PAD_LEFT) ?>:<?= $mm === 0 ? '00' : '30' ?>"
               ondragover="calOver(event)" ondragleave="calLeave(event)" ondrop="calDrop(event)"
               ondblclick="calNew('<?= $h($date) ?>T<?= str_pad((string)$hh, 2, '0', STR_PAD_LEFT) ?>:<?= $mm === 0 ? '00' : '30' ?>')"></div>
        <?php endforeach; endfor; ?>
        <?php foreach ($byDay[$date] ?? [] as $r):
            $st = (int)strtotime((string)$r['starts_at']);
            // 26px per half hour; the column's own top is the first drawn hour.
            $r['_top'] = (int)round((((int)date('G', $st) - $hFrom) * 60 + (int)date('i', $st)) / 30 * 26) + 1;
            if ($r['_top'] < 0) { $r['_top'] = 1; }
            $evChip($r, true);
        endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php // A phone gets the list; seven columns at 390px are unreadable. ?>
<div class="cal-agenda">
  <?php if (!$rows): ?><div class="empty"><?= $h($t('cal_empty')) ?></div><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <div class="pool-row">
      <span class="cal-chip" style="background:<?= $h(AppointmentTypes::color($r['type_code'])) ?>;color:#fff;border:0">
        <?= $h(AppointmentTypes::label($r['type_code'], $lang)) ?></span>
      <span class="who"><b><?= $h($r['customer_name'] ?: $r['title']) ?></b>
        <div class="muted small">
          <?= $h(\Glue\Reminder\Templates::when((int)strtotime((string)$r['starts_at']), $lang, true)) ?>
          <?php if (!empty($r['zone'])): ?> · 📍 <?= $h($r['zone']) ?><?php endif; ?>
          <?php if (!$who): ?> · <?= $h($r['staff_name'] ?: $r['staff_username']) ?><?php endif; ?>
        </div></span>
    </div>
  <?php endforeach; ?>
</div>

<?php // ---- the booking form ------------------------------------------------ ?>
<div class="cm-bg" id="calBg" onclick="if(event.target===this)calClose()">
  <div class="cm-box">
    <h3 id="calTitle"><?= $h($t('cal_new')) ?></h3>
    <form method="post" id="calForm">
      <input type="hidden" name="do" value="cal_save">
      <input type="hidden" name="id" id="cfId" value="0">
      <input type="hidden" name="back" value="<?= $h($backQs) ?>">
      <input type="hidden" name="contact_id" id="cfContact" value="">

      <label class="fld"><span><?= $h($t('cal_customer')) ?> *</span>
        <div class="ac-wrap">
          <input id="cfSearch" autocomplete="off" placeholder="<?= $h($t('cal_customer_ph')) ?>"
                 oninput="acType()" onkeydown="acKey(event)">
          <div class="ac-list" id="acList"></div>
        </div>
      </label>
      <div class="ac-pick">
        <span id="cfPicked" class="muted"><?= $h($t('cal_no_customer')) ?></span>
        <button type="button" class="btn ghost tiny" onclick="ncOpen()">+ <?= $h($t('cal_new_customer')) ?></button>
      </div>

      <div class="row" style="margin-top:12px">
        <label class="fld"><span><?= $h($t('cal_type')) ?></span>
          <select name="type_code" id="cfType" onchange="calTypeHint()">
            <?php foreach ($types as $code => $ty): ?>
              <option value="<?= $h($code) ?>" data-install="<?= (int)$ty['opens_install_form'] ?>">
                <?= $h($lang === 'en' ? $ty['name_en'] : $ty['name_it']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="fld"><span><?= $h($t('iv_when')) ?> *</span>
          <input type="datetime-local" name="starts_at" id="cfAt" required></label>
        <label class="fld"><span><?= $h($t('iv_duration')) ?></span>
          <input type="number" name="duration_min" id="cfMin" min="15" max="480" step="15" value="60"></label>
      </div>
      <p class="muted small" id="cfInstallHint" style="display:none;margin:-4px 0 10px">
        <?= $h($t('cal_install_hint')) ?></p>

      <div class="row">
        <label class="fld"><span><?= $h($t('cal_zone')) ?> *</span>
          <input name="zone" id="cfZone" list="cfZones" required>
          <datalist id="cfZones"><?php foreach ($zones as $z): ?><option value="<?= $h($z) ?>"><?php endforeach; ?></datalist>
        </label>
        <label class="fld"><span><?= $h($t('iv_where')) ?></span><input name="location" id="cfLoc"></label>
      </div>
      <?php if ($isAdminHere): ?>
        <label class="fld"><span><?= $h($t('iv_tech')) ?></span>
          <select name="agent_id" id="cfAgent">
            <option value="0"><?= $h($t('cal_leave_pool')) ?></option>
            <?php foreach ($techs as $tu): ?>
              <option value="<?= (int)$tu['id'] ?>"><?= $h($tu['full_name'] ?: $tu['username']) ?></option>
            <?php endforeach; ?>
          </select></label>
      <?php endif; ?>
      <label class="fld"><span><?= $h($t('f_title')) ?></span><input name="title" id="cfTitle2"></label>
      <label class="fld"><span><?= $h($t('iv_notes')) ?></span><textarea name="notes" id="cfNotes" rows="2"></textarea></label>
      <p class="muted small" id="cfInstallLink" style="display:none"></p>

      <div class="cm-foot">
        <button type="button" class="btn ghost tiny" onclick="calClose()"><?= $h($t('cancel')) ?></button>
        <button class="btn"><?= $h($t('save')) ?></button>
      </div>
    </form>
  </div>
</div>

<?php // ---- "+ nuovo cliente", over the top of the booking form -------------- ?>
<div class="cm-bg" id="ncBg" onclick="if(event.target===this)ncClose()">
  <div class="cm-box">
    <h3><?= $h($t('cal_new_customer')) ?></h3>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_first_name')) ?></span><input id="ncFirst"></label>
      <label class="fld"><span><?= $h($t('f_last_name')) ?></span><input id="ncLast"></label>
    </div>
    <label class="fld"><span><?= $h($t('f_company')) ?></span><input id="ncCompany"></label>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input id="ncPhone"></label>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input id="ncEmail"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('cu_vat')) ?></span><input id="ncVat"></label>
      <label class="fld"><span><?= $h($t('cal_zone')) ?></span><input id="ncCity"></label>
    </div>
    <label class="fld"><span><?= $h($t('iv_where')) ?></span><input id="ncAddress"></label>
    <p class="muted small" id="ncErr" style="color:var(--red);display:none"></p>
    <div class="cm-foot">
      <button type="button" class="btn ghost tiny" onclick="ncClose()"><?= $h($t('cancel')) ?></button>
      <button type="button" class="btn" id="ncSave" onclick="ncSave()"><?= $h($t('save')) ?></button>
    </div>
  </div>
</div>

<?php if ($uid): ?>
<details class="drawer" style="margin-top:22px">
  <summary class="btn ghost"><?= $h($t('cal_feed_h')) ?></summary>
  <div class="card" style="margin-top:10px">
    <p class="muted small" style="margin-top:0"><?= $h($t('cal_feed_hint')) ?></p>
    <?php foreach (['all' => 'cal_feed_all', 'intervention' => 'cal_feed_support'] as $fk => $fLabel): ?>
      <label class="fld" style="margin-bottom:8px"><span><?= $h($t($fLabel)) ?></span>
        <input readonly onclick="this.select()" style="width:100%;font-family:monospace;font-size:12px"
               value="<?= $h(Calendar::feedUrl((int)$uid, $fk)) ?>"></label>
    <?php endforeach; ?>
    <p class="muted small" style="margin:0 0 4px"><?= $h($t('cal_feed_pick')) ?></p>
    <form method="post" style="margin-top:10px" onsubmit="return confirm('<?= $h($t('cal_feed_reset_c')) ?>')">
      <input type="hidden" name="do" value="cal_feed_reset">
      <button class="btn ghost tiny"><?= $h($t('cal_feed_reset')) ?></button>
    </form>
  </div>
</details>
<?php endif; ?>

<script>
// ---- the customer type-ahead ------------------------------------------------
let acTimer = null, acRows = [], acSel = -1;
function acType() {
  clearTimeout(acTimer);
  const q = document.getElementById('cfSearch').value.trim();
  if (q.length < 2) { acHide(); return; }
  // One request per pause, not per keystroke: the office types a full company
  // name and every letter would otherwise be a query.
  acTimer = setTimeout(() => {
    fetch('?find=contacts&q=' + encodeURIComponent(q))
      .then(r => r.json()).then(rows => { acRows = rows || []; acDraw(); })
      .catch(() => acHide());
  }, 220);
}
function acDraw() {
  const box = document.getElementById('acList');
  if (!acRows.length) { acHide(); return; }
  acSel = -1;
  box.innerHTML = acRows.map((r, i) =>
    '<div class="ac-item" data-i="' + i + '" onclick="acPick(' + i + ')">' +
    (r.is_customer ? '👤 ' : '') + esc(r.label) +
    (r.sub ? '<span class="sub">' + esc(r.sub) + '</span>' : '') + '</div>').join('');
  box.classList.add('show');
}
function acHide() { document.getElementById('acList').classList.remove('show'); }
function acKey(e) {
  if (!acRows.length) return;
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    acSel = Math.max(0, Math.min(acRows.length - 1, acSel + (e.key === 'ArrowDown' ? 1 : -1)));
    document.querySelectorAll('.ac-item').forEach(el =>
      el.classList.toggle('sel', +el.dataset.i === acSel));
  } else if (e.key === 'Enter' && acSel >= 0) {
    e.preventDefault(); acPick(acSel);
  } else if (e.key === 'Escape') { acHide(); }
}
function acPick(i) {
  const r = acRows[i];
  document.getElementById('cfContact').value = r.id;
  document.getElementById('cfPicked').textContent = '✓ ' + r.label + (r.sub ? ' — ' + r.sub : '');
  document.getElementById('cfPicked').classList.remove('muted');
  document.getElementById('cfSearch').value = r.label;
  acHide();
}
function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

// ---- the booking form -------------------------------------------------------
function calNew(at) {
  document.getElementById('calForm').reset();
  document.getElementById('cfId').value = 0;
  document.getElementById('cfContact').value = '';
  document.getElementById('cfSearch').value = '';
  document.getElementById('cfPicked').textContent = <?= json_encode($t('cal_no_customer')) ?>;
  document.getElementById('cfPicked').classList.add('muted');
  document.getElementById('cfAt').value = at || defaultSlot();
  document.getElementById('cfMin').value = 60;
  document.getElementById('calTitle').textContent = <?= json_encode($t('cal_new')) ?>;
  document.getElementById('cfInstallLink').style.display = 'none';
  calTypeHint();
  document.getElementById('calBg').classList.add('show');
  document.getElementById('cfSearch').focus();
}
function calEdit(d) {
  calNew(d.at);
  document.getElementById('cfId').value = d.id;
  document.getElementById('cfContact').value = d.contact_id;
  document.getElementById('cfSearch').value = d.name || '';
  document.getElementById('cfPicked').textContent = '✓ ' + (d.name || '#' + d.contact_id);
  document.getElementById('cfPicked').classList.remove('muted');
  document.getElementById('cfType').value = d.type;
  document.getElementById('cfMin').value = d.min;
  document.getElementById('cfZone').value = d.zone || '';
  document.getElementById('cfLoc').value = d.loc || '';
  document.getElementById('cfTitle2').value = d.title || '';
  document.getElementById('cfNotes').value = d.notes || '';
  const ag = document.getElementById('cfAgent');
  if (ag) ag.value = d.agent || 0;
  document.getElementById('calTitle').textContent = <?= json_encode($t('cal_edit')) ?>;
  if (d.install) {
    const p = document.getElementById('cfInstallLink');
    p.innerHTML = '<a href="?tab=installations&id=' + d.install + '">' +
      <?= json_encode($t('cal_open_install')) ?> + '</a>';
    p.style.display = '';
  }
  calTypeHint();
}
function calClose() { document.getElementById('calBg').classList.remove('show'); }
function calTypeHint() {
  const o = document.getElementById('cfType').selectedOptions[0];
  document.getElementById('cfInstallHint').style.display =
    (o && o.dataset.install === '1') ? '' : 'none';
}
function defaultSlot() {
  // Tomorrow at 09:00 — the slot a planner reaches for far more often than now.
  const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0);
  const p = n => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T09:00';
}
document.getElementById('calForm').addEventListener('submit', function (e) {
  if (!document.getElementById('cfContact').value) {
    e.preventDefault();
    alert(<?= json_encode($t('cal_err_customer')) ?>);
  }
});

// ---- "+ nuovo cliente" ------------------------------------------------------
function ncOpen() { document.getElementById('ncErr').style.display = 'none'; document.getElementById('ncBg').classList.add('show'); }
function ncClose() { document.getElementById('ncBg').classList.remove('show'); }
function ncSave() {
  const btn = document.getElementById('ncSave'); btn.disabled = true;
  const b = new URLSearchParams({
    do: 'cal_newcust', ajax: '1',
    first_name: val('ncFirst'), last_name: val('ncLast'), company: val('ncCompany'),
    phone: val('ncPhone'), email: val('ncEmail'), vat_number: val('ncVat'),
    city: val('ncCity'), address: val('ncAddress'),
  });
  fetch('', { method: 'POST', body: b, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
    .then(r => r.json())
    .then(res => {
      btn.disabled = false;
      if (!res.ok) { const e = document.getElementById('ncErr'); e.textContent = res.error || 'error'; e.style.display = ''; return; }
      // Straight back into the appointment being written, already linked.
      acRows = [{ id: res.id, label: res.label, sub: '', is_customer: true }];
      acPick(0);
      if (!val('cfZone') && val('ncCity')) document.getElementById('cfZone').value = val('ncCity');
      if (!val('cfLoc') && val('ncAddress')) document.getElementById('cfLoc').value = val('ncAddress');
      ncClose();
    })
    .catch(() => { btn.disabled = false; });
}
function val(id) { const e = document.getElementById(id); return e ? e.value.trim() : ''; }

// ---- drag to move -----------------------------------------------------------
let dragId = 0, dragMin = 60;
function calDragStart(e) {
  dragId = +e.currentTarget.dataset.id; dragMin = +e.currentTarget.dataset.min || 60;
  e.dataTransfer.effectAllowed = 'move';
  try { e.dataTransfer.setData('text/plain', String(dragId)); } catch (_) {}
}
function calOver(e) { if (dragId) { e.preventDefault(); e.currentTarget.classList.add('drop'); } }
function calLeave(e) { e.currentTarget.classList.remove('drop'); }
function calDrop(e) {
  e.preventDefault(); e.currentTarget.classList.remove('drop');
  if (!dragId) return;
  const cell = e.currentTarget;
  // A month cell moves the day and keeps the hour; a half-hour slot sets both.
  const at = cell.dataset.time
    ? cell.dataset.date + 'T' + cell.dataset.time
    : cell.dataset.date + 'T' + (dragTime() || '09:00');
  const b = new URLSearchParams({ do: 'cal_move', ajax: '1', id: String(dragId), starts_at: at, duration_min: String(dragMin) });
  fetch('', { method: 'POST', body: b, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
    .then(r => r.json())
    .then(res => { if (res.ok) location.reload(); else alert(<?= json_encode($t('not_allowed')) ?>); })
    .catch(() => {});
  dragId = 0;
}
function dragTime() {
  const el = document.querySelector('[data-id="' + dragId + '"]');
  const m = el && el.title.match(/(\d{2}:\d{2})/);
  return m ? m[1] : null;
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') { calClose(); ncClose(); } });
<?php if (!empty($_GET['new']) && !empty($_GET['contact'])): ?>
// Arrived from a customer's page: open the form with them already chosen.
window.addEventListener('DOMContentLoaded', () => {
  calNew();
  document.getElementById('cfContact').value = <?= (int)$_GET['contact'] ?>;
  document.getElementById('cfSearch').value = <?= json_encode((string)($_GET['cname'] ?? '')) ?>;
  document.getElementById('cfPicked').textContent = '✓ ' + <?= json_encode((string)($_GET['cname'] ?? '')) ?>;
  document.getElementById('cfPicked').classList.remove('muted');
});
<?php endif; ?>
</script>
