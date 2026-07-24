<?php
/**
 * Invoices — who owes money, and chasing them for it.
 *
 * The page is customer-first: one row per customer with something outstanding,
 * worst debt at the top, because "does this customer owe us anything?" is the
 * question people open it to answer. Opening a customer shows their invoices and
 * the controls for chasing them; the flat invoice list is a second tab.
 *
 * Sibill holds no phone or email for a customer, so a debtor can be unreachable
 * until someone types a number in. The page says so out loud — an unreachable
 * debtor is not silently skipped, it is a filter of its own.
 *
 * Admin-only. In scope: $t, $h, $pdo, $cfg, $money.
 */
use Glue\Sibill\Client;
use Glue\Sibill\Customers;
use Glue\Sibill\Invoices;

$configured = Client::configured();
$view       = (string)($_GET['v'] ?? 'customers');   // customers | invoices
$filter     = (string)($_GET['f'] ?? ($view === 'customers' ? 'overdue' : 'overdue'));
$q          = trim((string)($_GET['q'] ?? ''));
$openCust   = (int)($_GET['c'] ?? 0);
$openInv    = (int)($_GET['inv'] ?? 0);

$lastSync   = Invoices::lastSyncAt();
$chaseOn    = (bool)$cfg('sibill.chase_enabled', false);
$today      = date('Y-m-d');

// Accounting figures keep their cents, unlike $money() which rounds for tiles.
$eur = fn($n, $cur = 'EUR') => $h($cur . ' ' . number_format((float)$n, 2, ',', '.'));
$plain = fn($n) => number_format((float)$n, 2, ',', '.');

$stateColor = [
    'paid' => 'var(--green)', 'partial' => 'var(--amber)',
    'unpaid' => 'var(--muted)', 'unknown' => 'var(--muted)',
];
// Link back to this page keeping the tab/filter/search the user is on.
// Overrides are merged BEFORE filtering, so passing null for a key (closing an
// expanded row) drops it from the URL rather than emitting "&c=".
$here = function (array $over = []) use ($view, $filter, $q): string {
    $p = array_merge(['tab' => 'invoices', 'v' => $view, 'f' => $filter, 'q' => $q], $over);
    return '?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null));
};
?>
<h2><?= $h($t('nav_invoices')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('inv_sub')) ?></p>

<?php if (!$configured): ?>
  <div class="card">
    <b><?= $h($t('inv_not_set')) ?></b>
    <p class="muted small" style="margin:8px 0 0"><?= $h($t('inv_not_set_h')) ?></p>
    <a class="btn ghost tiny" style="margin-top:10px" href="?tab=settings"><?= $h($t('nav_settings')) ?></a>
  </div>
