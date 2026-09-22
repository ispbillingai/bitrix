<?php
/**
 * Calendar — the CRM's own diary, month by month.
 *
 * Deliberately not a Google Calendar embed: everything here already lives in
 * the appointments table, the permissions are the CRM's own, and nobody has to
 * hand over an account. What a phone needs, it gets from the .ics feed at the
 * bottom of the page — subscribe once, and the same diary rides along in
 * whatever calendar app the person already uses.
 *
 * In scope: $t, $h, $lang, $uid, $isAgent, $isTech, $agents.
 */

use Glue\Crm\Calendar;
use Glue\Crm\Interventions;

$isAdminHere = !$isAgent && !$isTech;

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
$kind  = in_array($_GET['k'] ?? '', ['intervention', 'sales'], true) ? (string)$_GET['k'] : 'all';
// Staff filter: the office may look at anyone, everyone else only at themselves.
$who = $isAdminHere ? (int)($_GET['u'] ?? 0) : (int)$uid;

$monthTs = (int)strtotime($month . '-01');
$byDay   = Calendar::byDay($month, $kind, $who ?: null);
$upcoming = Calendar::month(date('Y-m', $monthTs), $kind, $who ?: null);

$prev = date('Y-m', (int)strtotime('-1 month', $monthTs));
$next = date('Y-m', (int)strtotime('+1 month', $monthTs));
$qs = fn(array $over = []) => '?tab=calendar&' . http_build_query(
    array_merge(['m' => $month, 'k' => $kind] + ($isAdminHere ? ['u' => $who] : []), $over));

// Monday-first, the way an Italian wall calendar reads.
$firstDow = ((int)date('N', $monthTs)) - 1;
$daysIn   = (int)date('t', $monthTs);
$dowNames = $lang === 'it'
    ? ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom']
    : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
// Templates::when() always writes a whole date; a month heading wants the month
// and the year alone, so the names are spelled out here.
$monthNames = $lang === 'it'
    ? ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio',
       'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre']
    : ['January', 'February', 'March', 'April', 'May', 'June', 'July',
       'August', 'September', 'October', 'November', 'December'];
