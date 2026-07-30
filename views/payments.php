<?php
/**
 * Payments — the support contracts and sales SmallPay is collecting for us.
 *
 * The page answers one question first: who has stopped paying? That is the
 * decision the business actually makes here (a customer whose card fails loses
 * the service), so "past due" is the default filter and it is the first tile.
 * Everything else — opening a contract, re-sending a link, retrying a rate — is
 * downstream of that.
 *
 * A contract row expands into its rates, because "he paid in March but not in
 * April" is the level of detail an agent needs before picking up the phone.
 *
 * Nothing on this page charges anyone: SmallPay holds the mandate and does the
 * collecting. What the buttons do is ask SmallPay to retry, to stop, or to tell
 * us again what it knows.
 *
 * Admin-only. In scope: $t, $h, $pdo, $cfg, $money, $agents.
 */
use Glue\Pay\Contracts;
use Glue\Pay\SmallPay;

$configured = SmallPay::configured();
$on         = (bool)$cfg('smallpay.enabled', false);
$filter     = (string)($_GET['f'] ?? 'past_due');
$openId     = (int)($_GET['c'] ?? 0);
$prefContact = (int)($_GET['contact'] ?? 0);
$prefDeal    = (int)($_GET['deal'] ?? 0);
$newOpen    = $prefContact > 0 || $prefDeal > 0 || !empty($_GET['new']);

$eur = fn($cents, $cur = 'EUR') => $h(($cur ?: 'EUR') . ' ' . number_format(((int)$cents) / 100, 2, ',', '.'));

$statusColor = [
    'active'            => 'var(--green)',
    'completed'         => 'var(--green)',
    'past_due'          => 'var(--red)',
    'failed'            => 'var(--red)',
    'awaiting_customer' => 'var(--amber)',
    'draft'             => 'var(--muted)',
    'cancelled'         => 'var(--muted)',
];
$rateColor = [
    'paid' => 'var(--green)', 'failed' => 'var(--red)',
    'processing' => 'var(--amber)', 'pending' => 'var(--muted)', 'deleted' => 'var(--muted)',
];

$here = function (array $over = []) use ($filter): string {
    $p = array_merge(['tab' => 'payments', 'f' => $filter], $over);
    return '?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null));
};
?>
<h2><?= $h($t('nav_payments')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('pay_sub')) ?></p>

<?php if (!$configured): ?>
  <div class="card">
    <b><?= $h($t('pay_not_set')) ?></b>
    <p class="muted small" style="margin:8px 0 0"><?= $h($t('pay_not_set_h')) ?></p>
    <a class="btn ghost tiny" style="margin-top:10px" href="?tab=settings"><?= $h($t('nav_settings')) ?></a>
  </div>