<?php else: ?>

  <?php $cs = Customers::summary(); $isum = Invoices::summary(); ?>
  <div class="grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:18px">
    <?php
    num_card($h, 'users', $t('inv_debtors'), $cs['debtors'],
        str_replace('{n}', (string)$cs['reachable'], $t('inv_reachable_n')));
    num_card($h, 'alert', $t('inv_overdue'), (int)($isum['overdue'] ?? 0), 'EUR ' . $plain($isum['overdue_total'] ?? 0));
    num_card($h, 'clock', $t('inv_open'), (int)(($isum['total'] ?? 0) - ($isum['paid'] ?? 0)), 'EUR ' . $plain($isum['open_total'] ?? 0));
    num_card($h, 'check', $t('inv_paid'), (int)($isum['paid'] ?? 0));
    ?>
  </div>

  <?php // Chasing is the thing most likely to be mis-set, so its state is on the page. ?>
  <div class="card" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
    <div class="small">
      <span class="pill" style="color:<?= $chaseOn ? 'var(--green)' : 'var(--muted)' ?>">
        <?= $h($chaseOn ? $t('inv_chase_on') : $t('inv_chase_off')) ?></span>
      <span class="muted" style="margin-left:8px">
        <?php if ($chaseOn): ?>
          <?= $h(str_replace(['{d}', '{l}', '{a}'], [
              (string)(int)$cfg('sibill.chase_every_days', 7),
              (string)(int)$cfg('sibill.chase_min_days_late', 7),
              $plain($cfg('sibill.chase_min_amount', 20)),
          ], $t('inv_chase_rule'))) ?>
          <?php if (($cf = trim((string)$cfg('sibill.chase_from_date', ''))) !== ''): ?>
            · <?= $h(str_replace('{date}', $cf, $t('inv_chase_from'))) ?>
          <?php endif; ?>
        <?php else: ?>
          <?= $h($t('inv_chase_off_h')) ?>
        <?php endif; ?>
      </span>
      <div class="muted" style="margin-top:5px">
        <?= $h($t('inv_last_sync')) ?>: <b><?= $h($lastSync ?: $t('inv_never')) ?></b>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" class="inline" style="margin:0">
        <input type="hidden" name="do" value="sibill_sync">
        <button class="btn ghost tiny"><?= $h($t('inv_sync_now')) ?></button>
      </form>
      <a class="btn ghost tiny" href="?tab=settings"><?= $h($t('inv_chase_settings')) ?></a>
    </div>
  </div>

  <div class="tabs">
    <a class="<?= $view === 'customers' ? 'on' : '' ?>" href="?tab=invoices&v=customers"><?= $h($t('inv_v_customers')) ?></a>
    <a class="<?= $view === 'invoices' ? 'on' : '' ?>" href="?tab=invoices&v=invoices"><?= $h($t('inv_v_invoices')) ?></a>
  </div>

  <div class="tabs">
    <?php
    $tabs = $view === 'customers'
      ? ['overdue' => 'inv_cf_overdue', 'owing' => 'inv_cf_owing', 'unreachable' => 'inv_cf_unreachable',
         'reachable' => 'inv_cf_reachable', 'all' => 'inv_cf_all']
      : ['overdue' => 'inv_f_overdue', 'open' => 'inv_f_open', 'partial' => 'inv_f_partial',
         'paid' => 'inv_f_paid', 'unknown' => 'inv_f_unknown', '' => 'inv_f_all'];
    foreach ($tabs as $key => $label): ?>
      <a class="<?= $filter === $key ? 'on' : '' ?>"
         href="?tab=invoices&v=<?= $h($view) ?>&f=<?= $h($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= $h($t($label)) ?></a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="inline" style="margin-bottom:12px">
    <input type="hidden" name="tab" value="invoices">
    <input type="hidden" name="v" value="<?= $h($view) ?>">
    <input type="hidden" name="f" value="<?= $h($filter) ?>">
    <input name="q" value="<?= $h($q) ?>" placeholder="<?= $h($view === 'customers' ? $t('inv_csearch_ph') : $t('inv_search_ph')) ?>" style="max-width:280px">
    <button class="btn ghost tiny"><?= $h($t('inv_search')) ?></button>
    <?php if ($q !== ''): ?>
      <a class="btn ghost tiny" href="?tab=invoices&v=<?= $h($view) ?>&f=<?= $h($filter) ?>"><?= $h($t('clear')) ?></a>
    <?php endif; ?>
  </form>

