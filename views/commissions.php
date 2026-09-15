<?php
/**
 * Provvigioni — the office's desk for commission statements, partners and
 * agents alike: file a statement (with the calculation attached), see who has
 * invoiced, pay, or send an invoice back. Admin only: the view is in neither
 * the agent nor the technician list, so they cannot reach it by URL.
 *
 * In scope: $t, $h, $uid, $lang.
 */

use Glue\Commission\Statements;

$fStatus = in_array($_GET['cs'] ?? '', Statements::STATUSES, true) ? (string)$_GET['cs'] : '';
$fPayee  = preg_match('/^(partner|agent):(\d+)$/', (string)($_GET['payee'] ?? ''), $pm) ? [$pm[1], (int)$pm[2]] : null;
$openId  = (int)($_GET['st'] ?? 0);
$newFor  = preg_match('/^(partner|agent):\d+$/', (string)($_GET['new'] ?? '')) ? (string)$_GET['new']
         : ($fPayee ? $fPayee[0] . ':' . $fPayee[1] : '');
$rows    = Statements::all(['status' => $fStatus ?: null, 'payee' => $fPayee]);
$tot     = Statements::totals();
$payees  = Statements::payees();
$m       = fn($n): string => Statements::money((float)$n);
$months  = $lang === 'it'
    ? ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre']
    : ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$defTitle = ($lang === 'it' ? 'Provvigioni ' : 'Commissions ') . $months[(int)date('n') - 1] . ' ' . date('Y');
$openAcc = [];
foreach ($payees['partner'] as $p) {
    $a = Statements::openAccruals((int)$p['id']);
    if ($a) {
        $openAcc[(int)$p['id']] = $a;
    }
}
$payeeName = [];
foreach ($payees as $type => $list) {
    foreach ($list as $p) { $payeeName[$type . ':' . (int)$p['id']] = (string)$p['name']; }
}

// What the office can do with a statement, by where it stands.
$actions = function (array $s) use ($t, $h, $m): string {
    $id = (int)$s['id'];
    $html = '';
    if ($s['status'] === 'invoiced') {
        $html .= '<form method="post" class="cm-form"><input type="hidden" name="do" value="cm_pay"><input type="hidden" name="id" value="' . $id . '">'
            . '<b class="small">' . $h($t('cm_pay')) . '</b><div class="row">'
            . '<label class="fld"><span>' . $h($t('cm_paid_on')) . '</span><input type="date" name="paid_on" value="' . date('Y-m-d') . '"></label>'
            . '<label class="fld"><span>' . $h($t('cm_paid_amount')) . '</span><input name="paid_amount" inputmode="decimal" value="'
            . $h(number_format((float)($s['invoice_amount'] ?? $s['amount']), 2, ',', '')) . '"></label>'
            . '<label class="fld"><span>' . $h($t('cm_payment_ref')) . '</span><input name="payment_ref" maxlength="190" placeholder="' . $h($t('cm_payment_ref_ph')) . '"></label>'
            . '</div><button class="btn tiny">✓ ' . $h($t('cm_pay_btn')) . '</button></form>';
        $html .= '<form method="post" class="cm-form"><input type="hidden" name="do" value="cm_reject"><input type="hidden" name="id" value="' . $id . '">'
            . '<b class="small">' . $h($t('cm_reject')) . '</b>'
            . '<label class="fld"><input name="reason" maxlength="500" required placeholder="' . $h($t('cm_reject_ph')) . '"></label>'
            . '<button class="btn tiny ghost">' . $h($t('cm_reject_btn')) . '</button></form>';
    }
    if ($s['status'] === 'sent') {
        $html .= commission_invoice_form($s, $t, $h, 'cm_invoice_office', true);
    }
    if (in_array($s['status'], ['sent', 'invoiced'], true)) {
        $html .= '<form method="post" class="cm-form" onsubmit="return confirm(\'' . $h(addslashes($t('cm_cancel_confirm'))) . '\')">'
            . '<input type="hidden" name="do" value="cm_cancel"><input type="hidden" name="id" value="' . $id . '">'
            . '<b class="small">' . $h($t('cm_cancel')) . '</b>'
            . '<label class="fld"><input name="note" maxlength="255" placeholder="' . $h($t('cm_cancel_ph')) . '"></label>'
            . '<button class="btn tiny ghost" style="color:var(--red)">' . $h($t('cm_cancel_btn')) . '</button></form>';
    }
    return $html;
};
?>
<h2><?= $h($t('nav_commissions')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('cm_office_sub')) ?></p>

