<?php
/**
 * Customers — the registry of everyone the business serves: the ~10,000 clienti
 * imported from the gestionale plus every deal the CRM wins. Identified by
 * customer code + VAT number; the detail page pulls together everything already
 * linked to the contact — Sibill invoices, SmallPay support contracts, the
 * internal chat with its documents, signed documents, pipeline history and the
 * customer's routers.
 *
 * In scope: $t, $h, $pdo, $agents, $uid. Admin-only (not in $agentViews).
 */

use Glue\Crm\Customers;

$eur   = fn($n, $cur = 'EUR') => $h(($cur ?: 'EUR') . ' ' . number_format((float)$n, 2, ',', '.'));
$eurC  = fn($cents, $cur = 'EUR') => $h(($cur ?: 'EUR') . ' ' . number_format(((int)$cents) / 100, 2, ',', '.'));
$dash  = '<span class="muted">—</span>';

$custId = (int)($_GET['id'] ?? 0);
$ov     = $custId > 0 ? Customers::overview($custId) : null;

if ($ov !== null):
    // ======================= one customer =======================
    $c = $ov['contact'];
    $activeContract = null;
    foreach ($ov['contracts'] as $pc) {
        if (in_array($pc['status'], ['active', 'past_due'], true)) { $activeContract = $pc; break; }
    }
    $routersDown = array_sum(array_map(fn($a) => (int)$a['devices_down'] > 0 ? 1 : 0, $ov['areas']));
    $openTickets = count(array_filter($ov['tickets'], fn($tk) => $tk['status'] !== 'closed'));
?>
<div class="cu-top">
  <a class="btn ghost tiny" href="?tab=customers">&larr; <?= $h($t('cu_back')) ?></a>
  <h2 style="margin:0"><?= avatar($h, $c['name']) ?> <?= $h($c['name']) ?>
    <?php if (!empty($c['customer_code'])): ?><span class="pill"><?= $h($t('cu_code')) ?> <?= $h($c['customer_code']) ?></span><?php endif; ?>
    <?php if (!empty($c['vat_number'])): ?><span class="pill"><?= $h($t('cu_vat')) ?> <?= $h($c['vat_number']) ?></span><?php endif; ?>
  </h2>
  <?php // Straight into the conversation from here. The newest thread when there
        // is one, otherwise the new-message form with this customer already
        // chosen — reaching the chat used to mean going to Ticket and finding
        // them again in a list of ten thousand. ?>
  <span class="cu-acts">
    <?php $cuNewest = $ov['tickets'][0] ?? null; ?>
    <?php if ($cuNewest): ?>
      <a class="btn tiny" href="?tab=tickets&tk=<?= (int)$cuNewest['id'] ?>"><?= svg('chat') ?> <?= $h($t('cu_open_chat')) ?></a>
    <?php endif; ?>
    <a class="btn ghost tiny" href="?tab=tickets&to=<?= (int)$custId ?>"><?= svg('send') ?> <?= $h($t('cu_new_msg')) ?></a>
    <?php // Straight into the calendar with this customer already chosen. The
          // name rides along so the picker shows who it is without a second
          // lookup; the id is what actually links the appointment. ?>
    <a class="btn ghost tiny" href="?tab=calendar&new=1&contact=<?= (int)$custId ?>&cname=<?= urlencode((string)$c['name']) ?>">
      <?= svg('appointments') ?> <?= $h($t('cu_book')) ?></a>
  </span>
</div>

<div class="grid stats">
  <?php stat_card($h, 'invoices', $t('cu_open_invoices'), 'EUR ' . number_format($ov['owed'], 2, ',', '.'), $ov['owed'] <= 0.009); ?>
  <?php
  // The support tile answers from either source: a live SmallPay subscription
  // wins; otherwise the gestionale contract speaks through its expiry date.
  $ctExpiry = (string)($c['contract_expiry'] ?? '');
  $ctValid  = $ctExpiry !== '' && $ctExpiry >= date('Y-m-d');
  stat_card($h, 'payments', $t('cu_support'), $activeContract
      ? $eurC($activeContract['amount_cents'], $activeContract['currency']) . ' / ' . $t('pay_per_month')
      : ($ctExpiry !== ''
          ? sprintf($t($ctValid ? 'cu_until' : 'cu_expired_on'), date('d/m/Y', strtotime($ctExpiry)))
          : $t('cu_no_contract')),
      $activeContract !== null || $ctValid); ?>
  <?php stat_card($h, 'devices', $t('cu_routers'), count($ov['areas']) . ($routersDown ? ' (' . $routersDown . ' ⚠)' : ''), $routersDown === 0); ?>
  <?php stat_card($h, 'tickets', $t('cu_open_tickets'), (string)$openTickets, $openTickets === 0); ?>
</div>

<?php // ---- the machines this customer has (081) ---------------------------
      // Typed in by the office: brand, model, serial. Not the LAN devices the
      // monitoring pings — "le macchine non sono queste" — and not a technician's
      // report, though a report is where the serial can be lifted from.
      $mcs    = \Glue\Crm\Machines::forContact($custId);
      $mcEdit = (int)($_GET['mc'] ?? 0);
      $mcOne  = $mcEdit > 0 ? \Glue\Crm\Machines::find($mcEdit) : null;
      $mcOpen = isset($_GET['mc_new']) || $mcOne !== null;
      $mcSugg = $mcOpen && !$mcOne ? \Glue\Crm\Machines::fromReports($custId) : []; ?>