<?php if ($view === 'customers'): /* ---------------- customer list ---------------- */ ?>

  <?php $custs = Customers::search(['state' => $filter, 'q' => $q]); ?>
  <table><thead><tr>
    <th><?= $h($t('inv_th_customer')) ?></th>
    <th><?= $h($t('inv_th_owed')) ?></th>
    <th><?= $h($t('inv_th_unpaid_n')) ?></th>
    <th><?= $h($t('inv_th_oldest')) ?></th>
    <th><?= $h($t('inv_th_reach')) ?></th>
    <th><?= $h($t('inv_th_chase')) ?></th>
    <th></th>
  </tr></thead><tbody>
  <?php if (!$custs): ?>
    <tr><td colspan="7" class="muted"><?= $h($lastSync === null ? $t('inv_never_synced') : $t('none_yet')) ?></td></tr>
  <?php endif; ?>
  <?php foreach ($custs as $c):
      $late = $c['oldest_due'] !== null && $c['oldest_due'] < $today
            ? (int)((strtotime($today) - strtotime((string)$c['oldest_due'])) / 86400) : 0;
      $reach = trim((string)$c['phone']) !== '' || trim((string)$c['email']) !== '';
      $isOpen = $openCust === (int)$c['id'];
  ?>
    <tr<?= $late > 0 ? ' style="background:rgba(239,68,68,.05)"' : '' ?>>
      <td class="small" style="max-width:300px">
        <b><?= $h($c['name']) ?></b>
        <div class="muted"><?= $h($t('f_vat')) ?> <?= $h($c['vat_number']) ?></div>
        <?php if ($c['contact_name'] !== null): ?>
          <div class="muted">↳ <?= $h($c['contact_name']) ?></div>
        <?php endif; ?>
      </td>
      <td class="small"><b><?= $eur($c['owed']) ?></b></td>
      <td class="small">
        <?= $h((string)(int)$c['open_count']) ?>
        <?php if ((int)$c['overdue_count'] > 0): ?>
          <div style="color:var(--red)"><?= $h(str_replace('{n}', (string)(int)$c['overdue_count'], $t('inv_n_overdue'))) ?></div>
        <?php endif; ?>
      </td>
      <td class="small">
        <?php if ($c['oldest_due'] !== null): ?>
          <span<?= $late > 0 ? ' style="color:var(--red);font-weight:600"' : '' ?>><?= $h($c['oldest_due']) ?></span>
          <?php if ($late > 0): ?>
            <div style="color:var(--red)"><?= $h(str_replace('{n}', (string)$late, $t('inv_days_late'))) ?></div>
          <?php endif; ?>
        <?php else: ?><span class="muted">—</span><?php endif; ?>
      </td>
      <td class="small">
        <?php if ($reach): ?>
          <?php if (trim((string)$c['phone']) !== ''): ?><div><?= phone_link($h, $c['phone']) ?></div><?php endif; ?>
          <?php if (trim((string)$c['email']) !== ''): ?><div class="muted"><?= $h($c['email']) ?></div><?php endif; ?>
        <?php else: ?>
          <span class="pill" style="color:var(--amber)"><?= $h($t('inv_unreachable')) ?></span>
        <?php endif; ?>
      </td>
      <td class="small">
        <?php if (!(int)$c['chase_enabled']): ?>
          <span class="pill"><?= $h($t('inv_chase_excluded')) ?></span>
        <?php elseif ($c['snooze_until'] !== null && $c['snooze_until'] >= $today): ?>
          <span class="pill" style="color:var(--amber)"><?= $h($t('inv_snoozed')) ?> <?= $h($c['snooze_until']) ?></span>
        <?php elseif ($c['last_reminded_at'] !== null): ?>
          <span class="muted"><?= $h(substr((string)$c['last_reminded_at'], 0, 10)) ?></span>
          <div class="muted">×<?= $h((string)(int)$c['reminders_sent']) ?></div>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td class="small">
        <a class="btn ghost tiny" href="<?= $h($here(['c' => $isOpen ? null : (int)$c['id']])) ?>#c<?= (int)$c['id'] ?>">
          <?= $h($isOpen ? $t('inv_close') : $t('inv_open_customer')) ?></a>
      </td>
    </tr>

    <?php if ($isOpen): ?>
      <tr id="c<?= (int)$c['id'] ?>"><td colspan="7" style="background:var(--surface2)">
        <div class="row" style="gap:14px;align-items:flex-start">
          <?php // Contact details — the thing Sibill cannot give us. ?>
          <form method="post" class="card" style="flex:1;min-width:280px;margin:0">
            <input type="hidden" name="do" value="sibill_customer_save">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <h3 style="margin-top:0"><?= $h($t('inv_contact_details')) ?></h3>
            <p class="muted small" style="margin-top:-6px"><?= $h($t('inv_contact_h')) ?></p>
            <div class="row">
              <label class="fld"><span><?= $h($t('f_phone')) ?></span>
                <input name="phone" value="<?= $h($c['phone'] ?? '') ?>" placeholder="+39…"></label>
              <label class="fld"><span><?= $h($t('f_email')) ?></span>
                <input name="email" type="email" value="<?= $h($c['email'] ?? '') ?>"></label>
            </div>
            <div class="row">
              <label class="fld"><span><?= $h($t('f_default_lang')) ?></span>
                <select name="lang">
                  <?php foreach (['it', 'en'] as $lc): ?>
                    <option value="<?= $lc ?>" <?= ($c['lang'] ?? 'it') === $lc ? 'selected' : '' ?>><?= strtoupper($lc) ?></option>
                  <?php endforeach; ?>
                </select></label>
              <label class="fld"><span><?= $h($t('inv_snooze_until')) ?></span>
                <input name="snooze_until" type="date" value="<?= $h($c['snooze_until'] ?? '') ?>"></label>
            </div>
            <label class="fld" style="display:flex;flex-direction:row;align-items:center;gap:10px">
              <input type="checkbox" name="chase_enabled" value="1" style="width:auto" <?= (int)$c['chase_enabled'] ? 'checked' : '' ?>>
              <span style="margin:0"><?= $h($t('inv_chase_this')) ?></span>
            </label>
            <label class="fld"><span><?= $h($t('f_notes')) ?></span>
              <textarea name="notes" rows="2"><?= $h($c['notes'] ?? '') ?></textarea></label>
            <button class="btn"><?= $h($t('save')) ?></button>
          </form>

          <div class="card" style="flex:1;min-width:260px;margin:0">
            <h3 style="margin-top:0"><?= $h($t('inv_send_reminder')) ?></h3>
            <?php if (!$reach): ?>
              <p class="muted small"><?= $h($t('inv_cannot_remind')) ?></p>
            <?php else: ?>
              <p class="muted small"><?= $h(str_replace(
                  ['{n}', '{total}'], [(string)(int)$c['open_count'], $plain($c['owed'])], $t('inv_remind_h'))) ?></p>
              <form method="post" onsubmit="return confirm('<?= $h($t('inv_remind_confirm')) ?>')">
                <input type="hidden" name="do" value="sibill_remind">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn"><?= $h($t('inv_remind_now')) ?></button>
              </form>
              <?php if ($c['last_reminded_at'] !== null): ?>
                <p class="muted small" style="margin-bottom:0">
                  <?= $h($t('inv_last_reminded')) ?>: <?= $h($c['last_reminded_at']) ?>
                  (<?= $h((string)(int)$c['reminders_sent']) ?>)</p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <table style="margin:14px 0 0"><thead><tr>
          <th><?= $h($t('inv_th_number')) ?></th><th><?= $h($t('inv_th_total')) ?></th>
          <th><?= $h($t('inv_th_open')) ?></th><th><?= $h($t('inv_th_due')) ?></th>
          <th><?= $h($t('inv_th_state')) ?></th>
        </tr></thead><tbody>
        <?php foreach (Customers::invoices((int)$c['id']) as $i):
            $iLate = $i['pay_state'] !== 'paid' && $i['due_date'] !== null && $i['due_date'] < $today; ?>
          <tr>
            <td class="small"><b><?= $h($i['number'] ?: '—') ?></b>
              <div class="muted"><?= $h($i['creation_date'] ?: '') ?></div></td>
            <td class="small"><?= $eur($i['gross_amount'], $i['currency']) ?></td>
            <td class="small"><?= $i['pay_state'] === 'paid' ? '<span class="muted">—</span>' : $eur($i['open_amount'], $i['currency']) ?></td>
            <td class="small"><span<?= $iLate ? ' style="color:var(--red);font-weight:600"' : '' ?>>
              <?= $h(($i['pay_state'] === 'paid' ? $i['last_paid_date'] : $i['due_date']) ?: '—') ?></span></td>
            <td><span class="pill" style="color:<?= $h($stateColor[$i['pay_state']] ?? 'var(--muted)') ?>">
              <?= $h($t('inv_st_' . $i['pay_state'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      </td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody></table>

  <p class="muted small" style="margin-top:12px"><?= $h($t('inv_cfootnote')) ?></p>

<?php else: /* ---------------- flat invoice list ---------------- */ ?>

  <?php $rows = Invoices::search(['state' => $filter, 'q' => $q]); ?>
  <table><thead><tr>
    <th><?= $h($t('inv_th_number')) ?></th>
    <th><?= $h($t('inv_th_customer')) ?></th>
    <th><?= $h($t('inv_th_total')) ?></th>
    <th><?= $h($t('inv_th_open')) ?></th>
    <th><?= $h($t('inv_th_due')) ?></th>
    <th><?= $h($t('inv_th_state')) ?></th>
    <th></th>
  </tr></thead><tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="7" class="muted"><?= $h($lastSync === null ? $t('inv_never_synced') : $t('none_yet')) ?></td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r):
      $state   = (string)$r['pay_state'];
      $overdue = $state !== 'paid' && $r['due_date'] !== null && $r['due_date'] < $today;
      $isOpen  = $openInv === (int)$r['id'];
  ?>
    <tr<?= $overdue ? ' style="background:rgba(239,68,68,.05)"' : '' ?>>
      <td class="small">
        <b><?= $h($r['number'] ?: '—') ?></b>
        <?php if ($r['doc_type'] === 'CREDIT_NOTE'): ?>
          <div><span class="pill"><?= $h($t('inv_credit_note')) ?></span></div>
        <?php endif; ?>
        <div class="muted"><?= $h($r['creation_date'] ?: '') ?></div>
      </td>
      <td class="small" style="max-width:280px">
        <div><?= $h($r['counterpart_name'] ?: '—') ?></div>
        <?php if ($r['counterpart_vat']): ?>
          <div class="muted"><?= $h($t('f_vat')) ?> <?= $h($r['counterpart_vat']) ?></div>
        <?php endif; ?>
        <?php if ($r['contact_name'] !== null): ?>
          <div class="muted">↳ <?= $h($r['contact_name']) ?><?= $r['deal_title'] !== null ? ' · ' . $h($r['deal_title']) : '' ?></div>
        <?php endif; ?>
      </td>
      <td class="small"><?= $eur($r['gross_amount'], $r['currency']) ?></td>
      <td class="small"><?= $state === 'paid' ? '<span class="muted">—</span>' : $eur($r['open_amount'], $r['currency']) ?></td>
      <td class="small">
        <?php if ($state === 'paid'): ?>
          <span class="muted"><?= $h($r['last_paid_date'] ?: '') ?></span>
        <?php elseif ($r['due_date'] !== null): ?>
          <span<?= $overdue ? ' style="color:var(--red);font-weight:600"' : '' ?>><?= $h($r['due_date']) ?></span>
          <?php if ($overdue): ?>
            <div style="color:var(--red)"><?= $h(str_replace('{n}',
                (string)(int)((strtotime($today) - strtotime((string)$r['due_date'])) / 86400), $t('inv_days_late'))) ?></div>
          <?php endif; ?>
        <?php else: ?><span class="muted">—</span><?php endif; ?>
      </td>
      <td>
        <span class="pill" style="color:<?= $h($stateColor[$state] ?? 'var(--muted)') ?>">
          <?= $h($t('inv_st_' . $state)) ?></span>
        <?php if ((int)$r['flows_count'] > 1): ?>
          <div class="muted small"><?= $h($r['flows_paid'] . '/' . $r['flows_count']) ?></div>
        <?php endif; ?>
      </td>
      <td class="small">
        <?php if ((int)$r['flows_count'] > 0): ?>
          <a class="btn ghost tiny" href="<?= $h($here(['inv' => $isOpen ? null : (int)$r['id']])) ?>#inv<?= (int)$r['id'] ?>">
            <?= $h($isOpen ? $t('inv_close') : $t('inv_instalments')) ?></a>
        <?php endif; ?>
      </td>
    </tr>
    <?php if ($isOpen): ?>
      <tr id="inv<?= (int)$r['id'] ?>"><td colspan="7" style="background:var(--surface2)">
        <table style="margin:0"><thead><tr>
          <th><?= $h($t('inv_th_due')) ?></th><th><?= $h($t('inv_th_amount')) ?></th>
          <th><?= $h($t('inv_th_method')) ?></th><th><?= $h($t('inv_th_state')) ?></th>
          <th><?= $h($t('inv_th_settled')) ?></th>
        </tr></thead><tbody>
        <?php foreach (Invoices::flows((int)$r['id']) as $fl): ?>
          <tr>
            <td class="small"><?= $h($fl['due_date'] ?: '—') ?></td>
            <td class="small"><?= $eur($fl['amount'], $fl['currency']) ?></td>
            <td class="small"><?= $h(code_label($t, 'inv_pm_', $fl['payment_method'])) ?></td>
            <td><span class="pill" style="color:<?= $fl['payment_status'] === 'PAID' ? 'var(--green)' : 'var(--muted)' ?>">
              <?= $h($fl['payment_status'] === 'PAID' ? $t('inv_st_paid') : $t('inv_st_unpaid')) ?></span></td>
            <td class="small"><?= $h($fl['settled_date'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      </td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody></table>

  <p class="muted small" style="margin-top:12px"><?= $h($t('inv_footnote')) ?></p>
<?php endif; ?>
<?php endif; ?>