<div class="grid" style="margin-bottom:16px">
  <div class="tile"><div class="tile-top"><?= svg('clock') ?><span><?= $h($t('cm_to_invoice')) ?></span></div>
    <span class="big" style="color:var(--amber)"><?= $h($m($tot['sent'])) ?></span>
    <div class="sub"><?= (int)$tot['n_sent'] ?> <?= $h($t('cm_statements')) ?></div></div>
  <div class="tile"><div class="tile-top"><?= svg('invoices') ?><span><?= $h($t('cm_to_pay')) ?></span></div>
    <span class="big" style="color:var(--accent)"><?= $h($m($tot['invoiced'])) ?></span>
    <div class="sub"><?= (int)$tot['n_invoiced'] ?> <?= $h($t('cm_statements')) ?></div></div>
  <div class="tile"><div class="tile-top"><?= svg('money') ?><span><?= $h($t('cm_paid_total')) ?></span></div>
    <span class="big" style="color:var(--green)"><?= $h($m($tot['paid'])) ?></span>
    <div class="sub"><?= (int)$tot['n_paid'] ?> <?= $h($t('cm_statements')) ?></div></div>
</div>

<details class="drawer" id="cm-new"<?= isset($_GET['new']) ? ' open' : '' ?>>
  <summary class="btn" style="margin-bottom:14px"><?= svg('money') ?> <?= $h($t('cm_new')) ?></summary>
  <form method="post" enctype="multipart/form-data" class="card" style="margin-top:12px;max-width:920px">
    <input type="hidden" name="do" value="cm_create">
    <div class="row">
      <label class="fld"><span><?= $h($t('cm_payee')) ?> *</span>
        <select name="payee" id="cm-payee" required>
          <option value=""><?= $h($t('cm_payee_pick')) ?></option>
          <?php foreach (['partner' => 'cm_payee_partners', 'agent' => 'cm_payee_agents'] as $type => $lbl): ?>
            <?php if ($payees[$type]): ?>
            <optgroup label="<?= $h($t($lbl)) ?>">
              <?php foreach ($payees[$type] as $p): $v = $type . ':' . (int)$p['id']; ?>
                <option value="<?= $h($v) ?>"<?= $newFor === $v ? ' selected' : '' ?>><?= $h($p['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          <?php endforeach; ?>
        </select></label>
      <label class="fld"><span><?= $h($t('cm_title')) ?> *</span><input name="title" value="<?= $h($defTitle) ?>" required maxlength="190"></label>
      <label class="fld"><span><?= $h($t('cm_period')) ?></span><input name="period" maxlength="60" placeholder="<?= $h($t('cm_period_ph')) ?>"></label>
    </div>
    <?php foreach ($openAcc as $ppid => $list): ?>
      <div class="cm-acc" data-for="partner:<?= (int)$ppid ?>" hidden>
        <b class="small"><?= $h($t('cm_include_accruals')) ?></b>
        <?php foreach ($list as $a): ?>
          <label class="cm-acc-row"><input type="checkbox" name="accrual_ids[]" value="<?= (int)$a['id'] ?>" data-amt="<?= $h($a['amount']) ?>">
            <span><?= $h($a['customer_name'] ?: ($a['deal_title'] ?: ('#' . (int)$a['id']))) ?> — <b><?= $h($m($a['amount'])) ?></b>
              <span class="muted small">(<?= $h($t('cm_deal_value')) ?> <?= $h($m($a['base_amount'])) ?> · <?= $h(number_format((float)$a['commission_pct'], 1, ',', '')) ?>%)</span></span></label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <div class="row">
      <label class="fld"><span><?= $h($t('cm_amount')) ?> *</span><input name="amount" id="cm-amount" inputmode="decimal" required placeholder="900,00"></label>
      <label class="fld"><span><?= $h($t('cm_calc_file')) ?></span><input type="file" name="calc" accept=".pdf,.xls,.xlsx,.csv,.ods,.doc,.docx,.odt,image/*"></label>
    </div>
    <label class="fld"><span><?= $h($t('cm_notes')) ?></span><textarea name="notes" rows="3" placeholder="<?= $h($t('cm_notes_ph')) ?>"></textarea></label>
    <p class="muted small" style="margin:-6px 0 12px"><?= $h($t('cm_new_hint')) ?></p>
    <button class="btn"><?= svg('send') ?> <?= $h($t('cm_create')) ?></button>
  </form>
</details>

<div class="cm-chips">
  <?php foreach (['' => 'cm_filter_all', 'invoiced' => 'cm_st_invoiced', 'sent' => 'cm_st_sent', 'paid' => 'cm_st_paid', 'cancelled' => 'cm_st_cancelled'] as $k => $lbl): ?>
    <a class="cm-chip<?= $fStatus === $k ? ' on' : '' ?>" href="<?= $h('?' . http_build_query(array_filter(['tab' => 'commissions', 'cs' => $k,
        'payee' => $fPayee ? $fPayee[0] . ':' . $fPayee[1] : ''], 'strlen'))) ?>"><?= $h($t($lbl)) ?><?php
      if ($k !== '') { echo ' <b>' . (int)$tot['n_' . $k] . '</b>'; } ?></a>
  <?php endforeach; ?>
  <?php if ($fPayee): ?>
    <span class="cm-chip on">👤 <?= $h($payeeName[$fPayee[0] . ':' . $fPayee[1]] ?? ('#' . $fPayee[1])) ?>
      <a href="<?= $h('?' . http_build_query(array_filter(['tab' => 'commissions', 'cs' => $fStatus], 'strlen'))) ?>" style="margin-left:6px">×</a></span>
  <?php endif; ?>