$monthLabel = $monthNames[(int)date('n', $monthTs) - 1] . ' ' . date('Y', $monthTs);
?>
<style>
  .cal-top{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px}
  .cal-top h3{margin:0;min-width:150px;text-transform:capitalize}
  .cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}
  .cal-dow{font-size:11px;text-transform:uppercase;letter-spacing:.04em;padding:4px 2px;text-align:center}
  .cal-cell{min-height:92px;border:1px solid var(--line,#e5e5e5);border-radius:8px;padding:4px;overflow:hidden}
  .cal-cell.pad{border:0}
  .cal-cell.today{border-color:var(--accent);border-width:2px}
  .cal-num{font-size:11px;font-weight:600;opacity:.6}
  .cal-ev{display:block;font-size:11px;line-height:1.25;margin-top:3px;padding:2px 4px;border-radius:4px;
          background:var(--chip,#f1f1f1);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .cal-ev.iv{border-left:3px solid var(--green)}
  .cal-ev.sl{border-left:3px solid var(--accent)}
  .cal-more{font-size:10px;opacity:.6;margin-top:2px}
  /* On a phone seven columns are unreadable, and the agenda below says the same
     thing better. The grid is the desktop view only. */
  @media (max-width:720px){ .cal-grid,.cal-dow{display:none} }
</style>

<h2><?= $h($t('nav_calendar')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('cal_sub')) ?></p>

<div class="cal-top">
  <a class="btn ghost tiny" href="<?= $h($qs(['m' => $prev])) ?>" title="<?= $h($t('cal_prev')) ?>">&larr;</a>
  <h3><?= $h($monthLabel) ?></h3>
  <a class="btn ghost tiny" href="<?= $h($qs(['m' => $next])) ?>" title="<?= $h($t('cal_next')) ?>">&rarr;</a>
  <a class="btn ghost tiny" href="<?= $h($qs(['m' => date('Y-m')])) ?>"><?= $h($t('cal_today')) ?></a>
  <span style="flex:1"></span>
  <form method="get" class="inline" style="display:flex;gap:6px;flex-wrap:wrap">
    <input type="hidden" name="tab" value="calendar">
    <input type="hidden" name="m" value="<?= $h($month) ?>">
    <select name="k" onchange="this.form.submit()">
      <option value="all" <?= $kind === 'all' ? 'selected' : '' ?>><?= $h($t('cal_kind_all')) ?></option>
      <option value="intervention" <?= $kind === 'intervention' ? 'selected' : '' ?>><?= $h($t('cal_kind_interv')) ?></option>
      <option value="sales" <?= $kind === 'sales' ? 'selected' : '' ?>><?= $h($t('cal_kind_sales')) ?></option>
    </select>
    <?php if ($isAdminHere): ?>
      <select name="u" onchange="this.form.submit()">
        <option value="0"><?= $h($t('cal_all_techs')) ?></option>
        <?php foreach ($agents as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= $who === (int)$a['id'] ? 'selected' : '' ?>>
            <?= $h($a['full_name'] ?: $a['username']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </form>
</div>

<div class="cal-dow cal-grid">
  <?php foreach ($dowNames as $d): ?><div class="cal-dow"><?= $h($d) ?></div><?php endforeach; ?>
</div>
<div class="cal-grid">
  <?php for ($i = 0; $i < $firstDow; $i++): ?><div class="cal-cell pad"></div><?php endfor; ?>
  <?php for ($d = 1; $d <= $daysIn; $d++):
      $date = date('Y-m-', $monthTs) . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
      $evs  = $byDay[$date] ?? []; ?>
    <div class="cal-cell<?= $date === date('Y-m-d') ? ' today' : '' ?>">
      <div class="cal-num"><?= $d ?></div>
      <?php foreach (array_slice($evs, 0, 3) as $e):
          $isIv = (string)$e['kind'] === Interventions::KIND;
          $href = $isIv && !empty($e['assist_request_id']) ? '?tab=support' : '?tab=appointments'; ?>
        <a class="cal-ev <?= $isIv ? 'iv' : 'sl' ?>" href="<?= $h($href) ?>"
           title="<?= $h(trim(($e['customer_name'] ?? '') . ' — ' . ($e['title'] ?? '') . ' ' . ($e['location'] ?? ''))) ?>">
          <?= $h(date('H:i', strtotime((string)$e['starts_at']))) ?>
          <?= $h($e['customer_name'] ?: $e['title']) ?></a>
      <?php endforeach; ?>
      <?php if (count($evs) > 3): ?>
        <div class="cal-more">+<?= count($evs) - 3 ?></div>
      <?php endif; ?>
    </div>
  <?php endfor; ?>
</div>

<h3 style="margin:22px 0 8px"><?= $h($t('cal_list_h')) ?></h3>
<?php if (!$upcoming): ?>
  <div class="empty"><?= $h($t('cal_empty')) ?></div>
<?php else: ?>
  <table><thead><tr>
    <th><?= $h($t('iv_when')) ?></th><th><?= $h($t('th_customer')) ?></th>
    <th><?= $h($t('iv_where')) ?></th><th><?= $h($t('iv_tech')) ?></th><th><?= $h($t('th_status')) ?></th>
  </tr></thead><tbody>
  <?php foreach ($upcoming as $e):
      $isIv = (string)$e['kind'] === Interventions::KIND; ?>
    <tr>
      <td style="white-space:nowrap"><?= $isIv ? '🔧' : '📅' ?>
        <?= $h(\Glue\Reminder\Templates::when((int)strtotime((string)$e['starts_at']), $lang, true)) ?></td>
      <td><b><?= $h($e['customer_name'] ?: '—') ?></b>
        <?php if (!empty($e['title'])): ?><div class="muted small"><?= $h($e['title']) ?></div><?php endif; ?></td>
      <td class="small"><?= $h($e['location'] ?: '—') ?></td>
      <td class="small"><?= $h($e['staff_name'] ?: ($e['staff_username'] ?: '—')) ?></td>
      <td><?= pill($h, (string)$e['status'], $t) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
<?php endif; ?>

<?php if ($uid): // the master-password admin has no user row to hang a token on ?>
<details class="drawer" style="margin-top:22px">
  <summary class="btn ghost"><?= $h($t('cal_feed_h')) ?></summary>
  <div class="card" style="margin-top:10px">
    <p class="muted small" style="margin-top:0"><?= $h($t('cal_feed_hint')) ?></p>
    <?php /* Two addresses, not one: a phone shows each subscribed calendar as
             its own layer, so support sessions can carry their own colour and be
             silenced on a day off. Subscribe to one OR the other — both together
             and every visit appears twice. */
          foreach (['all' => 'cal_feed_all', 'intervention' => 'cal_feed_support'] as $fk => $fLabel): ?>
      <label class="fld" style="margin-bottom:8px">
        <span><?= $h($t($fLabel)) ?></span>
        <input readonly onclick="this.select()" style="width:100%;font-family:monospace;font-size:12px"
               value="<?= $h(Calendar::feedUrl((int)$uid, $fk)) ?>">
      </label>
    <?php endforeach; ?>
    <p class="muted small" style="margin:0 0 4px"><?= $h($t('cal_feed_pick')) ?></p>
    <form method="post" style="margin-top:10px"
          onsubmit="return confirm('<?= $h($t('cal_feed_reset_c')) ?>')">
      <input type="hidden" name="do" value="cal_feed_reset">
      <button class="btn ghost tiny"><?= $h($t('cal_feed_reset')) ?></button>
    </form>
  </div>
</details>
<?php endif; ?>
