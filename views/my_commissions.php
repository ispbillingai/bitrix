<?php
/**
 * Le mie provvigioni — an agent's commission statements from the office:
 * download the calculation, send the invoice, follow the payment. Only the
 * agent's own (Statements::forPayee with their own id); the invoice upload is
 * checked against them again on the way in.
 *
 * In scope: $t, $h, $uid.
 */

use Glue\Commission\Plans;
use Glue\Commission\Statements;

$me = (int)$uid;
?>
<h2><?= $h($t('nav_my_commissions')) ?></h2>
<?php if ($me <= 0): ?>
  <div class="card"><div class="empty"><?= $h($t('cm_need_account')) ?></div></div>
  <?php return; ?>
<?php endif; ?>
<?php
$rows   = Statements::forPayee('agent', $me);
$tot    = Statements::totals('agent', $me);
$openId = (int)($_GET['st'] ?? 0);
$m      = fn($n): string => Statements::money((float)$n);
// Commissions paid as the customer pays: shown with their instalments, the
// cancelled ones left out — nothing more will come of them.
$plans  = array_values(array_filter(Plans::all(['payee' => ['agent', $me]]), fn($p) => $p['status'] !== 'cancelled'));
$wait   = Plans::waiting('agent', $me);
?>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('cm_my_sub')) ?></p>

<div class="grid" style="margin-bottom:16px">
  <div class="tile"><div class="tile-top"><?= svg('clock') ?><span><?= $h($t('cm_to_invoice')) ?></span></div>
    <span class="big" style="color:var(--amber)"><?= $h($m($tot['sent'])) ?></span>
    <div class="sub"><?= (int)$tot['n_sent'] ?> <?= $h($t('cm_statements')) ?></div></div>
  <div class="tile"><div class="tile-top"><?= svg('invoices') ?><span><?= $h($t('cm_awaiting')) ?></span></div>
    <span class="big" style="color:var(--accent)"><?= $h($m($tot['invoiced'])) ?></span>
    <div class="sub"><?= (int)$tot['n_invoiced'] ?> <?= $h($t('cm_statements')) ?></div></div>
  <div class="tile"><div class="tile-top"><?= svg('money') ?><span><?= $h($t('cm_paid_total')) ?></span></div>
    <span class="big" style="color:var(--green)"><?= $h($m($tot['paid'])) ?></span>
    <div class="sub"><?= (int)$tot['n_paid'] ?> <?= $h($t('cm_statements')) ?></div></div>
  <?php if ($plans): ?>
  <div class="tile"><div class="tile-top"><?= svg('reminders') ?><span><?= $h($t('cp_waiting_tile')) ?></span></div>
    <span class="big" style="color:var(--muted)"><?= $h($m($wait['amount'])) ?></span>
    <div class="sub"><?= (int)$wait['n'] ?> <?= $h($t('cp_rates_word')) ?></div></div>
  <?php endif; ?>
</div>

<?php if (!$rows): ?><div class="card"><div class="empty"><?= $h($t('cm_none_mine')) ?></div></div><?php endif; ?>
<?php foreach ($rows as $s): ?>
  <?= commission_card($s, $t, $h, commission_invoice_form($s, $t, $h, 'cm_invoice'),
        $openId === (int)$s['id'] || ($openId === 0 && $s['status'] === 'sent')) ?>
<?php endforeach; ?>

<?php if ($plans): ?>
  <h3 style="margin-top:22px"><?= svg('reminders') ?> <?= $h($t('cp_section')) ?></h3>
  <p class="muted small" style="margin:-6px 0 12px"><?= $h($t('cp_payee_sub')) ?></p>
  <?php foreach ($plans as $p): ?>
    <?= commission_plan_card($p, $t, $h, '?tab=my_commissions', null, '', false, false, '?cpf=' . (int)$p['id']) ?>
  <?php endforeach; ?>
<?php endif; ?>
<script>if(location.hash.indexOf('#cp-')===0){var cpd=document.querySelector(location.hash);if(cpd){cpd.open=true;}}</script>