</div>

<?php if (!$rows): ?><div class="card"><div class="empty"><?= $h($t('cm_none')) ?></div></div><?php endif; ?>
<?php foreach ($rows as $s):
    $who = ($s['payee_type'] === 'partner' ? $t('cm_payee_partner') : $t('cm_payee_agent')) . ': ' . ($s['payee_name'] ?? ('#' . $s['payee_id'])); ?>
  <?= commission_card($s, $t, $h, $actions($s), $openId === (int)$s['id'], $who) ?>
<?php endforeach; ?>

<script>
(function(){
  var sel=document.getElementById('cm-payee'), amt=document.getElementById('cm-amount');
  if(!sel) return;
  function show(){
    document.querySelectorAll('.cm-acc').forEach(function(b){
      var on=b.dataset.for===sel.value; b.hidden=!on;
      if(!on) b.querySelectorAll('input').forEach(function(i){i.checked=false;});
    });
  }
  // Ticking the accruals the statement covers fills in their total; it stays editable.
  document.addEventListener('change',function(e){
    if(!e.target.closest('.cm-acc')) return;
    var s=0,any=false;
    document.querySelectorAll('.cm-acc:not([hidden]) input:checked').forEach(function(i){s+=parseFloat(i.dataset.amt)||0;any=true;});
    if(any) amt.value=s.toFixed(2).replace('.',',');
  });
  sel.addEventListener('change',show); show();
})();
</script>
