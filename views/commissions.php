<?php
/**
 * Provvigioni — the office's desk for commission statements, partners and
 * agents alike: file a statement (with the calculation attached), see who has
 * invoiced, pay, or send an invoice back. Admin only: the view is in neither
 * the agent nor the technician list, so they cannot reach it by URL.
 *
 * In scope: $t, $h, $uid, $lang.
 */

use Glue\Commission\Plans;
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

// ---- commissions paid in instalments, as the customer pays ----
$cpAll   = ($_GET['cp'] ?? '') === 'all';
$plans   = array_values(array_filter(Plans::all(['payee' => $fPayee]),
    fn($p) => $cpAll || $p['status'] !== 'cancelled'));
$cpOpen  = (int)($_GET['cp_open'] ?? 0);
$waitAll = Plans::waiting();
$split   = (int)($_GET['split'] ?? 0) > 0 ? Statements::find((int)$_GET['split']) : null;
if ($split && !Plans::splittable($split)) {
    $split = null;
}
$hasSibill = false;
try {
    $hasSibill = (int)\Glue\Db::pdo()->query("SELECT COUNT(*) FROM sibill_invoices WHERE doc_type = 'INVOICE'")->fetchColumn() > 0;
} catch (Throwable $e) {
    $hasSibill = false;
}

// The office's buttons on one customer instalment.
$rateAction = function (array $r, array $p) use ($t, $h): string {
    if ($p['status'] === 'active' && $r['status'] === 'waiting') {
        return '<form method="post" class="cp-act"><input type="hidden" name="do" value="cp_earn">'
            . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
            . '<input type="date" name="paid_on" value="' . date('Y-m-d') . '" max="' . date('Y-m-d') . '" aria-label="' . $h($t('cp_paid_on')) . '">'
            . '<button class="btn tiny">✓ ' . $h($t('cp_earn_btn')) . '</button></form>';
    }
    if ($r['status'] === 'earned' && $r['paid_source'] === 'office' && in_array((string)$r['st_status'], ['sent', 'invoiced'], true)
        && $p['status'] !== 'cancelled') {
        return '<form method="post" class="cp-act" onsubmit="return confirm(' . $h(json_encode($t('cp_undo_confirm'), JSON_UNESCAPED_UNICODE)) . ')">'
            . '<input type="hidden" name="do" value="cp_undo"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
            . '<button class="btn tiny ghost">↩ ' . $h($t('cp_undo_btn')) . '</button></form>';
    }
    return '';
};
$planActions = function (array $p) use ($t, $h): string {
    if ($p['status'] !== 'active') {
        return '';
    }
    return '<form method="post" class="cm-form" onsubmit="return confirm(' . $h(json_encode($t('cp_cancel_confirm'), JSON_UNESCAPED_UNICODE)) . ')">'
        . '<input type="hidden" name="do" value="cp_cancel"><input type="hidden" name="id" value="' . (int)$p['id'] . '">'
        . '<b class="small">' . $h($t('cp_cancel')) . '</b>'
        . '<p class="muted small" style="margin:4px 0 8px">' . $h($t('cp_cancel_h')) . '</p>'
        . '<label class="fld"><input name="note" maxlength="255" placeholder="' . $h($t('cm_cancel_ph')) . '"></label>'
        . '<button class="btn tiny ghost" style="color:var(--red)">' . $h($t('cp_cancel_btn')) . '</button></form>';
};