<div class="card" id="machines">
  <h3><?= svg('devices') ?> <?= $h($t('mc_h')) ?>
    <?php $mcLive = count(array_filter($mcs, fn($m) => $m['status'] === 'active')); ?>
    <?php if ($mcLive): ?><span class="pill pill-up"><?= (int)$mcLive ?></span><?php endif; ?>
  </h3>
  <p class="muted small" style="margin:0 0 12px"><?= $h($t('mc_h_sub')) ?></p>

  <?php if ($mcs): ?>
    <table><thead><tr>
      <th><?= $h($t('mc_what')) ?></th><th><?= $h($t('mc_brand')) ?></th><th><?= $h($t('mc_model')) ?></th>
      <th><?= $h($t('mc_serial')) ?></th><th><?= $h($t('mc_where')) ?></th><th><?= $h($t('th_status')) ?></th><th></th>
    </tr></thead><tbody>
    <?php foreach ($mcs as $mc): ?>
      <tr<?= $mc['status'] === 'dismissed' ? ' style="opacity:.55"' : '' ?>>
        <td><a href="?tab=customers&amp;id=<?= (int)$custId ?>&amp;mc=<?= (int)$mc['id'] ?>#machines">
            <b><?= $h($mc['label'] ?: ($mc['kind'] ? $t('mc_k_' . $mc['kind']) : $t('mc_unnamed'))) ?></b></a>
          <?php if (!empty($mc['label']) && !empty($mc['kind'])): ?><br><span class="muted small"><?= $h($t('mc_k_' . $mc['kind'])) ?></span><?php endif; ?></td>
        <td><?= $h($mc['brand'] ?? '') ?: $dash ?></td>
        <td><?= $h($mc['model'] ?? '') ?: $dash ?></td>
        <td class="small"><?= $mc['serial'] ? '<code>' . $h($mc['serial']) . '</code>' : $dash ?></td>
        <td class="small"><?= $h($mc['location'] ?? '') ?: $dash ?>
          <?php if (!empty($mc['installed_on'])): ?><br><span class="muted"><?= $h($t('mc_since')) ?> <?= $h(date('d/m/Y', strtotime((string)$mc['installed_on']))) ?></span><?php endif; ?></td>
        <td><span class="pill" style="color:<?= $mc['status'] === 'active' ? 'var(--green)' : 'var(--muted)' ?>">
          <?= $h($t('mc_st_' . $mc['status'])) ?></span></td>
        <td class="small"><a class="btn ghost tiny" href="?tab=customers&amp;id=<?= (int)$custId ?>&amp;mc=<?= (int)$mc['id'] ?>#machines"><?= svg('pen') ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php else: ?>
    <p class="muted small"><?= $h($t('mc_none')) ?></p>
  <?php endif; ?>

  <?php if (!$mcOpen): ?>
    <a class="btn tiny" href="?tab=customers&amp;id=<?= (int)$custId ?>&amp;mc_new=1#machines"><?= $h($t('mc_add')) ?></a>
  <?php else: ?>
    <?php if ($mcSugg): ?>
      <div class="warn" style="margin-top:14px">
        <?= $h($t('mc_from_reports')) ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          <?php foreach ($mcSugg as $sg): ?>
            <a class="btn tiny ghost" href="?tab=customers&amp;id=<?= (int)$custId ?>&amp;mc_new=1&amp;from=<?= (int)$sg['report_id'] ?>#machines">
              <?= $h($sg['model'] ?: $t('mc_unnamed')) ?> · <?= $h($sg['serial']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    <?php
    // Picking one of those fills the form with what the technician wrote, so a
    // serial is read off the record instead of typed a second time.
    $mcPre = ['model' => '', 'serial' => '', 'report' => 0];
    if (!$mcOne && (int)($_GET['from'] ?? 0) > 0) {
        foreach (\Glue\Crm\Machines::fromReports($custId) as $sg) {
            if ($sg['report_id'] === (int)$_GET['from']) {
                $mcPre = ['model' => $sg['model'], 'serial' => $sg['serial'], 'report' => $sg['report_id']];
            }
        }
    }
    $mcV = fn(string $k, string $alt = '') => $h($mcOne[$k] ?? ($alt !== '' ? $alt : '')); ?>
    <form method="post" class="card" style="margin:14px 0 0">
      <input type="hidden" name="do" value="machine_save">
      <input type="hidden" name="id" value="<?= (int)$custId ?>">
      <input type="hidden" name="machine_id" value="<?= (int)($mcOne['id'] ?? 0) ?>">
      <input type="hidden" name="install_report_id" value="<?= (int)$mcPre['report'] ?>">
      <b class="small"><?= $h($t($mcOne ? 'mc_edit' : 'mc_add')) ?></b>
      <div class="row" style="margin-top:10px">
        <label class="fld" style="max-width:220px"><span><?= $h($t('mc_kind')) ?></span>
          <select name="kind">
            <option value=""><?= $h($t('mc_kind_pick')) ?></option>
            <?php foreach (\Glue\Crm\Machines::KINDS as $kk): ?>
              <option value="<?= $h($kk) ?>"<?= ($mcOne['kind'] ?? '') === $kk ? ' selected' : '' ?>><?= $h($t('mc_k_' . $kk)) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="fld"><span><?= $h($t('mc_brand')) ?></span>
          <input name="brand" maxlength="60" list="mc-brands" value="<?= $mcV('brand') ?>" placeholder="<?= $h($t('mc_brand_ph')) ?>"></label>
        <label class="fld"><span><?= $h($t('mc_model')) ?></span>
          <input name="model" maxlength="80" value="<?= $mcV('model', $mcPre['model']) ?>" placeholder="<?= $h($t('mc_model_ph')) ?>"></label>
      </div>
      <div class="row">
        <label class="fld"><span><?= $h($t('mc_serial')) ?></span>
          <input name="serial" maxlength="80" value="<?= $mcV('serial', $mcPre['serial']) ?>" placeholder="<?= $h($t('mc_serial_ph')) ?>">
          <small class="muted"><?= $h($t('mc_serial_h')) ?></small></label>
        <label class="fld"><span><?= $h($t('mc_label')) ?></span>
          <input name="label" maxlength="80" value="<?= $mcV('label') ?>" placeholder="<?= $h($t('mc_label_ph')) ?>"></label>
        <label class="fld"><span><?= $h($t('mc_where')) ?></span>
          <input name="location" maxlength="120" value="<?= $mcV('location') ?>" placeholder="<?= $h($t('mc_where_ph')) ?>"></label>
      </div>
      <div class="row">
        <label class="fld" style="max-width:200px"><span><?= $h($t('mc_installed')) ?></span>
          <input type="date" name="installed_on" value="<?= $mcV('installed_on') ?>"></label>
        <label class="fld"><span><?= $h($t('f_notes')) ?></span>
          <input name="notes" maxlength="500" value="<?= $mcV('notes') ?>" placeholder="<?= $h($t('mc_notes_ph')) ?>"></label>
      </div>
      <?php if ($mcOne): ?>
        <label class="cm-chip" style="margin:4px 0 0">
          <input type="checkbox" name="status" value="dismissed" style="width:auto"<?= ($mcOne['status'] ?? '') === 'dismissed' ? ' checked' : '' ?>>
          <span><?= $h($t('mc_dismissed')) ?></span></label>
      <?php endif; ?>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px">
        <button class="btn"><?= svg('check') ?> <?= $h($t('save')) ?></button>
        <a class="btn ghost" href="?tab=customers&amp;id=<?= (int)$custId ?>#machines"><?= $h($t('cancel')) ?></a>
        <?php if ($mcOne): ?>
          <button class="btn ghost" style="color:var(--red);margin-left:auto" formnovalidate
                  name="do" value="machine_delete"
                  onclick="return confirm(<?= $h(json_encode($t('mc_del_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
            <?= $h($t('delete')) ?></button>
        <?php endif; ?>
      </div>
    </form>
    <datalist id="mc-brands">
      <?php foreach (['Cashmatic', 'Epson', 'Berkel', 'Olivetti', 'RCH', 'Custom', 'Ditron', 'Zucchetti'] as $bb): ?>
        <option value="<?= $h($bb) ?>">
      <?php endforeach; ?>
    </datalist>
  <?php endif; ?>
</div>

<?php // ---- the contracts this customer holds (080) -------------------------
      // Plural, and independent of each other: an H24 on the two Cashmatic
      // machines paid by SDD, and a basic one on the fiscal printer paid by
      // transfer, are two contracts and the card shows them as two.
      $cts     = \Glue\Crm\Contracts::forContact($custId);
      $ctEdit  = (int)($_GET['ct'] ?? 0);
      $ctOne   = $ctEdit > 0 ? \Glue\Crm\Contracts::find($ctEdit) : null;
      $ctNew   = isset($_GET['ct_new']) || ($ctEdit > 0 && !$ctOne);
      $ctOpen  = $ctOne !== null || $ctNew;
      $ctPick  = $ctOpen ? \Glue\Crm\Contracts::machinePicker($custId) : [];
      $ctHas   = array_column($ctOne['machines'] ?? [], 'id');
      $ctState = ['active' => 'var(--green)', 'expired' => 'var(--amber)', 'cancelled' => 'var(--muted)']; ?>
<div class="card" id="contracts">
  <h3><?= svg('documents') ?> <?= $h($t('ct_h')) ?>
    <?php $ctLive = count(array_filter($cts, fn($c) => $c['state'] === 'active')); ?>
    <?php if ($ctLive): ?><span class="pill pill-up"><?= (int)$ctLive ?> <?= $h($t('ct_active')) ?></span><?php endif; ?>
  </h3>
  <p class="muted small" style="margin:0 0 12px"><?= $h($t('ct_h_sub')) ?></p>

  <?php if ($cts): // the wrapper that makes it scroll on a phone is added by render_foot ?>
    <table><thead><tr>
      <th><?= $h($t('ct_name')) ?></th><th><?= $h($t('ct_devices')) ?></th>
      <th><?= $h($t('ct_amount')) ?></th><th><?= $h($t('ct_method')) ?></th>
      <th><?= $h($t('ct_period_col')) ?></th><th><?= $h($t('th_status')) ?></th><th></th>
    </tr></thead><tbody>
    <?php foreach ($cts as $c): ?>
      <tr>
        <td><a href="?tab=customers&amp;id=<?= (int)$custId ?>&amp;ct=<?= (int)$c["id"] ?>#contracts"><b><?= $h($c["name"]) ?></b></a>
          <?php if (!empty($c['notes'])): ?><br><span class="muted small"><?= $h($c['notes']) ?></span><?php endif; ?></td>
        <td class="small">
          <?php if ($c['machines']): ?>
            <?php foreach ($c['machines'] as $dv): ?>
              <span class="pill" title="<?= $h(trim((string)($dv['serial'] ?? '') . ' ' . (string)($dv['location'] ?? ''))) ?>">
                <?= $h(\Glue\Crm\Machines::title($dv)) ?></span>
            <?php endforeach; ?>
          <?php else: ?><?= $dash ?><?php endif; ?></td>
        <td><?= $c['amount_cents'] !== null ? $eurC($c['amount_cents'], $c['currency']) : $dash ?></td>
        <td class="small"><?= $h($t('ct_pm_' . $c['payment_method'])) ?></td>
        <td class="small"><?= $h($t('ct_per_' . $c['period'])) ?>
          <?php if (!empty($c['expires_on'])): ?><br><span class="muted"><?= $h($t('ct_until')) ?> <?= $h(date('d/m/Y', strtotime((string)$c['expires_on']))) ?></span><?php endif; ?></td>
        <td><span class="pill" style="color:<?= $h($ctState[$c['state']]) ?>"><?= $h($t('ct_st_' . $c['state'])) ?></span></td>
        <td class="small"><a class="btn ghost tiny" href="?tab=customers&id=<?= (int)$custId ?>&ct=<?= (int)$c['id'] ?>#contracts"><?= svg('pen') ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php else: ?>
    <p class="muted small"><?= $h($t('ct_none')) ?></p>
  <?php endif; ?>

  <?php if (!$ctOpen): ?>
    <a class="btn tiny" href="?tab=customers&id=<?= (int)$custId ?>&ct_new=1#contracts"><?= $h($t('ct_add')) ?></a>
  <?php else: ?>
    <form method="post" class="card" style="margin:14px 0 0">
      <input type="hidden" name="do" value="contract_save">
      <input type="hidden" name="id" value="<?= (int)$custId ?>">
      <input type="hidden" name="contract_id" value="<?= (int)($ctOne['id'] ?? 0) ?>">
      <b class="small"><?= $h($t($ctOne ? 'ct_edit' : 'ct_add')) ?></b>
      <div class="row" style="margin-top:10px">
        <label class="fld"><span><?= $h($t('ct_name')) ?> *</span>
          <input name="name" required maxlength="120" list="ct-names" value="<?= $h($ctOne['name'] ?? '') ?>"
                 placeholder="<?= $h($t('ct_name_ph')) ?>"></label>
        <label class="fld" style="max-width:180px"><span><?= $h($t('ct_amount')) ?></span>
          <input name="amount" inputmode="decimal" placeholder="0,00"
                 value="<?= $ctOne && $ctOne['amount_cents'] !== null ? $h(number_format(((int)$ctOne['amount_cents']) / 100, 2, ',', '')) : '' ?>"></label>
        <label class="fld" style="max-width:190px"><span><?= $h($t('ct_period')) ?></span>
          <select name="period">
            <?php foreach (\Glue\Crm\Contracts::PERIODS as $pk): ?>
              <option value="<?= $h($pk) ?>"<?= ($ctOne['period'] ?? 'year') === $pk ? ' selected' : '' ?>><?= $h($t('ct_per_' . $pk)) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="fld" style="max-width:200px"><span><?= $h($t('ct_method')) ?></span>
          <select name="payment_method">
            <?php foreach (\Glue\Crm\Contracts::METHODS as $mk): ?>
              <option value="<?= $h($mk) ?>"<?= ($ctOne['payment_method'] ?? 'transfer') === $mk ? ' selected' : '' ?>><?= $h($t('ct_pm_' . $mk)) ?></option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <div class="row">
        <label class="fld" style="max-width:190px"><span><?= $h($t('ct_from')) ?></span>
          <input type="date" name="started_on" value="<?= $h($ctOne['started_on'] ?? '') ?>"></label>
        <label class="fld" style="max-width:190px"><span><?= $h($t('ct_to')) ?></span>
          <input type="date" name="expires_on" value="<?= $h($ctOne['expires_on'] ?? '') ?>">
          <small class="muted"><?= $h($t('ct_to_h')) ?></small></label>
        <label class="fld"><span><?= $h($t('f_notes')) ?></span>
          <input name="notes" maxlength="500" value="<?= $h($ctOne['notes'] ?? '') ?>"></label>
      </div>

      <b class="small"><?= $h($t('ct_devices')) ?></b>
      <p class="muted small" style="margin:4px 0 8px"><?= $h($t('ct_devices_h')) ?></p>
      <?php if ($ctPick): ?>
        <input type="search" id="ct-dev-q" placeholder="<?= $h($t('ct_devices_find')) ?>" style="margin-bottom:8px">
        <div class="ct-devs">
          <?php foreach ($ctPick as $dv): $dvT = \Glue\Crm\Machines::title($dv); ?>
            <label class="cm-chip ct-dev" data-k="<?= $h(mb_strtolower($dvT . ' ' . (string)($dv['serial'] ?? '') . ' ' . (string)($dv['location'] ?? ''))) ?>">
              <input type="checkbox" name="machine_ids[]" value="<?= (int)$dv['id'] ?>" style="width:auto"
                     <?= in_array((int)$dv['id'], array_map('intval', $ctHas), true) ? 'checked' : '' ?>>
              <span><?= $h($dvT) ?><?php if (!empty($dv['serial'])): ?> <span class="muted small">· <?= $h($dv['serial']) ?></span><?php endif; ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="muted small"><?= $h($t('ct_devices_none')) ?>
          <a href="?tab=customers&amp;id=<?= (int)$custId ?>&amp;mc_new=1#machines"><?= $h($t('mc_add')) ?></a></p>
      <?php endif; ?>

      <?php if ($ctOne): ?>
        <label class="cm-chip" style="margin:12px 0 0">
          <input type="checkbox" name="status" value="cancelled" style="width:auto"<?= ($ctOne['status'] ?? '') === 'cancelled' ? ' checked' : '' ?>>
          <span><?= $h($t('ct_cancelled')) ?></span></label>
      <?php endif; ?>

      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px">
        <button class="btn"><?= svg('check') ?> <?= $h($t('save')) ?></button>
        <a class="btn ghost" href="?tab=customers&id=<?= (int)$custId ?>#contracts"><?= $h($t('cancel')) ?></a>
        <?php if ($ctOne): ?>
          <button class="btn ghost" style="color:var(--red);margin-left:auto" formnovalidate
                  name="do" value="contract_delete"
                  onclick="return confirm(<?= $h(json_encode($t('ct_del_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
            <?= $h($t('delete')) ?></button>
        <?php endif; ?>
      </div>
    </form>
    <datalist id="ct-names">
      <?php foreach (['Assistenza Base', 'Assistenza Full Risk', 'Assistenza H24', 'Noleggio', 'Comodato d’uso'] as $sug): ?>
        <option value="<?= $h($sug) ?>">
      <?php endforeach; ?>
    </datalist>
    <script>
    (function () {
      var q = document.getElementById('ct-dev-q');
      if (!q) { return; }
      q.addEventListener('input', function () {
        var v = q.value.trim().toLowerCase();
        document.querySelectorAll('.ct-dev').forEach(function (el) {
          el.hidden = v !== '' && (el.getAttribute('data-k') || '').indexOf(v) < 0
                      && !el.querySelector('input').checked;
        });
      });
    })();
    </script>
  <?php endif; ?>
</div>

<?php // ---- maintenance contract -------------------------------------------
      // The one panel that answers "what are we to this customer?" — a periodic
      // contract, or A CHIAMATA, which is an answer and not a blank.
      $mt   = \Glue\Crm\Maintenance::forContact($custId);
      $onD  = $mt['type'] === \Glue\Crm\Maintenance::ON_DEMAND;
      $last = \Glue\Crm\Maintenance::lastServiceAt($custId);
      $chases = \Glue\Crm\Maintenance::followUps($custId, 3);
      $mtLabel = $mt['label'] !== '' ? $mt['label'] : $t('mt_type_' . strtolower($mt['type'])); ?>
<div class="card" style="border-left:3px solid <?= $onD ? 'var(--amber)' : 'var(--green)' ?>">
  <h3><?= svg('payments') ?> <?= $h($t('mt_h')) ?>
    <span class="pill" style="color:<?= $onD ? 'var(--amber)' : 'var(--green)' ?>"><?= $h($mtLabel) ?></span>
  </h3>
  <dl class="cm-kv">
    <dt><?= $h($t('mt_type')) ?></dt><dd><b><?= $h($mtLabel) ?></b>
      <?php if ($mt['source'] !== 'none'): ?>
        <span class="muted small">· <?= $h($t('mt_src_' . $mt['source'])) ?></span><?php endif; ?></dd>

    <dt><?= $h($t('mt_fee')) ?></dt>
    <dd><?= $mt['fee_cents'] !== null
          ? $eurC($mt['fee_cents'], $mt['currency'])
            . ($mt['period'] ? ' / ' . $h($t('mt_per_' . $mt['period'])) : '')
          : ($onD ? $h($t('mt_fee_per_visit')) : $dash) ?></dd>

    <?php if (!empty($mt['expires_at'])): ?>
      <dt><?= $h($t('mt_expires')) ?></dt>
      <dd><?= $h(date('d/m/Y', strtotime((string)$mt['expires_at']))) ?></dd>
    <?php endif; ?>

    <dt><?= $h($t('mt_last_service')) ?></dt>
    <dd><?= $last ? $h(date('d/m/Y', strtotime($last))) : '<span class="muted">' . $h($t('mt_never')) . '</span>' ?></dd>

    <?php if ($onD): ?>
      <dt><?= $h($t('mt_next_chase')) ?></dt>
      <dd><?php
        // The clock runs from the later of the last visit and the last chase —
        // the same rule the cron uses, so the record cannot disagree with it.
        $anchor = $last;
        if ($chases && (string)$chases[0]['sent_at'] > (string)$anchor) { $anchor = (string)$chases[0]['sent_at']; }
        if (!$anchor) {
          echo '<span class="muted">' . $h($t('mt_no_anchor')) . '</span>';
        } else {
          $next = strtotime($anchor . ' +' . \Glue\Crm\Maintenance::months() . ' months');
          echo $h(date('d/m/Y', $next)) . ($next <= time()
              ? ' <span class="pill" style="color:var(--amber)">' . $h($t('mt_due_now')) . '</span>' : '');
        } ?></dd>
    <?php endif; ?>
    <?php if (!empty($mt['note'])): ?>
      <dt><?= $h($t('f_notes')) ?></dt><dd><?= $h($mt['note']) ?></dd>
    <?php endif; ?>
  </dl>

  <?php if ($chases): ?>
    <p class="muted small" style="margin:8px 0 0"><?= $h($t('mt_chases')) ?>:
      <?php foreach ($chases as $ch): ?>
        <?= $h(date('d/m/Y', strtotime((string)$ch['sent_at']))) ?><?= (int)$ch['messaged'] ? ' 💬' : '' ?><?= !empty($ch['task_id']) ? ' 📋' : '' ?>&nbsp;
      <?php endforeach; ?></p>
  <?php endif; ?>

  <?php // The office's own entry, for a contract the CRM cannot derive. ?>
  <details class="drawer" style="margin-top:10px">
    <summary class="btn ghost tiny"><?= $h($t('mt_edit')) ?></summary>
    <form method="post" class="card" style="margin-top:10px">
      <input type="hidden" name="do" value="maint_save">
      <input type="hidden" name="id" value="<?= (int)$custId ?>">
      <div class="row">
        <label class="fld"><span><?= $h($t('mt_type')) ?></span>
          <input name="maint_type" value="<?= $h($c['maint_type'] ?? '') ?>"
                 placeholder="<?= $h($t('mt_type_ph')) ?>" list="mtTypes">
          <datalist id="mtTypes">
            <option value="ON_DEMAND"><option value="Contratto di manutenzione">
            <option value="Contratto Helpdesk"><option value="Full service">
          </datalist></label>
        <label class="fld"><span><?= $h($t('mt_fee_eur')) ?></span>
          <input name="maint_fee" type="number" step="0.01" min="0"
                 value="<?= $c['maint_fee_cents'] !== null ? number_format(((int)$c['maint_fee_cents']) / 100, 2, '.', '') : '' ?>"></label>
        <label class="fld"><span><?= $h($t('mt_period')) ?></span>
          <select name="maint_period">
            <option value=""><?= $h($t('unassigned')) ?></option>
            <?php foreach (['monthly', 'quarterly', 'yearly'] as $pp): ?>
              <option value="<?= $pp ?>" <?= ($c['maint_period'] ?? '') === $pp ? 'selected' : '' ?>>
                <?= $h($t('mt_per_' . $pp)) ?></option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <label class="fld"><span><?= $h($t('f_notes')) ?></span>
        <input name="maint_note" value="<?= $h($c['maint_note'] ?? '') ?>"></label>
      <p class="muted small" style="margin:0 0 10px"><?= $h($t('mt_edit_h')) ?></p>
      <button class="btn tiny"><?= $h($t('save')) ?></button>
    </form>
  </details>
</div>

<div class="cu-cols">
<div class="cu-main">

  <!-- ---- invoices (Sibill) ---- -->
  <div class="card">
    <h3><?= svg('invoices') ?> <?= $h($t('nav_invoices')) ?>
      <?php if ($ov['overdue'] > 0): ?><span class="pill pill-unpaid"><?= (int)$ov['overdue'] ?> <?= $h($t('cu_overdue')) ?></span><?php endif; ?>
    </h3>
    <?php if (!$ov['invoices']): ?><p class="muted small"><?= $h($t('cu_none')) ?></p>
    <?php else: ?>
    <table><thead><tr>
      <th><?= $h($t('inv_th_number')) ?></th><th><?= $h($t('cu_date')) ?></th>
      <th><?= $h($t('inv_th_amount')) ?></th><th><?= $h($t('inv_th_open')) ?></th>
      <th><?= $h($t('inv_th_due')) ?></th><th><?= $h($t('th_status')) ?></th>
    </tr></thead><tbody>
    <?php foreach ($ov['invoices'] as $i): ?>
      <tr>
        <td><?= $h($i['number'] ?? '') ?: $dash ?><?= $i['doc_type'] === 'CREDIT_NOTE' ? ' <span class="pill">NC</span>' : '' ?></td>
        <td class="small"><?= $h($i['creation_date'] ?? '') ?: $dash ?></td>
        <td><?= $eur($i['gross_amount'], $i['currency']) ?></td>
        <td><?= (float)$i['open_amount'] > 0 ? '<b>' . $eur($i['open_amount'], $i['currency']) . '</b>' : $dash ?></td>
        <td class="small"><?= $h($i['due_date'] ?? '') ?: $dash ?></td>
        <td><?= pill($h, (string)$i['pay_state'], $t) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

  <!-- ---- certified reminder (PEC) ----
       Where the unpaid invoices are, because that is where somebody decides to
       send one. The letter is drafted by the CRM and SENT BY A PERSON: a
       sollecito per PEC is what puts a customer in mora, not a notification. -->
  <div class="card" id="pec">
    <h3><?= svg('mail') ?> <?= $h($t('pec_card')) ?></h3>
    <?php
    $pecOpen  = !empty($_GET['pec']);
    $pecSent  = \Glue\Notify\Pec::forContact($custId);
    $pecAddr  = trim((string)($c['pec'] ?? ''));
    $pecDraft = $pecOpen ? \Glue\Crm\Dunning::draft($custId) : null;
    $pecWhy   = $pecDraft['why'] ?? (\Glue\Notify\Pec::enabled()
        ? ($pecAddr === '' ? 'no_pec' : (\Glue\Notify\Pec::isPec($pecAddr) ? null : 'not_a_pec'))
        : 'pec_off');
    ?>
    <p class="muted small" style="margin:0 0 10px">
      <?= $h($t('pec_card_h')) ?>
      <?php if ($pecAddr !== ''): ?><br><b>PEC:</b> <?= $h($pecAddr) ?><?php endif; ?>
    </p>
    <?php if ($pecWhy !== null): ?>
      <p class="muted small" style="color:var(--amber);margin:0 0 10px"><?= $h($t('pec_why_' . $pecWhy)) ?></p>
    <?php endif; ?>

    <?php if ($pecOpen && $pecDraft !== null): ?>
      <form method="post">
        <input type="hidden" name="do" value="pec_send">
        <input type="hidden" name="id" value="<?= (int)$custId ?>">
        <div class="row">
          <label class="fld"><span><?= $h($t('pec_to')) ?></span>
            <input name="to" value="<?= $h($pecDraft['to']) ?>" <?= $pecDraft['to'] === '' ? '' : 'readonly' ?>></label>
          <label class="fld"><span><?= $h($t('pec_subject')) ?></span>
            <input name="subject" value="<?= $h($pecDraft['subject']) ?>" required></label>
        </div>
        <label class="fld"><span><?= $h($t('pec_body')) ?></span>
          <textarea name="body" rows="16" required style="font:13px/1.6 ui-monospace,Consolas,monospace"><?= $h($pecDraft['body']) ?></textarea>
          <small class="muted"><?= $h($t('pec_body_h')) ?></small></label>
        <button class="btn" <?= $pecDraft['can_send'] ? '' : 'disabled' ?>
                onclick="return confirm(<?= $h(json_encode($t('pec_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
          <?= svg('send') ?> <?= $h($t('pec_send')) ?></button>
        <a class="btn ghost" href="?tab=customers&id=<?= (int)$custId ?>#pec"><?= $h($t('cancel')) ?></a>
      </form>
    <?php else: ?>
      <?php $pecDebt = \Glue\Crm\Dunning::debt($custId); ?>
      <p class="small" style="margin:0 0 10px">
        <?= $h(sprintf($t('pec_owes'), $eur($pecDebt['total']),
            count($pecDebt['invoices']), count($pecDebt['rates']))) ?>
      </p>
      <a class="btn tiny<?= $pecWhy !== null ? ' ghost' : '' ?>"
         href="?tab=customers&id=<?= (int)$custId ?>&pec=1#pec"><?= svg('mail') ?> <?= $h($t('pec_prepare')) ?></a>
    <?php endif; ?>

    <?php if ($pecSent): ?>
      <table style="margin-top:12px"><thead><tr>
        <th><?= $h($t('cu_date')) ?></th><th><?= $h($t('pec_subject')) ?></th>
        <th><?= $h($t('th_status')) ?></th><th><?= $h($t('pec_proof')) ?></th>
      </tr></thead><tbody>
      <?php foreach ($pecSent as $pm): $pst = \Glue\Notify\Pec::stateOf($pm); ?>
        <tr>
          <td class="small"><?= $h($pm['created_at']) ?></td>
          <td class="small"><?= $h($pm['subject']) ?></td>
          <td><span class="pill <?= $pst === 'consegna' ? 'pill-done' : ($pst === 'errore' || $pst === 'failed' ? 'pill-down' : '') ?>">
            <?= $h($t('pec_st_' . $pst)) ?></span></td>
          <td class="small">
            <?php foreach ($pm['receipts'] as $rc): ?>
              <?php if (!empty($rc['eml_path'])): ?>
                <a href="?pecr=<?= (int)$rc['id'] ?>"><?= $h($t('pec_rc_' . $rc['kind'])) ?></a>
              <?php else: ?><?= $h($t('pec_rc_' . $rc['kind'])) ?><?php endif; ?>
            <?php endforeach; ?>
            <?= $pm['receipts'] ? '' : $dash ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </div>

  <!-- ---- support contracts (SmallPay) ---- -->
  <div class="card">
    <h3><?= svg('payments') ?> <?= $h($t('cu_contracts')) ?></h3>
    <?php if (!$ov['contracts']): ?><p class="muted small"><?= $h($t('cu_none')) ?></p>
    <?php else: ?>
    <table><thead><tr>
      <th><?= $h($t('pay_c_what')) ?></th><th><?= $h($t('pay_c_amount')) ?></th>
      <th><?= $h($t('pay_c_collected')) ?></th><th><?= $h($t('pay_c_next')) ?></th><th><?= $h($t('th_status')) ?></th><th></th>
    </tr></thead><tbody>
    <?php foreach ($ov['contracts'] as $pc): ?>
      <tr>
        <td><?= $h($pc['description']) ?> <span class="muted small"><?= $h($pc['kind']) ?></span></td>
        <td><?= $eurC($pc['amount_cents'], $pc['currency']) ?></td>
        <td class="small"><?= (int)$pc['cycles_paid'] ?><?= (int)$pc['total_cycles'] > 0 ? '/' . (int)$pc['total_cycles'] : '' ?>
            · <?= $eurC($pc['paid_cents'], $pc['currency']) ?></td>
        <td class="small"><?= $h($pc['next_charge_date'] ?? '') ?: $dash ?></td>
        <td><?= pill($h, (string)$pc['status'], $t) ?></td>
        <td class="small"><a class="btn ghost tiny" href="?tab=payments&q=<?= urlencode((string)$pc['reference']) ?>"><?= $h($t('cu_open')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

  <!-- ---- the chat: every ticket thread in full ---- -->
  <div class="card">
    <h3><?= svg('tickets') ?> <?= $h($t('cu_chat')) ?></h3>
    <?php if (!$ov['tickets']): ?><p class="muted small"><?= $h($t('cu_none')) ?></p><?php endif; ?>
    <?php foreach ($ov['tickets'] as $tk): ?>
      <details class="cu-tk" <?= $tk['status'] !== 'closed' ? 'open' : '' ?>>
        <summary>
          <span><b><?= $h($tk['subject']) ?></b> <span class="muted small">#<?= (int)$tk['id'] ?> · <?= $h(short_time($tk['updated_at'])) ?></span></span>
          <span><?= pill($h, (string)$tk['status'], $t) ?>
            <a class="btn ghost tiny" href="?tab=tickets&tk=<?= (int)$tk['id'] ?>"><?= $h($t('cu_open')) ?></a></span>
        </summary>
        <div class="cu-chatbox">
          <?php foreach ($tk['messages'] as $m): $mine = $m['sender_type'] !== 'customer'; ?>
            <div class="msg <?= $mine ? 'staff' : 'cust' ?>">
              <?php if ((string)$m['body'] !== ''): ?><div class="msg-b"><?= nl2br($h($m['body'])) ?></div><?php endif; ?>
              <?php if (!empty($m['sign_document_id'])): ?>
                <div class="msg-b">✍️ <a href="?sdl=<?= (int)$m['sign_document_id'] ?>&k=orig"><?= $h($m['sign_title'] ?: $t('dc_h_doc')) ?></a>
                  <?= pill($h, (string)($m['sign_status'] ?? 'sent'), $t) ?>
                  <?php if (($m['sign_status'] ?? '') === 'signed' && !empty($m['sign_signed_path'])): ?>
                    <a href="?sdl=<?= (int)$m['sign_document_id'] ?>&k=signed"><?= $h($t('dc_dl_signed')) ?></a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($m['attachment_path'])): ?>
                <div class="msg-b"><a href="?dl=<?= (int)$m['id'] ?>">📎 <?= $h($m['attachment_name'] ?: $t('tk_attachment')) ?></a></div>
              <?php endif; ?>
              <div class="msg-m"><?= $h($m['sender_name'] ?: ($mine ? $t('tk_staff') : $t('th_customer'))) ?> · <?= $h(short_time($m['created_at'])) ?></div>
            </div>
          <?php endforeach; ?>
          <?php if (!$tk['messages']): ?><p class="muted small"><?= $h($t('cu_none')) ?></p><?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </div>

  <!-- ---- files exchanged in the chat ---- -->
  <?php
  // Built from the threads already loaded above, so this costs no extra query.
  // The card below it lists SIGNING documents; this one is everything that
  // actually passed through a conversation — a plain PDF attached to a reply
  // appeared nowhere but inside its own thread, which is no use when the
  // question is "what did we send this customer?".
  $cuFiles = [];
  foreach ($ov['tickets'] as $tk) {
      foreach ($tk['messages'] as $m) {
          if (empty($m['attachment_path']) && empty($m['sign_document_id'])) {
              continue;
          }
          $cuFiles[] = $m + ['ticket_id' => (int)$tk['id'], 'ticket_subject' => (string)$tk['subject']];
      }
  }
  usort($cuFiles, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
  ?>
  <div class="card">
    <h3><?= svg('documents') ?> <?= $h($t('cu_chat_files')) ?>
      <?php if ($cuFiles): ?><span class="muted small">· <?= count($cuFiles) ?></span><?php endif; ?></h3>
    <?php if (!$cuFiles): ?><p class="muted small"><?= $h($t('cu_no_chat_files')) ?></p>
    <?php else: ?>
    <table><thead><tr>
      <th><?= $h($t('dc_h_doc')) ?></th><th><?= $h($t('cu_chat_thread')) ?></th>
      <th><?= $h($t('th_status')) ?></th><th><?= $h($t('th_created')) ?></th><th></th>
    </tr></thead><tbody>
    <?php foreach ($cuFiles as $f): $isSign = !empty($f['sign_document_id']); ?>
      <tr>
        <td>
          <?php if ($isSign): ?>
            ✍️ <?= $h($f['sign_title'] ?: $t('dc_h_doc')) ?>
          <?php else: ?>
            📎 <?= $h($f['attachment_name'] ?: $t('tk_attachment')) ?>
          <?php endif; ?>
          <div class="muted small"><?= $h($f['sender_name'] ?: ($f['sender_type'] === 'customer' ? $t('th_customer') : $t('tk_staff'))) ?></div>
        </td>
        <td class="small"><a href="?tab=tickets&tk=<?= (int)$f['ticket_id'] ?>"><?= $h($f['ticket_subject']) ?></a></td>
        <td class="small"><?= $isSign ? pill($h, (string)($f['sign_status'] ?? 'sent'), $t) : $dash ?></td>
        <td class="small muted"><?= $h(short_time($f['created_at'])) ?></td>
        <td class="small" style="white-space:nowrap">
          <?php if ($isSign): ?>
            <a class="btn ghost tiny" href="?sdl=<?= (int)$f['sign_document_id'] ?>&k=orig"><?= $h($t('dc_dl_orig')) ?></a>
            <?php if (!empty($f['sign_signed_path'])): ?>
              <a class="btn ghost tiny" href="?sdl=<?= (int)$f['sign_document_id'] ?>&k=signed"><?= $h($t('dc_dl_signed')) ?></a>
            <?php endif; ?>
          <?php else: ?>
            <a class="btn ghost tiny" href="?dl=<?= (int)$f['id'] ?>"><?= $h($t('dc_dl_orig')) ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

  <!-- ---- requests (leads) ---- -->
  <?php // "He sells different products": a customer who bought a machine last year
        // asks for another one this week, and that request arrives as a lead. It
        // is listed here, on their card, next to their quotes and documents — the
        // office sees a returning customer, not a stranger. ?>
  <?php $cuLeads = \Glue\Crm\LeadCustomers::forCard($custId); if ($cuLeads): ?>
  <div class="card">
    <h3><?= svg('leads') ?> <?= $h($t('cu_leads_h')) ?> <span class="muted small">· <?= count($cuLeads) ?></span></h3>
    <?php foreach ($cuLeads as $cl): ?>
      <div style="display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;padding:9px 0;border-top:1px solid var(--line)">
        <a href="?tab=leads&amp;lead=<?= (int)$cl['id'] ?>"><b><?= $h($cl['customer_name'] ?: ('#' . $cl['id'])) ?></b></a>
        <span class="pill"><?= $h(stage_label($t, $cl['stage_code'], \Glue\Crm\Pipelines::label('lead', $cl['stage_code']))) ?></span>
        <?= pill($h, $cl['status'], $t) ?>
        <span class="muted small"><?= $h(short_time($cl['received_at'] ?? $cl['created_at'])) ?> · <?= $h($cl['agent_name'] ?: ($cl['agent_username'] ?: $t('unassigned'))) ?></span>
        <?php if (trim((string)($cl['comments'] ?? '')) !== ''): ?>
          <div class="muted small note-clip l2" style="flex-basis:100%">“<?= $h($cl['comments']) ?>”</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ---- quote requests ---- -->
  <?php $cuQuotes = \Glue\Crm\QuoteRequests::forContact($custId); ?>
  <div class="card">
    <h3><?= svg('quotes') ?> <?= $h($t('nav_quotes')) ?>
      <?php if ($cuQuotes): ?><span class="muted small">· <?= count($cuQuotes) ?></span><?php endif; ?></h3>
    <?php // Raise one straight from here. A registry customer usually has no
          // lead behind them — 10,000 arrived from the gestionale as contacts —
          // and a quote request needs one, so forCustomer() opens a quiet lead
          // against THIS contact rather than leaving the office with no way to
          // price a customer who just asked for a price. ?>
    <details class="drawer" style="margin-bottom:12px">
      <summary class="btn tiny"><?= svg('quotes') ?> <?= $h($t('qt_request')) ?></summary>
      <form method="post" class="card" style="margin-top:10px">
        <input type="hidden" name="do" value="customer_quote">
        <input type="hidden" name="id" value="<?= (int)$custId ?>">
        <label class="fld"><span><?= $h($t('qt_notes')) ?> *</span>
          <textarea name="notes" rows="3" required placeholder="<?= $h($t('qt_notes_ph')) ?>"></textarea></label>
        <p class="muted small" style="margin:-8px 0 12px"><?= $h($t('qt_request_hint')) ?></p>
        <button class="btn tiny"><?= svg('send') ?> <?= $h($t('qt_send_request')) ?></button>
      </form>
    </details>
    <?php if (!$cuQuotes): ?><p class="muted small"><?= $h($t('cu_no_quotes')) ?></p>
    <?php else: ?>
    <table><thead><tr>
      <th><?= $h($t('qt_notes')) ?></th><th><?= $h($t('qt_asked_by')) ?></th>
      <th><?= $h($t('th_status')) ?></th><th><?= $h($t('qt_quote')) ?></th>
      <th><?= $h($t('th_created')) ?></th><th></th>
    </tr></thead><tbody>
    <?php foreach ($cuQuotes as $qr): $qs = (string)$qr['status']; ?>
      <tr>
        <td><div class="note-clip l2" style="max-width:320px;white-space:pre-wrap"><?= $h($qr['notes']) ?></div></td>
        <td class="small"><?= $h($qr['requester_name'] ?: ($qr['requester_username'] ?: $dash)) ?></td>
        <td class="small"><span class="pill"><?= $h($t('qt_st_' . $qs)) ?></span></td>
        <td class="small">
          <?php if (!empty($qr['document_id'])): ?>
            <?= $h($qr['doc_title'] ?: $t('qt_quote')) ?>
            <div class="muted small"><span class="pill pill-<?= $h((string)($qr['doc_status'] ?? 'draft')) ?>"><?= $h($t('dc_st_' . (string)($qr['doc_status'] ?? 'draft'))) ?></span>
              <?php if (!empty($qr['signed_at'])): ?> ✅ <?= $h(short_time($qr['signed_at'])) ?><?php endif; ?></div>
          <?php else: ?><?= $dash ?><?php endif; ?>
        </td>
        <td class="small muted"><?= $h(short_time($qr['created_at'])) ?></td>
        <td class="small" style="white-space:nowrap">
          <?php if (!empty($qr['document_id'])): ?>
            <a class="btn ghost tiny" href="?sdl=<?= (int)$qr['document_id'] ?>&k=orig"><?= $h($t('dc_dl_orig')) ?></a>
            <?php if (!empty($qr['signed_path'])): ?>
              <a class="btn ghost tiny" href="?sdl=<?= (int)$qr['document_id'] ?>&k=signed"><?= $h($t('dc_dl_signed')) ?></a>
            <?php endif; ?>
          <?php endif; ?>
          <a class="btn ghost tiny" href="?tab=quotes"><?= $h($t('cu_open')) ?></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

  <!-- ---- signed documents ---- -->
  <div class="card">
    <h3><?= svg('documents') ?> <?= $h($t('nav_documents')) ?></h3>
    <?php if (!$ov['documents']): ?><p class="muted small"><?= $h($t('cu_none')) ?></p>
    <?php else: ?>
    <table><thead><tr>
      <th><?= $h($t('dc_h_doc')) ?></th><th><?= $h($t('th_status')) ?></th><th><?= $h($t('cu_signed')) ?></th><th></th>
    </tr></thead><tbody>
    <?php foreach ($ov['documents'] as $d): ?>
      <tr>
        <td><?= $h($d['title']) ?> <span class="muted small"><?= $h($d['orig_name']) ?></span></td>
        <td><?= pill($h, (string)$d['status'], $t) ?></td>
        <td class="small"><?= $h($d['signed_at'] ? short_time($d['signed_at']) : '') ?: $dash ?></td>
        <td class="small">
          <a class="btn ghost tiny" href="?sdl=<?= (int)$d['id'] ?>&k=orig"><?= $h($t('dc_dl_orig')) ?></a>
          <?php if (!empty($d['signed_path'])): ?><a class="btn ghost tiny" href="?sdl=<?= (int)$d['id'] ?>&k=signed"><?= $h($t('dc_dl_signed')) ?></a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

  <!-- ---- installation reports ---- -->
  <?php $cuInstalls = \Glue\Install\Reports::forContact($custId); if ($cuInstalls): ?>
  <div class="card">
    <h3><?= svg('installations') ?> <?= $h($t('cu_installs')) ?></h3>
    <table><thead><tr>
      <th>#</th><th><?= $h($t('ir_f_model')) ?></th><th><?= $h($t('ir_f_serial')) ?></th>
      <th><?= $h($t('ir_f_tech')) ?></th><th><?= $h($t('th_status')) ?></th><th></th>
    </tr></thead><tbody>
    <?php foreach ($cuInstalls as $ir): $irSt = \Glue\Install\Reports::displayStatus($ir); ?>
      <tr>
        <td class="muted"><?= (int)$ir['id'] ?></td>
        <td><?= $h($ir['machine_model'] ?: '—') ?></td>
        <td class="small"><?= $h($ir['serial_number'] ?: '—') ?></td>
        <td class="small"><?= $h($ir['technician_name'] ?: '—') ?></td>
        <td><?= pill($h, $irSt === 'draft' ? 'draft' : $irSt, $t) ?></td>
        <td class="small"><a class="btn ghost tiny" href="?tab=installations&id=<?= (int)$ir['id'] ?>"><?= $h($t('cu_open')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <?php endif; ?>

  <!-- ---- pipeline history ---- -->
  <div class="card">
    <h3><?= svg('deals') ?> <?= $h($t('cu_pipeline')) ?></h3>
    <?php if (!$ov['deals'] && !$ov['leads']): ?><p class="muted small"><?= $h($t('cu_none')) ?></p><?php endif; ?>
    <?php if ($ov['deals']): ?>
    <table><thead><tr>
      <th><?= $h($t('nav_deals')) ?></th><th><?= $h($t('cu_amount')) ?></th><th><?= $h($t('th_status')) ?></th><th class="small"><?= $h($t('th_created')) ?></th>
    </tr></thead><tbody>
    <?php foreach ($ov['deals'] as $d): ?>
      <tr><td><?= $h(record_title($t, $d['title'])) ?></td><td><?= $eur($d['amount'], $d['currency']) ?></td>
          <td><?= pill($h, (string)$d['status'], $t) ?></td><td class="small muted"><?= $h(short_time($d['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?php if ($ov['leads']): ?>
    <table><thead><tr>
      <th><?= $h($t('nav_leads')) ?></th><th><?= $h($t('th_source')) ?></th><th><?= $h($t('th_status')) ?></th><th class="small"><?= $h($t('th_created')) ?></th>
    </tr></thead><tbody>
    <?php foreach ($ov['leads'] as $l): ?>
      <tr><td><?= $h(record_title($t, $l['title']) ?: $l['customer_name'] ?: ('#' . $l['id'])) ?></td><td class="small"><?= !empty($l['source']) ? $h(source_label($t, $l['source'])) : $dash ?></td>
          <td><?= pill($h, (string)$l['status'], $t) ?></td><td class="small muted"><?= $h(short_time($l['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

  <!-- ---- routers ---- -->
  <div class="card">
    <h3><?= svg('devices') ?> <?= $h($t('cu_routers')) ?></h3>
    <?php if ($ov['areas']): ?>
    <table><thead><tr>
      <th><?= $h($t('cu_name')) ?></th><th><?= $h($t('na_host')) ?></th><th><?= $h($t('nav_devices')) ?></th><th></th>
    </tr></thead><tbody>
    <?php foreach ($ov['areas'] as $a): ?>
      <tr>
        <td><?= $h($a['name']) ?></td>
        <td class="small"><?= $h($a['host']) ?></td>
        <td><?php $down = (int)$a['devices_down']; ?>
          <span class="pill <?= $down ? 'pill-down' : 'pill-up' ?>"><?= (int)$a['device_count'] - $down ?>/<?= (int)$a['device_count'] ?> up</span></td>
        <td class="small">
          <a class="btn ghost tiny" href="?tab=devices"><?= $h($t('cu_open')) ?></a>
          <form method="post" class="inline" onsubmit="return confirm('<?= $h($t('cu_router_unlink_q')) ?>')">
            <input type="hidden" name="do" value="customer_area_unlink">
            <input type="hidden" name="cid" value="<?= (int)$c['id'] ?>"><input type="hidden" name="area_id" value="<?= (int)$a['id'] ?>">
            <button class="btn ghost tiny"><?= $h($t('cu_router_unlink')) ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php else: ?><p class="muted small"><?= $h($t('cu_no_routers')) ?></p><?php endif; ?>
    <?php $free = Customers::unassignedAreas(); if ($free): ?>
    <form method="post" class="row" style="align-items:flex-end;margin-top:8px">
      <input type="hidden" name="do" value="customer_area_link"><input type="hidden" name="cid" value="<?= (int)$c['id'] ?>">
      <label class="fld"><span><?= $h($t('cu_router_link')) ?></span>
        <select name="area_id">
          <?php foreach ($free as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $h($a['name']) ?> (<?= $h($a['host']) ?>)</option><?php endforeach; ?>
        </select></label>
      <button class="btn"><?= $h($t('cu_router_link_btn')) ?></button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- ---- profile / edit ---- -->
<div class="cu-side">
  <div class="card">
    <h3><?= svg('contacts') ?> <?= $h($t('cu_profile')) ?></h3>
    <form method="post">
      <input type="hidden" name="do" value="customer_edit"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <div class="row">
        <label class="fld"><span><?= $h($t('f_first_name')) ?></span><input name="first_name" value="<?= $h($c['first_name'] ?? '') ?>"></label>
        <label class="fld"><span><?= $h($t('f_last_name')) ?></span><input name="last_name" value="<?= $h($c['last_name'] ?? '') ?>"></label>
      </div>
      <label class="fld"><span><?= $h($t('f_company')) ?></span><input name="company" value="<?= $h($c['company'] ?? '') ?>"></label>
      <div class="row">
        <label class="fld"><span><?= $h($t('cu_code')) ?></span><input name="customer_code" value="<?= $h($c['customer_code'] ?? '') ?>"></label>
        <label class="fld"><span><?= $h($t('cu_vat')) ?></span><input name="vat_number" value="<?= $h($c['vat_number'] ?? '') ?>"></label>
      </div>
      <div class="row">
        <?php phone_field($h, $t('f_phone'), 'phone', $c['phone'] ?? null, $lang); ?>
        <?php phone_field($h, $t('cu_phone2'), 'phone2', $c['phone2'] ?? null, $lang); ?>
      </div>
      <div class="row">
        <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email" value="<?= $h($c['email'] ?? '') ?>"></label>
        <label class="fld"><span>PEC</span><input name="pec" value="<?= $h($c['pec'] ?? '') ?>"></label>
      </div>
      <label class="fld"><span><?= $h($t('cu_address')) ?></span><input name="address" value="<?= $h($c['address'] ?? '') ?>"></label>
      <div class="row">
        <label class="fld"><span><?= $h($t('cu_city')) ?></span><input name="city" value="<?= $h($c['city'] ?? '') ?>"></label>
        <label class="fld" style="max-width:80px"><span><?= $h($t('cu_prov')) ?></span><input name="province" value="<?= $h($c['province'] ?? '') ?>"></label>
        <label class="fld" style="max-width:110px"><span><?= $h($t('cu_zip')) ?></span><input name="zip" value="<?= $h($c['zip'] ?? '') ?>"></label>
      </div>
      <div class="row">
        <label class="fld"><span><?= $h($t('cu_contract_expiry')) ?></span><input type="date" name="contract_expiry" value="<?= $h($c['contract_expiry'] ?? '') ?>"></label>
        <label class="fld"><span><?= $h($t('cu_agent')) ?></span><input name="gestionale_agent" value="<?= $h($c['gestionale_agent'] ?? '') ?>"></label>
      </div>
      <label class="fld"><span><?= $h($t('f_notes')) ?></span><textarea name="notes" rows="3"><?= $h($c['notes'] ?? '') ?></textarea></label>
      <button class="btn"><?= $h($t('save')) ?></button>
    </form>
  </div>
  <div class="card small">
    <h3><?= $h($t('cu_registry')) ?></h3>
    <p class="muted" style="margin:4px 0"><?= $h($t('cu_balance')) ?>: <b><?= $eur($c['balance']) ?></b></p>
    <p class="muted" style="margin:4px 0"><?= $h($t('cu_since')) ?>: <?= $h($c['customer_since'] ? short_time($c['customer_since']) : '') ?: $dash ?></p>
    <p class="muted" style="margin:4px 0"><?= $h($t('th_source')) ?>: <?= !empty($c['source']) ? $h(source_label($t, $c['source'])) : $dash ?></p>
    <p class="muted" style="margin:4px 0"><?= $h($t('cu_portal')) ?>:
      <?= (int)($c['portal_enabled'] ?? 0) === 1
          ? '<span class="pill pill-up">' . $h($t('cu_portal_on')) . '</span>' . ($c['last_login_at'] ? ' ' . $h(short_time($c['last_login_at'])) : '')
          : '<span class="pill">' . $h($t('cu_portal_off')) . '</span>' ?></p>
    <form method="post" style="margin-top:8px">
      <input type="hidden" name="do" value="customer_portal_invite">
      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <button class="btn ghost tiny"><?= svg('link') ?> <?= $h($t('cu_portal_send')) ?></button>
      <small class="muted" style="display:block;margin-top:4px"><?= $h($t('cu_portal_send_h')) ?></small>
    </form>
    <?php // Deleting the card. The name goes into the confirm so the admin sees
          // whom they are about to remove; json_encode keeps an apostrophe in
          // "D'Amico" from ending the JavaScript string. ?>
    <form method="post" style="margin-top:14px;padding-top:12px;border-top:1px solid var(--line)"
          onsubmit="return confirm(<?= $h(json_encode(sprintf($t('confirm_cu_delete'), $c['name']), JSON_UNESCAPED_UNICODE)) ?>)">
      <input type="hidden" name="do" value="customer_delete">
      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <button class="btn ghost tiny" style="color:var(--red)"><?= $h($t('cu_delete')) ?></button>
      <small class="muted" style="display:block;margin-top:4px"><?= $h($t('cu_delete_h')) ?></small>
    </form>
  </div>
</div>
</div>

<style>
.cu-top{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap}
/* The chat actions sit at the far right of the customer's name, so reaching the
   conversation is one click from the top of the page rather than a scroll. */
.cu-acts{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
.cu-acts .btn{white-space:nowrap}
.cu-cols{display:flex;gap:16px;align-items:flex-start}
.cu-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:16px}
.cu-main .card{margin:0}
.cu-side{width:340px;flex-shrink:0;display:flex;flex-direction:column;gap:16px}
.cu-side .card{margin:0}
.cu-tk{border:1px solid var(--line);border-radius:10px;margin-bottom:10px;background:var(--surface)}
.cu-tk summary{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;flex-wrap:wrap}
.cu-chatbox{display:flex;flex-direction:column;gap:8px;padding:12px 14px;border-top:1px solid var(--line);max-height:420px;overflow-y:auto}
.cu-chatbox .msg{max-width:78%;padding:9px 13px;border-radius:12px;font-size:13.5px;line-height:1.5}
.cu-chatbox .msg-m{font-size:11px;color:var(--muted);margin-top:5px}
.cu-chatbox .msg.cust{align-self:flex-start;background:var(--surface2);border:1px solid var(--line);border-bottom-left-radius:3px}
.cu-chatbox .msg.staff{align-self:flex-end;background:var(--accent-soft);border:1px solid var(--line);border-bottom-right-radius:3px}
.pill-up{background:rgba(62,207,142,.15);color:#3ecf8e}
.pill-down{background:rgba(240,82,82,.15);color:#f05252}
/* Stacked, the row becomes a column — and a column that keeps align-items:flex-start
   sizes .cu-main to its WIDEST child (a table is 520px min), which pushed the whole
   card sideways on a phone. Stretch puts it back to the screen width. */
@media (max-width:1000px){.cu-cols{flex-direction:column;align-items:stretch}.cu-side{width:100%}}
</style>

<?php else:
    // ======================= the list =======================
    $q     = trim((string)($_GET['q'] ?? ''));
    $state = (string)($_GET['state'] ?? 'all');
    $page  = max(1, (int)($_GET['p'] ?? 1));
    $res   = Customers::search(['q' => $q, 'state' => $state], $page);
    $cnt   = Customers::counters();
    $lastImports = $pdo->query('SELECT * FROM customer_imports ORDER BY id DESC LIMIT 5')->fetchAll();
    $chip = fn(string $key, string $label, int $n) => '<a class="btn tiny ' . ($state === $key ? '' : 'ghost')
        . '" href="?tab=customers&state=' . $key . ($q !== '' ? '&q=' . urlencode($q) : '') . '">'
        . $h($label) . ' <span class="muted">' . $n . '</span></a>';
?>
<div class="cu-top">
  <h2 style="margin:0"><?= $h($t('nav_customers')) ?></h2>
  <span style="flex:1"></span>
  <details class="drawer">
    <summary class="btn"><?= svg('customers') ?> <?= $h($t('cu_new')) ?></summary>
    <div class="card" style="position:absolute;right:20px;z-index:6;width:min(640px,92vw);margin-top:8px">
      <form method="post">
        <input type="hidden" name="do" value="customer_create">
        <div class="row">
          <label class="fld"><span><?= $h($t('f_first_name')) ?></span><input name="first_name"></label>
          <label class="fld"><span><?= $h($t('f_last_name')) ?></span><input name="last_name"></label>
          <label class="fld"><span><?= $h($t('f_company')) ?></span><input name="company"></label>
        </div>
        <div class="row">
          <label class="fld"><span><?= $h($t('cu_code')) ?></span><input name="customer_code" placeholder="<?= $h($t('cu_code_ph')) ?>"></label>
          <label class="fld"><span><?= $h($t('cu_vat')) ?></span><input name="vat_number"></label>
          <label class="fld"><span><?= $h($t('cu_contract_expiry')) ?></span><input type="date" name="contract_expiry"></label>
        </div>
        <div class="row">
          <?php phone_field($h, $t('f_phone'), 'phone', null, $lang); ?>
          <?php phone_field($h, $t('cu_phone2'), 'phone2', null, $lang); ?>
          <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email" type="email"></label>
        </div>
        <div class="row">
          <label class="fld"><span><?= $h($t('cu_address')) ?></span><input name="address"></label>
          <label class="fld" style="max-width:160px"><span><?= $h($t('cu_city')) ?></span><input name="city"></label>
          <label class="fld" style="max-width:80px"><span><?= $h($t('cu_prov')) ?></span><input name="province"></label>
          <label class="fld" style="max-width:110px"><span><?= $h($t('cu_zip')) ?></span><input name="zip"></label>
        </div>
        <label class="fld"><span><?= $h($t('f_notes')) ?></span><textarea name="notes" rows="2"></textarea></label>
        <button class="btn"><?= $h($t('save')) ?></button>
      </form>
    </div>
  </details>
  <details class="drawer">
    <summary class="btn ghost"><?= svg('invoices') ?> <?= $h($t('cu_import')) ?></summary>
    <div class="card" style="position:absolute;right:20px;z-index:5;width:min(460px,90vw);margin-top:8px">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="do" value="customer_import">
        <p class="muted small" style="margin-top:0"><?= $h($t('cu_import_help')) ?></p>
        <input type="file" name="file" accept=".xlsx" required>
        <button class="btn" style="margin-top:10px"><?= $h($t('cu_import_btn')) ?></button>
      </form>
      <?php if ($lastImports): ?>
      <table style="margin-top:12px"><thead><tr><th><?= $h($t('cu_imp_file')) ?></th><th><?= $h($t('cu_imp_result')) ?></th><th><?= $h($t('th_created')) ?></th></tr></thead><tbody>
        <?php foreach ($lastImports as $im): ?>
        <tr><td class="small"><?= $h($im['filename']) ?></td>
            <td class="small">+<?= (int)$im['created_n'] ?> / ~<?= (int)$im['updated_n'] ?></td>
            <td class="small muted"><?= $h(short_time($im['imported_at'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
  </details>
</div>

<div class="cu-filter">
  <?= $chip('all', $t('cu_f_all'), $cnt['total']) ?>
  <?= $chip('owing', $t('cu_f_owing'), $cnt['owing']) ?>
  <?= $chip('support', $t('cu_f_support'), $cnt['support']) ?>
  <?= $chip('expired', $t('cu_f_expired'), $cnt['expired']) ?>
  <?= $chip('no_contact', $t('cu_f_no_contact'), $cnt['no_contact']) ?>
  <?php // The people the registry does not hold: they arrived as a lead and were
        // quoted, invoiced or signed without ever being imported from the
        // gestionale. Reaching them used to be impossible from this page. ?>
  <?= $chip('leads', $t('cu_f_leads'), $cnt['leads']) ?>
  <form method="get" class="inline" style="margin-left:auto">
    <input type="hidden" name="tab" value="customers"><input type="hidden" name="state" value="<?= $h($state) ?>">
    <input type="search" name="q" value="<?= $h($q) ?>" placeholder="<?= $h($t('cu_search_ph')) ?>" style="width:min(320px,60vw)">
  </form>
</div>

<table><thead><tr>
  <th><?= $h($t('cu_code')) ?></th><th><?= $h($t('cu_name')) ?></th><th><?= $h($t('cu_city')) ?></th>
  <th><?= $h($t('cu_vat')) ?></th><th><?= $h($t('f_phone')) ?></th>
  <th><?= $h($t('cu_th_owed')) ?></th><th><?= $h($t('cu_support')) ?></th>
  <th><?= $h($t('cu_routers')) ?></th><th><?= $h($t('cu_chat')) ?></th>
</tr></thead><tbody>
<?php if (!$res['rows']): ?><tr><td colspan="9" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
<?php foreach ($res['rows'] as $r): ?>
  <tr class="rowlink" onclick="location='?tab=customers&id=<?= (int)$r['id'] ?>'">
    <td class="small muted"><?= $h($r['customer_code'] ?? '') ?: $dash ?></td>
    <td><a href="?tab=customers&id=<?= (int)$r['id'] ?>"><?= avatar($h, $r['name']) ?> <?= $h($r['name']) ?></a></td>
    <td class="small"><?= $h($r['city'] ?? '') ?: $dash ?></td>
    <td class="small"><?= $h($r['vat_number'] ?? '') ?: $dash ?></td>
    <td class="small"><?= phone_link($h, $r['phone'] ?: $r['phone2']) ?></td>
    <td><?php if ((float)$r['inv_open'] > 0): ?><b><?= $eur($r['inv_open']) ?></b>
        <?php if ((int)$r['inv_overdue'] > 0): ?><span class="pill pill-unpaid"><?= (int)$r['inv_overdue'] ?></span><?php endif; ?>
        <?php else: echo $dash; endif; ?></td>
    <td><?php // either kind of support contract: SmallPay subscription or gestionale expiry
      if ((int)$r['active_contracts'] > 0): ?><span class="pill pill-up">✓ <?= $h($t('cu_ct_active')) ?></span>
      <?php elseif (!empty($r['contract_expiry']) && $r['contract_expiry'] >= date('Y-m-d')): ?>
        <span class="pill pill-up"><?= $h(sprintf($t('cu_until'), date('d/m/Y', strtotime((string)$r['contract_expiry'])))) ?></span>
      <?php elseif (!empty($r['contract_expiry'])): ?>
        <span class="pill pill-unpaid"><?= $h(sprintf($t('cu_expired_on'), date('d/m/Y', strtotime((string)$r['contract_expiry'])))) ?></span>
      <?php else: echo $dash; endif; ?></td>
    <td><?= (int)$r['router_count'] > 0 ? (int)$r['router_count'] : $dash ?></td>
    <td><?= (int)$r['open_tickets'] > 0 ? '<span class="pill">' . (int)$r['open_tickets'] . '</span>' : $dash ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table>

<?php if ($res['pages'] > 1): $qs = '&state=' . $h($state) . ($q !== '' ? '&q=' . urlencode($q) : ''); ?>
<div class="cu-pager">
  <?php if ($res['page'] > 1): ?><a class="btn ghost tiny" href="?tab=customers<?= $qs ?>&p=<?= $res['page'] - 1 ?>">&larr;</a><?php endif; ?>
  <span class="muted small"><?= $res['page'] ?> / <?= $res['pages'] ?> · <?= $res['total'] ?> <?= $h($t('cu_f_all')) ?></span>
  <?php if ($res['page'] < $res['pages']): ?><a class="btn ghost tiny" href="?tab=customers<?= $qs ?>&p=<?= $res['page'] + 1 ?>">&rarr;</a><?php endif; ?>
</div>
<?php endif; ?>

<style>
.cu-top{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap;position:relative}
.cu-filter{display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.cu-pager{display:flex;align-items:center;gap:12px;justify-content:center;margin-top:14px}
.rowlink{cursor:pointer}
.rowlink:hover{background:var(--surface2)}
.pill-up{background:rgba(62,207,142,.15);color:#3ecf8e}
.pill-unpaid{background:rgba(240,82,82,.15);color:#f05252}
</style>
<?php endif; ?>