<?php else: ?>

  <?php $s = Contracts::summary(); ?>
  <div class="grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:18px">
    <?php
    num_card($h, 'alert', $t('pay_t_past_due'), (int)($s['past_due'] ?? 0), $t('pay_t_past_due_h'));
    num_card($h, 'check', $t('pay_t_active'), (int)($s['active'] ?? 0),
        'EUR ' . number_format(((int)($s['mrr_cents'] ?? 0)) / 100, 2, ',', '.') . ' / ' . $t('pay_per_month'));
    num_card($h, 'clock', $t('pay_t_awaiting'), (int)($s['awaiting'] ?? 0), $t('pay_t_awaiting_h'));
    num_card($h, 'money', $t('pay_t_collected'),
        'EUR ' . number_format(((int)($s['collected_cents'] ?? 0)) / 100, 0, ',', '.'), $t('pay_t_collected_h'));
    ?>
  </div>

  <?php // The two things most likely to be mis-set live on the page, not buried in Settings. ?>
  <div class="card" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
    <div class="small">
      <span class="pill" style="color:<?= $on ? 'var(--green)' : 'var(--muted)' ?>">
        <?= $h($on ? $t('pay_on') : $t('pay_off')) ?></span>
      <?php if ((string)$cfg('smallpay.env', 'staging') !== 'production'): ?>
        <span class="pill" style="color:var(--amber);margin-left:6px"><?= $h($t('pay_staging')) ?></span>
      <?php endif; ?>
      <span class="muted" style="margin-left:8px">
        <?= $h($on ? $t('pay_on_h') : $t('pay_off_h')) ?>
      </span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" class="inline" style="margin:0">
        <input type="hidden" name="do" value="pay_sync_all">
        <button class="btn ghost tiny"><?= $h($t('pay_sync_now')) ?></button>
      </form>
      <a class="btn ghost tiny" href="?tab=settings"><?= $h($t('pay_settings')) ?></a>
      <a class="btn tiny" href="<?= $h($here(['new' => 1])) ?>"><?= $h($t('pay_new')) ?></a>
    </div>
  </div>

  <?php // ---- open a contract ---- ?>
  <?php if ($newOpen): ?>
    <?php
    // Contacts that can actually be reached — a contract whose link can't be
    // delivered is just a row nobody acts on.
    $contactRows = $pdo->query(
        "SELECT id, name, company, phone, email, lang FROM contacts
          WHERE (phone IS NOT NULL AND phone <> '') OR (email IS NOT NULL AND email <> '')
          ORDER BY name LIMIT 500"
    )->fetchAll();
    $dealRows = $pdo->query(
        "SELECT id, title, contact_id, amount, currency FROM deals
          WHERE status <> 'lost' ORDER BY id DESC LIMIT 300"
    )->fetchAll();
    ?>
    <form method="post" class="card" style="margin-bottom:14px">
      <input type="hidden" name="do" value="pay_contract_create">
      <h3 style="margin-bottom:4px"><?= $h($t('pay_new_title')) ?></h3>
      <p class="muted small" style="margin:0 0 14px"><?= $h($t('pay_new_h')) ?></p>

      <div class="row">
        <label class="fld"><span><?= $h($t('pay_f_customer')) ?></span>
          <select name="contact_id" required>
            <option value="">—</option>
            <?php foreach ($contactRows as $c): ?>
              <option value="<?= $h($c['id']) ?>" <?= $prefContact === (int)$c['id'] ? 'selected' : '' ?>>
                <?= $h(trim($c['name'] . ($c['company'] ? ' · ' . $c['company'] : ''))) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="muted"><?= $h($t('pay_f_customer_h')) ?></small>
        </label>
        <label class="fld"><span><?= $h($t('pay_f_deal')) ?></span>
          <select name="deal_id">
            <option value="">—</option>
            <?php foreach ($dealRows as $d): ?>
              <option value="<?= $h($d['id']) ?>" <?= $prefDeal === (int)$d['id'] ? 'selected' : '' ?>>
                #<?= $h($d['id']) ?> · <?= $h($d['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="muted"><?= $h($t('pay_f_deal_h')) ?></small>
        </label>
      </div>

      <div class="row">
        <label class="fld"><span><?= $h($t('pay_f_kind')) ?></span>
          <select name="kind" id="payKind" onchange="payKindChanged()">
            <option value="subscription"><?= $h($t('pay_k_subscription')) ?></option>
            <option value="installments"><?= $h($t('pay_k_installments')) ?></option>
            <option value="one_off"><?= $h($t('pay_k_one_off')) ?></option>
          </select>
          <small class="muted" id="payKindHint"><?= $h($t('pay_k_subscription_h')) ?></small>
        </label>
        <?php fld($h, 'description', $t('pay_f_desc'), '', $t('pay_f_desc_h')); ?>
      </div>

      <div class="row">
        <label class="fld"><span id="payAmountLabel"><?= $h($t('pay_f_amount')) ?></span>
          <input name="amount" inputmode="decimal" placeholder="49,00" required>
          <small class="muted" id="payAmountHint"><?= $h($t('pay_f_amount_h')) ?></small>
        </label>
        <label class="fld" id="payCyclesFld" style="display:none"><span><?= $h($t('pay_f_cycles')) ?></span>
          <input name="total_cycles" inputmode="numeric" placeholder="12">
          <small class="muted"><?= $h($t('pay_f_cycles_h')) ?></small>
        </label>
        <label class="fld"><span><?= $h($t('pay_f_first')) ?></span>
          <input name="first_amount" inputmode="decimal" placeholder="0,00">
          <small class="muted"><?= $h($t('pay_f_first_h')) ?></small>
        </label>
      </div>

      <label class="fld" style="display:flex;flex-direction:row;align-items:center;gap:10px">
        <input type="checkbox" name="send_link" value="1" checked style="width:auto">
        <span style="margin:0"><?= $h($t('pay_f_send')) ?></span>
      </label>

      <div style="display:flex;gap:8px;align-items:center">
        <button class="btn" <?= $on ? '' : 'disabled' ?>><?= $h($t('pay_create')) ?></button>
        <a class="btn ghost" href="<?= $h($here()) ?>"><?= $h($t('cancel')) ?></a>
        <?php if (!$on): ?><span class="muted small"><?= $h($t('pay_off_h')) ?></span><?php endif; ?>
      </div>
    </form>
    <script>
    // The three kinds need different fields: a subscription has no rate count, a
    // one-off has no recurring amount. Hiding what doesn't apply is cheaper than
    // explaining it.
    function payKindChanged() {
      var k = document.getElementById('payKind').value;
      document.getElementById('payCyclesFld').style.display = k === 'installments' ? '' : 'none';
      document.getElementById('payKindHint').textContent = {
        subscription: <?= json_encode($t('pay_k_subscription_h'), JSON_UNESCAPED_UNICODE) ?>,
        installments: <?= json_encode($t('pay_k_installments_h'), JSON_UNESCAPED_UNICODE) ?>,
        one_off:      <?= json_encode($t('pay_k_one_off_h'), JSON_UNESCAPED_UNICODE) ?>
      }[k];
      document.getElementById('payAmountLabel').textContent = k === 'one_off'
        ? <?= json_encode($t('pay_f_total'), JSON_UNESCAPED_UNICODE) ?>
        : <?= json_encode($t('pay_f_amount'), JSON_UNESCAPED_UNICODE) ?>;
      document.getElementById('payAmountHint').textContent = k === 'one_off'
        ? <?= json_encode($t('pay_f_total_h'), JSON_UNESCAPED_UNICODE) ?>
        : <?= json_encode($t('pay_f_amount_h'), JSON_UNESCAPED_UNICODE) ?>;
    }
    payKindChanged();
    </script>
  <?php endif; ?>

  <div class="tabs">
    <?php
    $tabs = [
        'past_due'          => 'pay_f_past_due',
        'active'            => 'pay_f_active',
        'awaiting_customer' => 'pay_f_awaiting',
        'cancelled'         => 'pay_f_cancelled',
        ''                  => 'pay_f_all',
    ];
    foreach ($tabs as $k => $label): ?>
      <a class="<?= $filter === $k ? 'on' : '' ?>" href="?tab=payments&f=<?= $h($k) ?>"><?= $h($t($label)) ?></a>
    <?php endforeach; ?>
  </div>

  <?php $rows = Contracts::all($filter !== '' ? $filter : null); ?>
  <?php if (!$rows): ?>
    <div class="card"><p class="muted"><?= $h($t('pay_none')) ?></p></div>
  <?php else: ?>
    <div class="card" style="padding:0;overflow-x:auto">
      <table class="tbl">
        <thead><tr>
          <th><?= $h($t('pay_c_customer')) ?></th>
          <th><?= $h($t('pay_c_what')) ?></th>
          <th><?= $h($t('pay_c_amount')) ?></th>
          <th><?= $h($t('pay_c_collected')) ?></th>
          <th><?= $h($t('pay_c_next')) ?></th>
          <th><?= $h($t('pay_c_status')) ?></th>
          <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $c): $cid = (int)$c['id']; $isOpen = $openId === $cid; ?>
          <tr>
            <td>
              <b><?= $h($c['customer_name'] ?: '—') ?></b>
              <?php if ($c['deal_title']): ?>
                <div class="muted small"><?= $h($c['deal_title']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?= $h($c['description']) ?>
              <div class="muted small"><?= $h(Contracts::cadenceText($c)) ?></div>
            </td>
            <td><?= $eur($c['amount_cents'] ?: $c['first_amount_cents'], $c['currency']) ?></td>
            <td>
              <?= $eur($c['paid_cents'], $c['currency']) ?>
              <div class="muted small">
                <?= (int)$c['cycles_paid'] ?><?= (int)$c['total_cycles'] > 0 ? '/' . (int)$c['total_cycles'] : '' ?>
                <?php if ((int)$c['failed_cycles'] > 0): ?>
                  · <span style="color:var(--red)"><?= (int)$c['failed_cycles'] ?> <?= $h($t('pay_unpaid')) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="muted"><?= $h($c['next_charge_date'] ? date('d/m/Y', strtotime((string)$c['next_charge_date'])) : '—') ?></td>
            <td>
              <span class="pill" style="color:<?= $statusColor[(string)$c['status']] ?? 'var(--muted)' ?>">
                <?= $h($t('pay_s_' . $c['status'])) ?></span>
            </td>
            <td style="text-align:right;white-space:nowrap">
              <a class="btn ghost tiny" href="<?= $h($here(['c' => $isOpen ? null : $cid])) ?>">
                <?= $h($isOpen ? $t('pay_close') : $t('pay_open')) ?></a>
            </td>
          </tr>

          <?php if ($isOpen): $charges = Contracts::charges($cid); ?>
          <tr><td colspan="7" style="background:var(--surface2)">
            <div style="padding:14px 4px">

              <?php if (trim((string)$c['last_error']) !== ''): ?>
                <p class="small" style="color:var(--red);margin-bottom:12px">
                  <b><?= $h($t('pay_last_error')) ?>:</b> <?= $h($c['last_error']) ?></p>
              <?php endif; ?>

              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
                <?php if (trim((string)$c['checkout_url']) !== ''): ?>
                  <form method="post" class="inline" style="margin:0">
                    <input type="hidden" name="do" value="pay_send_link">
                    <input type="hidden" name="id" value="<?= $h($cid) ?>">
                    <button class="btn ghost tiny"><?= $h($t('pay_a_send')) ?></button></form>
                  <a class="btn ghost tiny" href="<?= $h($c['checkout_url']) ?>" target="_blank" rel="noopener">
                    <?= $h($t('pay_a_open_cashier')) ?></a>
                <?php endif; ?>
                <form method="post" class="inline" style="margin:0">
                  <input type="hidden" name="do" value="pay_sync">
                  <input type="hidden" name="id" value="<?= $h($cid) ?>">
                  <button class="btn ghost tiny"><?= $h($t('pay_a_refresh')) ?></button></form>
                <?php if ((int)$c['failed_cycles'] > 0): ?>
                  <form method="post" class="inline" style="margin:0">
                    <input type="hidden" name="do" value="pay_relaunch">
                    <input type="hidden" name="id" value="<?= $h($cid) ?>">
                    <button class="btn ghost tiny"><?= $h($t('pay_a_retry')) ?></button></form>
                <?php endif; ?>
                <?php if ((string)$c['status'] === 'failed'): ?>
                  <form method="post" class="inline" style="margin:0">
                    <input type="hidden" name="do" value="pay_regenerate">
                    <input type="hidden" name="id" value="<?= $h($cid) ?>">
                    <button class="btn ghost tiny"><?= $h($t('pay_a_regenerate')) ?></button></form>
                <?php endif; ?>
                <?php if (!in_array((string)$c['status'], ['cancelled', 'completed'], true)): ?>
                  <form method="post" class="inline" style="margin:0"
                        onsubmit="return confirm(<?= $h(json_encode($t('pay_a_cancel_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
                    <input type="hidden" name="do" value="pay_cancel">
                    <input type="hidden" name="id" value="<?= $h($cid) ?>">
                    <button class="btn ghost tiny" style="color:var(--red)"><?= $h($t('pay_a_cancel')) ?></button></form>
                <?php endif; ?>
              </div>

              <p class="muted small" style="margin-bottom:10px">
                <?= $h($t('pay_ref')) ?>: <b><?= $h($c['reference']) ?></b>
                <?php if ($c['operation_id']): ?> · SmallPay: <?= $h($c['operation_id']) ?><?php endif; ?>
                <?php if ($c['last_sync_at']): ?> · <?= $h($t('pay_synced')) ?> <?= $h($c['last_sync_at']) ?><?php endif; ?>
                <?php if ($c['contract_url']): ?>
                  · <a href="<?= $h($c['contract_url']) ?>" target="_blank" rel="noopener"><?= $h($t('pay_mandate')) ?></a>
                <?php endif; ?>
              </p>

              <?php if (!$charges): ?>
                <p class="muted small"><?= $h($t('pay_no_rates')) ?></p>
              <?php else: ?>
                <form method="post">
                  <input type="hidden" name="do" value="pay_cash">
                  <input type="hidden" name="id" value="<?= $h($cid) ?>">
                  <table class="tbl" style="background:transparent">
                    <thead><tr>
                      <th style="width:34px"></th>
                      <th>#</th>
                      <th><?= $h($t('pay_r_due')) ?></th>
                      <th><?= $h($t('pay_r_amount')) ?></th>
                      <th><?= $h($t('pay_r_status')) ?></th>
                      <th><?= $h($t('pay_r_paid')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($charges as $r): $done = in_array((string)$r['status'], ['paid', 'deleted'], true); ?>
                      <tr>
                        <td><?php if (!$done): ?>
                          <input type="checkbox" name="charges[]" value="<?= $h($r['external_id']) ?>">
                        <?php endif; ?></td>
                        <td class="muted"><?= (int)$r['seq'] ?></td>
                        <td><?= $h($r['due_date'] ? date('d/m/Y', strtotime((string)$r['due_date'])) : '—') ?></td>
                        <td><?= $eur($r['amount_cents'], $r['currency']) ?></td>
                        <td><span class="pill" style="color:<?= $rateColor[(string)$r['status']] ?? 'var(--muted)' ?>">
                          <?= $h($t('pay_rs_' . $r['status'])) ?></span></td>
                        <td class="muted">
                          <?= $h($r['paid_date'] ? date('d/m/Y', strtotime((string)$r['paid_date'])) : '—') ?>
                          <?php if ((int)$r['paid_in_cash'] === 1): ?>
                            <span class="pill" style="color:var(--amber)"><?= $h($t('pay_in_cash')) ?></span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                  <div style="margin-top:10px">
                    <button class="btn ghost tiny"><?= $h($t('pay_a_cash')) ?></button>
                    <span class="muted small" style="margin-left:8px"><?= $h($t('pay_a_cash_h')) ?></span>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