// What the office can do with a statement, by where it stands. Paying needs no
// invoice: an agent who issues none is paid in cash, by transfer or otherwise,
// straight from "In attesa di fattura" (2026-09-15).
$actions = function (array $s) use ($t, $h, $m): string {
    $id   = (int)$s['id'];
    $st   = (string)$s['status'];
    $needs = (int)($s['invoice_required'] ?? 1) === 1;
    $hasInvoice = (string)($s['invoice_number'] ?? '') !== '';
    $cash = !$hasInvoice; // no invoice in hand: most likely cash
    $html = '';
    if (in_array($st, ['sent', 'invoiced'], true)) {
        $html .= '<form method="post" class="cm-form"><input type="hidden" name="do" value="cm_pay"><input type="hidden" name="id" value="' . $id . '">'
            . '<b class="small">' . $h($t('cm_pay')) . '</b>'
            . ($st === 'sent' && $needs ? '<p class="muted small" style="margin:4px 0 10px">' . $h($t('cm_pay_no_invoice_hint')) . '</p>' : '')
            . '<div class="row">'
            . '<label class="fld"><span>' . $h($t('cm_paid_on')) . '</span><input type="date" name="paid_on" value="' . date('Y-m-d') . '"></label>'
            . '<label class="fld"><span>' . $h($t('cm_paid_amount')) . '</span><input name="paid_amount" inputmode="decimal" value="'
            . $h(number_format((float)($s['invoice_amount'] ?? $s['amount']), 2, ',', '')) . '"></label>'
            . '<label class="fld"><span>' . $h($t('cm_pay_method')) . '</span><select name="payment_method">'
            . '<option value="transfer"' . ($cash ? '' : ' selected') . '>' . $h($t('cm_pm_transfer')) . '</option>'
            . '<option value="cash"' . ($cash ? ' selected' : '') . '>' . $h($t('cm_pm_cash')) . '</option>'
            . '<option value="other">' . $h($t('cm_pm_other')) . '</option></select></label>'
            . '<label class="fld"><span>' . $h($t('cm_payment_ref')) . '</span><input name="payment_ref" maxlength="190" placeholder="' . $h($t('cm_payment_ref_ph')) . '"></label>'
            . '</div><button class="btn tiny">✓ ' . $h($t('cm_pay_btn')) . '</button></form>';
    }
    if ($st === 'invoiced' && $hasInvoice) {
        $html .= '<form method="post" class="cm-form"><input type="hidden" name="do" value="cm_reject"><input type="hidden" name="id" value="' . $id . '">'
            . '<b class="small">' . $h($t('cm_reject')) . '</b>'
            . '<label class="fld"><input name="reason" maxlength="500" required placeholder="' . $h($t('cm_reject_ph')) . '"></label>'
            . '<button class="btn tiny ghost">' . $h($t('cm_reject_btn')) . '</button></form>';
    }
    if ($st === 'sent' && $needs) {
        $html .= commission_invoice_form($s, $t, $h, 'cm_invoice_office', true);
    }
    // A statement for a whole sale can still be turned into instalments that
    // follow the customer's payments, until an invoice for all of it arrives.
    if (Plans::splittable($s)) {
        $html .= '<div class="cm-form"><b class="small">' . $h($t('cp_split')) . '</b>'
            . '<p class="muted small" style="margin:4px 0 10px">' . $h($t('cp_split_h')) . '</p>'
            . '<a class="btn tiny ghost" href="?tab=commissions&amp;split=' . $id . '#cp-new">' . $h($t('cp_split_btn')) . '</a></div>';
    }
    if (in_array($st, ['sent', 'invoiced'], true)) {
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
  <div class="tile"><div class="tile-top"><?= svg('reminders') ?><span><?= $h($t('cp_waiting_tile')) ?></span></div>
    <span class="big" style="color:var(--muted)"><?= $h($m($waitAll['amount'])) ?></span>
    <div class="sub"><?= (int)$waitAll['n'] ?> <?= $h($t('cp_rates_word')) ?></div></div>
</div>

<div class="cm-drawers">
<details class="drawer" id="cm-new"<?= isset($_GET['new']) ? ' open' : '' ?>>
  <summary class="btn" style="margin-bottom:14px"><?= svg('money') ?> <?= $h($t('cm_new')) ?></summary>
  <form method="post" enctype="multipart/form-data" class="card" style="margin-top:12px;max-width:920px">
    <input type="hidden" name="do" value="cm_create">
    <div class="row">
      <label class="fld"><span><?= $h($t('cm_payee')) ?> *</span>
        <select name="payee" id="cm-payee" required data-noinv="<?= $h(implode(',', Statements::noInvoicePayees())) ?>">
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
    <label class="fld" style="display:flex;flex-direction:row;align-items:flex-start;gap:10px">
      <input type="checkbox" name="no_invoice" value="1" id="cm-noinv" style="width:auto;margin-top:3px">
      <span style="margin:0"><b style="color:var(--txt)"><?= $h($t('cm_no_invoice')) ?></b><br><?= $h($t('cm_no_invoice_h')) ?></span>
    </label>
    <p class="muted small" style="margin:-6px 0 12px"><?= $h($t('cm_new_hint')) ?></p>
    <button class="btn"><?= svg('send') ?> <?= $h($t('cm_create')) ?></button>
  </form>
</details>

<?php // ---- a commission paid in instalments, as the customer pays ---- ?>
<details class="drawer" id="cp-new"<?= ($split || isset($_GET['cp_new'])) ? ' open' : '' ?>>
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('reminders') ?> <?= $h($t('cp_new')) ?></summary>
  <form method="post" enctype="multipart/form-data" class="card" id="cp-form" style="margin-top:12px;max-width:920px">
    <input type="hidden" name="do" value="cp_create">
    <p class="muted small" style="margin:0 0 14px"><?= $h($t('cp_new_h')) ?></p>
    <?php if ($split): ?>
      <input type="hidden" name="source_statement_id" value="<?= (int)$split['id'] ?>">
      <div class="warn"><?= $h(sprintf($t('cp_split_notice'), (int)$split['id'], $m($split['amount']),
          $payeeName[$split['payee_type'] . ':' . (int)$split['payee_id']] ?? ('#' . (int)$split['payee_id']))) ?></div>
    <?php endif; ?>
    <div class="row">
      <label class="fld"><span><?= $h($t('cm_payee')) ?> *</span>
        <?php if ($split): ?>
          <input value="<?= $h($payeeName[$split['payee_type'] . ':' . (int)$split['payee_id']] ?? ('#' . (int)$split['payee_id'])) ?>" readonly>
        <?php else: ?>
        <select name="payee" id="cp-payee" required data-noinv="<?= $h(implode(',', Statements::noInvoicePayees())) ?>">
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
        </select>
        <?php endif; ?></label>
      <label class="fld"><span><?= $h($t('cm_title')) ?></span>
        <input name="title" maxlength="190" value="<?= $h($split['title'] ?? '') ?>" placeholder="<?= $h($t('cp_title_ph')) ?>"></label>
    </div>

    <b class="small"><?= $h($t('cp_customer_pays')) ?></b>
    <div class="cm-chips" style="margin:8px 0 10px">
      <label class="cm-chip"><input type="radio" name="cp_src" value="sibill" style="width:auto"<?= $hasSibill ? ' checked' : '' ?><?= $hasSibill ? '' : ' disabled' ?>> <?= $h($t('cp_src_sibill')) ?></label>
      <label class="cm-chip"><input type="radio" name="cp_src" value="manual" style="width:auto"<?= $hasSibill ? '' : ' checked' ?>> <?= $h($t('cp_src_manual')) ?></label>
    </div>

    <div class="cp-src" data-src="sibill">
      <input type="hidden" name="sibill_invoice_id" id="cp-inv" value="">
      <label class="fld"><span><?= $h($t('cp_sibill_search')) ?></span>
        <input type="search" id="cp-inv-q" placeholder="<?= $h($t('cp_sibill_search_ph')) ?>" autocomplete="off"></label>
      <div id="cp-inv-res" class="cp-rates" style="margin-top:-6px"></div>
      <div id="cp-inv-pick" hidden class="cm-strip" style="margin-bottom:12px"></div>
    </div>

    <div class="cp-src" data-src="manual" hidden>
      <label class="fld"><span><?= $h($t('cp_customer')) ?> *</span>
        <input name="customer_name" id="cp-cust" maxlength="190" placeholder="<?= $h($t('cp_customer_ph')) ?>"></label>
      <div class="row">
        <label class="fld"><span><?= $h($t('cp_gen_total')) ?></span><input id="cp-g-total" inputmode="decimal" placeholder="12.000,00"></label>
        <label class="fld" style="max-width:120px"><span><?= $h($t('cp_gen_n')) ?></span><input id="cp-g-n" type="number" min="1" max="120" value="3"></label>
        <label class="fld"><span><?= $h($t('cp_gen_first')) ?></span><input id="cp-g-first" type="date" value="<?= date('Y-m-d') ?>"></label>
        <label class="fld" style="max-width:140px"><span><?= $h($t('cp_gen_every')) ?></span><input id="cp-g-every" type="number" min="0" max="12" value="1"></label>
      </div>
      <p style="margin:-6px 0 12px"><button type="button" class="btn tiny ghost" id="cp-gen"><?= $h($t('cp_gen_btn')) ?></button>
        <button type="button" class="btn tiny ghost" id="cp-add">+ <?= $h($t('cp_add_rate')) ?></button></p>
      <div id="cp-rows" class="cp-rates"></div>
    </div>

    <b class="small" style="display:block;margin-top:6px"><?= $h($t('cp_commission')) ?></b>
    <div class="cm-chips" style="margin:8px 0 10px">
      <label class="cm-chip"><input type="radio" name="mode" value="pct" style="width:auto"<?= $split ? '' : ' checked' ?>> <?= $h($t('cp_mode_pct')) ?></label>
      <label class="cm-chip"><input type="radio" name="mode" value="amount" style="width:auto"<?= $split ? ' checked' : '' ?>> <?= $h($t('cp_mode_amount')) ?></label>
    </div>
    <div class="row cp-mode" data-mode="pct"<?= $split ? ' hidden' : '' ?>>
      <label class="fld" style="max-width:140px"><span><?= $h($t('cp_pct')) ?> *</span><input name="commission_pct" id="cp-pct" inputmode="decimal" placeholder="10"></label>
      <label class="fld"><span><?= $h($t('cp_pct_base')) ?></span>
        <select name="pct_base" id="cp-base">
          <option value="net"><?= $h($t('cp_base_net')) ?></option>
          <option value="gross"><?= $h($t('cp_base_gross')) ?></option>
        </select></label>
      <label class="fld" style="max-width:120px"><span><?= $h($t('cp_vat')) ?></span><input name="vat_rate" id="cp-vat" inputmode="decimal" value="22"></label>
    </div>
    <div class="row cp-mode" data-mode="amount"<?= $split ? '' : ' hidden' ?>>
      <label class="fld"><span><?= $h($t('cp_total')) ?> *</span>
        <input name="commission_total" id="cp-total" inputmode="decimal" placeholder="900,00"
               value="<?= $split ? $h(number_format((float)$split['amount'], 2, ',', '')) : '' ?>"></label>
    </div>
    <div id="cp-preview" class="cm-note" hidden></div>

    <?php if (!$split): ?>
      <label class="fld"><span><?= $h($t('cm_calc_file')) ?></span><input type="file" name="calc" accept=".pdf,.xls,.xlsx,.csv,.ods,.doc,.docx,.odt,image/*"></label>
    <?php endif; ?>
    <label class="fld"><span><?= $h($t('cm_notes')) ?></span>
      <textarea name="notes" rows="2" placeholder="<?= $h($t('cm_notes_ph')) ?>"><?= $h($split['notes'] ?? '') ?></textarea></label>
    <?php if (!$split): ?>
    <label class="fld" style="display:flex;flex-direction:row;align-items:flex-start;gap:10px">
      <input type="checkbox" name="no_invoice" value="1" id="cp-noinv" style="width:auto;margin-top:3px">
      <span style="margin:0"><b style="color:var(--txt)"><?= $h($t('cm_no_invoice')) ?></b><br><?= $h($t('cp_no_invoice_h')) ?></span>
    </label>
    <?php endif; ?>
    <button class="btn"><?= svg('check') ?> <?= $h($t('cp_create')) ?></button>
  </form>
</details>
</div>

<?php if ($plans || $cpAll): ?>
  <h3 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><?= svg('reminders') ?> <?= $h($t('cp_section')) ?>
    <a class="muted small" style="font-weight:400" href="<?= $h('?' . http_build_query(array_filter(['tab' => 'commissions',
        'payee' => $fPayee ? $fPayee[0] . ':' . $fPayee[1] : '', 'cp' => $cpAll ? '' : 'all'], 'strlen'))) ?>"><?= $h($t($cpAll ? 'cp_hide_cancelled' : 'cp_show_all')) ?></a></h3>
  <?php if (!$plans): ?><div class="card"><div class="empty"><?= $h($t('cp_none')) ?></div></div><?php endif; ?>
  <?php foreach ($plans as $p): ?>
    <?= commission_plan_card($p, $t, $h, '?tab=commissions', $rateAction, $planActions($p),
          $cpOpen === (int)$p['id'], true, '?cpf=' . (int)$p['id']) ?>
  <?php endforeach; ?>
  <h3 style="margin-top:22px"><?= svg('invoices') ?> <?= $h($t('cp_statements_h')) ?></h3>
