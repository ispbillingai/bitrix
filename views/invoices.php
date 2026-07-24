<?php
/**
 * Invoices — has the customer paid? A read-only mirror of the invoices Sibill
 * holds, refreshed by the scheduler, with the overdue ones first because those
 * are the only rows anyone opens this page to find.
 *
 * Sibill splits an invoice into instalments ("flows"/scadenze), each PAID or
 * TO_PAY, so an invoice is paid / part-paid / unpaid rather than a yes-or-no.
 * Expanding a row shows the instalments behind that verdict.
 *
 * Admin-only. In scope: $t, $h, $pdo, $cfg, $money.
 */
use Glue\Sibill\Client;
use Glue\Sibill\Invoices;

$configured = Client::configured();
$enabled    = (bool)$cfg('sibill.enabled', false);
$filter     = (string)($_GET['f'] ?? 'overdue');
$q          = trim((string)($_GET['q'] ?? ''));
$openId     = (int)($_GET['inv'] ?? 0);

$sum      = $configured ? Invoices::summary() : [];
$rows     = $configured ? Invoices::search(['state' => $filter, 'q' => $q]) : [];
$lastSync = Invoices::lastSyncAt();

// Amounts here are real accounting figures, so they keep their cents — unlike
// $money(), which rounds for the pipeline tiles.
$eur = fn($n, $cur = 'EUR') => $h($cur . ' ' . number_format((float)$n, 2, ',', '.'));

$stateColor = [
    'paid'    => 'var(--green)',
    'partial' => 'var(--amber)',
    'unpaid'  => 'var(--muted)',
    'unknown' => 'var(--muted)',
];
$today = date('Y-m-d');
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

  <?php if (!$enabled): ?>
    <div class="card" style="border-color:var(--amber);margin-bottom:14px">
      <b style="color:var(--amber)"><?= $h($t('inv_paused')) ?></b>
      <div class="muted small" style="margin-top:6px"><?= $h($t('inv_paused_h')) ?></div>
    </div>
  <?php endif; ?>

  <div class="grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:18px">
    <?php
    num_card($h, 'alert', $t('inv_overdue'), (int)($sum['overdue'] ?? 0),
        strip_tags($eur($sum['overdue_total'] ?? 0)));
    num_card($h, 'clock', $t('inv_open'), (int)(($sum['total'] ?? 0) - ($sum['paid'] ?? 0)),
        strip_tags($eur($sum['open_total'] ?? 0)));
    num_card($h, 'check', $t('inv_paid'), (int)($sum['paid'] ?? 0));
    num_card($h, 'invoices', $t('inv_total'), (int)($sum['total'] ?? 0));
    ?>
  </div>

  <div class="card" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
    <div class="muted small">
      <?= $h($t('inv_last_sync')) ?>:
      <b><?= $h($lastSync ?: $t('inv_never')) ?></b>
      <?php if ($enabled): ?>
        · <?= $h(str_replace('{n}', (string)(int)$cfg('sibill.sync_minutes', 30), $t('inv_every'))) ?>
      <?php endif; ?>
    </div>
    <form method="post" class="inline" style="margin:0">
      <input type="hidden" name="do" value="sibill_sync">
      <button class="btn ghost tiny"><?= $h($t('inv_sync_now')) ?></button>
    </form>
  </div>

  <div class="tabs">
    <?php
    $tabs = ['overdue' => 'inv_f_overdue', 'open' => 'inv_f_open', 'partial' => 'inv_f_partial',
             'paid' => 'inv_f_paid', 'unknown' => 'inv_f_unknown', '' => 'inv_f_all'];
    foreach ($tabs as $key => $label): ?>
      <a class="<?= $filter === $key ? 'on' : '' ?>"
         href="?tab=invoices&f=<?= $h($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= $h($t($label)) ?></a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="inline" style="margin-bottom:12px">
    <input type="hidden" name="tab" value="invoices">
    <input type="hidden" name="f" value="<?= $h($filter) ?>">
    <input name="q" value="<?= $h($q) ?>" placeholder="<?= $h($t('inv_search_ph')) ?>" style="max-width:280px">
    <button class="btn ghost tiny"><?= $h($t('inv_search')) ?></button>
    <?php if ($q !== ''): ?>
      <a class="btn ghost tiny" href="?tab=invoices&f=<?= $h($filter) ?>"><?= $h($t('clear')) ?></a>
    <?php endif; ?>
  </form>

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
      $isOpen  = $openId === (int)$r['id'];
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
          <div class="muted">↳ <a href="?tab=contacts"><?= $h($r['contact_name']) ?></a>
            <?= $r['deal_title'] !== null ? '· ' . $h($r['deal_title']) : '' ?></div>
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
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
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
          <a class="btn ghost tiny"
             href="?tab=invoices&f=<?= $h($filter) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?><?= $isOpen ? '' : '&inv=' . (int)$r['id'] ?>#inv<?= (int)$r['id'] ?>">
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
        <?php foreach (Invoices::flows((int)$r['id']) as $f): ?>
          <tr>
            <td class="small"><?= $h($f['due_date'] ?: '—') ?></td>
            <td class="small"><?= $eur($f['amount'], $f['currency']) ?></td>
            <td class="small"><?= $h(code_label($t, 'inv_pm_', $f['payment_method'])) ?></td>
            <td><span class="pill" style="color:<?= $f['payment_status'] === 'PAID' ? 'var(--green)' : 'var(--muted)' ?>">
              <?= $h($f['payment_status'] === 'PAID' ? $t('inv_st_paid') : $t('inv_st_unpaid')) ?></span></td>
            <td class="small"><?= $h($f['settled_date'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      </td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody></table>

  <p class="muted small" style="margin-top:12px"><?= $h($t('inv_footnote')) ?></p>
<?php endif; ?>