<?php endif; ?>

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
  // "Senza fattura" follows the payee: ticked for whoever was last paid without one.
  var cb=document.getElementById('cm-noinv'), ni=(sel.dataset.noinv||'').split(',');
  function preset(){ if(cb){ cb.checked = sel.value !== '' && ni.indexOf(sel.value) !== -1; } }
  sel.addEventListener('change',function(){ show(); preset(); }); show(); preset();
})();

// A link to one instalment commission (#cp-N) opens its card.
if(location.hash.indexOf('#cp-')===0){var cpd=document.querySelector(location.hash);if(cpd&&cpd.tagName==='DETAILS'){cpd.open=true;}}

// ---- the instalment plan form ----
(function(){
  var form=document.getElementById('cp-form'); if(!form) return;
  var L=<?= json_encode([
      'paid' => $t('cp_js_paid'), 'topay' => $t('cp_js_topay'), 'none' => $t('cp_js_none'),
      'sale' => $t('cp_js_sale'), 'comm' => $t('cp_js_comm'), 'already' => $t('cp_js_already'),
      'toomuch' => $t('cp_js_toomuch'), 'change' => $t('cp_js_change'), 'rate' => $t('cp_js_rate'),
      'due' => $t('cp_js_due'), 'amount' => $t('cp_js_amount'), 'invoice' => $t('cp_js_invoice'),
      'pick' => $t('cp_js_pick'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  var picked=null; // the chosen Sibill invoice
  function num(s){ s=String(s||'').replace(/[^\d.,]/g,''); if(!s) return 0;
    var p=Math.max(s.lastIndexOf(','),s.lastIndexOf('.')), after=p>=0?s.length-p-1:0;
    if(p>=0&&after>=1&&after<=2){ return parseFloat(s.slice(0,p).replace(/\D/g,'')+'.'+s.slice(p+1).replace(/\D/g,''))||0; }
    return parseFloat(s.replace(/\D/g,''))||0; }
  function eur(n){ return '€ '+n.toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.'); }
  function dmy(d){ if(!d) return '—'; var p=d.split('-'); return p[2]+'/'+p[1]+'/'+p[0]; }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function src(){ var r=form.querySelector('input[name=cp_src]:checked'); return r?r.value:'manual'; }
  function mode(){ var r=form.querySelector('input[name=mode]:checked'); return r?r.value:'pct'; }

  function sync(){
    form.querySelectorAll('.cp-src').forEach(function(b){
      var on=b.dataset.src===src(); b.hidden=!on;
      b.querySelectorAll('input').forEach(function(i){ if(i.id!=='cp-inv-q') i.disabled=!on; });
    });
    document.getElementById('cp-cust').required = src()==='manual';
    form.querySelectorAll('.cp-mode').forEach(function(b){
      var on=b.dataset.mode===mode(); b.hidden=!on;
      b.querySelectorAll('input,select').forEach(function(i){ i.disabled=!on; });
    });
    document.getElementById('cp-pct').required = mode()==='pct';
    document.getElementById('cp-total').required = mode()==='amount';
    document.getElementById('cp-vat').disabled = mode()!=='pct' || document.getElementById('cp-base').value!=='net';
    preview();
  }

  // amounts + paid flags of the instalments the form currently describes
  function rates(){
    if(src()==='sibill'){ return picked ? picked.flows.map(function(f){ return {a:f.amount, paid:f.paid}; }) : []; }
    var out=[]; document.querySelectorAll('#cp-rows .cp-rate').forEach(function(r){
      var a=num(r.querySelector('input[name="rate_amount[]"]').value); if(a>0) out.push({a:a, paid:false}); });
    return out;
  }
  function shares(amounts,total){
    var sale=amounts.reduce(function(s,a){return s+a;},0), out=[], sum=0;
    amounts.forEach(function(a,i){
      if(i===amounts.length-1){ out.push(Math.round((total-sum)*100)/100); return; }
      var s=sale>0?Math.round(a*total/sale*100)/100:0; out.push(s); sum=Math.round((sum+s)*100)/100; });
    return out;
  }
  function preview(){
    var box=document.getElementById('cp-preview'), rs=rates();
    var sale=Math.round(rs.reduce(function(s,r){return s+r.a;},0)*100)/100, total=0;
    if(mode()==='pct'){
      var pct=num(document.getElementById('cp-pct').value), base=document.getElementById('cp-base').value;
      var vatRaw=document.getElementById('cp-vat').value.trim(), vat=vatRaw===''?22:num(vatRaw);
      total=Math.round((base==='net'?sale/(1+vat/100):sale)*pct/100*100)/100;
    } else { total=num(document.getElementById('cp-total').value); }
    if(!rs.length||total<=0){ box.hidden=true; return; }
    var sh=shares(rs.map(function(r){return r.a;}),total), paid=rs.filter(function(r){return r.paid;}).length;
    var html='<b>'+esc(L.sale)+'</b> '+eur(sale)+' · <b>'+esc(L.comm)+'</b> '+eur(total)+'<br>'
      +sh.map(function(s,i){ return (i+1)+') '+eur(s)+(rs[i].paid?' ✅':''); }).join(' · ');
    if(total>sale) html+='<br><span style="color:var(--red)">'+esc(L.toomuch)+'</span>';
    if(paid) html+='<br>✅ '+esc(L.already.replace('%d',paid));
    box.innerHTML=html; box.hidden=false;
  }

  // ---- Sibill invoice search ----
  var q=document.getElementById('cp-inv-q'), res=document.getElementById('cp-inv-res'), pick=document.getElementById('cp-inv-pick'), timer=null, list=[];
  function showPick(){
    if(!picked){ pick.hidden=true; document.getElementById('cp-inv').value=''; preview(); return; }
    document.getElementById('cp-inv').value=picked.id;
    var paid=picked.flows.filter(function(f){return f.paid;}).length;
    pick.innerHTML='<span style="flex:1;min-width:200px"><b>'+esc(L.invoice)+' '+esc(picked.number)+'</b> · '+dmy(picked.date)+' · '+esc(picked.customer)
      +'<br><span class="muted small">'+eur(picked.gross)+' · '+picked.flows.length+' '+esc(L.rate)+' · '+paid+' '+esc(L.paid)+'</span><br><span class="small">'
      +picked.flows.map(function(f,i){ return (i+1)+') '+dmy(f.due)+' '+eur(f.amount)+(f.paid?' ✅':''); }).join(' · ')+'</span></span>'
      +'<button type="button" class="btn tiny ghost" id="cp-inv-clear">'+esc(L.change)+'</button>';
    pick.hidden=false; res.innerHTML=''; q.value='';
    document.getElementById('cp-inv-clear').onclick=function(){ picked=null; showPick(); q.focus(); };
    preview();
  }
  if(q){ q.addEventListener('input',function(){
    clearTimeout(timer); var v=q.value.trim(); if(v.length<2){ res.innerHTML=''; return; }
    timer=setTimeout(function(){
      fetch('?find=sibill_invoices&q='+encodeURIComponent(v),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(rows){
        list=rows||[];
        res.innerHTML=list.length?list.map(function(r,i){
          var paid=r.flows.filter(function(f){return f.paid;}).length;
          return '<button type="button" class="cp-rate" data-i="'+i+'" style="text-align:left;cursor:pointer;color:inherit;font:inherit;width:100%">'
            +'<span class="cp-seq">'+esc(r.number)+'</span><span class="cp-main"><b>'+esc(r.customer)+'</b>'
            +'<span class="muted small">'+dmy(r.date)+' · '+eur(r.gross)+' · '+r.flows.length+' '+esc(L.rate)+' ('+paid+' '+esc(L.paid)+')</span></span></button>';
        }).join(''):'<div class="muted small">'+esc(L.none)+'</div>';
      }).catch(function(){ res.innerHTML=''; });
    },300);
  });
  res.addEventListener('click',function(e){ var b=e.target.closest('[data-i]'); if(!b) return; picked=list[+b.dataset.i]; showPick(); }); }

  // ---- manual instalments ----
  var rows=document.getElementById('cp-rows');
  function addRow(due,amount){
    var d=document.createElement('div'); d.className='cp-rate';
    d.innerHTML='<span class="cp-seq"></span>'
      +'<label class="fld" style="margin:0;flex:1;min-width:140px"><span>'+esc(L.due)+'</span><input type="date" name="rate_due[]"></label>'
      +'<label class="fld" style="margin:0;flex:1;min-width:140px"><span>'+esc(L.amount)+'</span><input name="rate_amount[]" inputmode="decimal" placeholder="0,00"></label>'
      +'<button type="button" class="btn tiny ghost" style="color:var(--red)" aria-label="✕">✕</button>';
    d.querySelector('input[type=date]').value=due||'';
    d.querySelector('input[name="rate_amount[]"]').value=amount||'';
    d.querySelector('button').onclick=function(){ d.remove(); renumber(); preview(); };
    rows.appendChild(d); renumber();
  }
  function renumber(){ rows.querySelectorAll('.cp-seq').forEach(function(s,i){ s.textContent=(i+1); }); }
  function addMonths(iso,m){ var p=iso.split('-').map(Number), d=new Date(p[0],p[1]-1+m,1);
    var last=new Date(d.getFullYear(),d.getMonth()+1,0).getDate(); d.setDate(Math.min(p[2],last));
    return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
  document.getElementById('cp-gen').onclick=function(){
    var total=num(document.getElementById('cp-g-total').value), n=Math.max(1,Math.min(120,parseInt(document.getElementById('cp-g-n').value,10)||1));
    var first=document.getElementById('cp-g-first').value, every=Math.max(0,parseInt(document.getElementById('cp-g-every').value,10)||0);
    rows.innerHTML='';
    var each=Math.floor(total/n*100)/100, sum=0;
    for(var i=0;i<n;i++){
      var a=i===n-1?Math.round((total-sum)*100)/100:each; sum=Math.round((sum+a)*100)/100;
      addRow(first?addMonths(first,i*every):'', total>0?a.toFixed(2).replace('.',','):'');
    }
    preview();
  };
  document.getElementById('cp-add').onclick=function(){ addRow('',''); };
  addRow('',''); addRow('',''); addRow('','');

  form.addEventListener('input',function(e){ if(e.target.id!=='cp-inv-q') preview(); });
  form.addEventListener('change',sync);
  var ps=document.getElementById('cp-payee'), cb=document.getElementById('cp-noinv');
  if(ps&&cb){ var ni=(ps.dataset.noinv||'').split(','); ps.addEventListener('change',function(){ cb.checked=ps.value!==''&&ni.indexOf(ps.value)!==-1; }); }
  form.addEventListener('submit',function(e){
    if(src()==='sibill'&&!picked){ e.preventDefault(); e.stopImmediatePropagation(); q.focus(); alert(L.pick); }
  },true);
  sync();
})();
</script>
<style>
/* .row / .cm-strip / .cp-rates set their own display, which beats [hidden] */
#cp-form [hidden]{display:none!important}
.cm-drawers{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start}
.cm-drawers>details[open]{flex-basis:100%}
.cm-chip input{margin-right:4px;vertical-align:-1px}
</style>
