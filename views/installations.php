<?php
/**
 * Installation reports — the technician's on-site form (the Jotform
 * "Installazione Cashmatic" sheet brought in-house). Draft: edit the machine
 * data, attach the photos. Send: the report becomes a PDF filed with the
 * in-house signing flow and the customer signs it from their phone with the
 * one-time code; from then on the report is read-only and its state is the
 * sign document's state.
 *
 * In scope: $t, $h, $pdo, $uid, $isAgent, $isTech, $scopeId.
 * Admins see everyone's reports; technicians only their own.
 */

use Glue\Crm\Customers;
use Glue\Install\Reports;

$isAdminHere = !$isAgent && !$isTech;
$ownScope    = $isAdminHere ? null : (int)$uid;

$statusColor = [
    'draft' => 'var(--muted)', 'sent' => 'var(--accent)', 'viewed' => 'var(--amber)',
    'signed' => 'var(--green)', 'declined' => 'var(--red)', 'expired' => 'var(--muted)',
    'void' => 'var(--muted)',
];
$stLabel = function (string $st) use ($t): string {
    if ($st === 'draft') { return $t('ir_st_draft'); }
    $k = 'dc_st_' . $st;
    $tr = $t($k);
    return $tr !== $k ? $tr : $st;
};
$dtLocal = fn(?string $v): string => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$dtHuman = fn(?string $v): string => $v ? date('d/m/Y H:i', strtotime($v)) : '—';

$irId = (int)($_GET['id'] ?? 0);
$r    = $irId > 0 ? Reports::find($irId) : null;
// Non-admins only ever see their own reports, by URL too.
if ($r && $ownScope !== null && (int)$r['created_by'] !== $ownScope) {
    $r = null;
}

if ($r !== null):
    // ======================= one report =======================
    $photos  = Reports::photos((int)$r['id']);
    $ground  = array_values(array_filter($photos, fn($p) => $p['kind'] === 'ground'));
    $final   = array_values(array_filter($photos, fn($p) => $p['kind'] !== 'ground'));
    $isDraft = $r['status'] === 'draft';
    $st      = Reports::displayStatus($r);
    $hasChannel = trim((string)$r['customer_phone']) !== '' || trim((string)$r['customer_email']) !== '';
?>
<div class="cu-top">
  <a class="btn ghost tiny" href="?tab=installations">&larr; <?= $h($t('ir_back')) ?></a>
  <h2 style="margin:0"><?= avatar($h, $r['customer_name']) ?> <?= $h($r['customer_name']) ?>
    <span class="pill" style="color:<?= $statusColor[$st] ?? 'var(--muted)' ?>"><?= $h($stLabel($st)) ?></span>
    <span class="muted small">#<?= (int)$r['id'] ?></span>
  </h2>
</div>

<?php if ($isDraft): ?>
<form method="post" class="card" style="margin-bottom:14px">
  <input type="hidden" name="do" value="install_save">
  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
  <h3 style="margin-top:0"><?= svg('installations') ?> <?= $h($t('ir_data')) ?></h3>
  <div class="row">
    <label class="fld"><span><?= $h($t('ir_f_type')) ?></span>
      <select name="report_type" id="ir-type" onchange="irTypeToggle()">
        <option value="installation" <?= ($r['report_type'] ?? '') !== 'test' ? 'selected' : '' ?>><?= $h($t('ir_type_installation')) ?></option>
        <option value="test" <?= ($r['report_type'] ?? '') === 'test' ? 'selected' : '' ?>><?= $h($t('ir_type_test')) ?></option>
      </select></label>
    <label class="fld" id="ir-testend" <?= ($r['report_type'] ?? '') === 'test' ? '' : 'style="display:none"' ?>>
      <span><?= $h($t('ir_f_test_end')) ?></span>
      <input type="date" name="test_end_date" value="<?= $h((string)($r['test_end_date'] ?? '')) ?>"
             min="<?= $h(date('Y-m-d')) ?>">
      <small class="muted"><?= $h($t('ir_f_test_end_h')) ?></small></label>
  </div>
  <script>
  function irTypeToggle(){var s=document.getElementById('ir-type');
    document.getElementById('ir-testend').style.display = s.value==='test' ? '' : 'none';}
  </script>
  <div class="row">
    <label class="fld"><span><?= $h($t('ir_f_start')) ?></span>
      <input type="datetime-local" name="started_at" value="<?= $h($dtLocal($r['started_at'])) ?>"></label>
    <label class="fld"><span><?= $h($t('ir_f_end')) ?></span>
      <input type="text" value="<?= $h($t('ir_end_auto_ph')) ?>" disabled>
      <small class="muted"><?= $h($t('ir_end_auto')) ?></small></label>
  </div>
  <div class="row">
    <label class="fld"><span><?= $h($t('ir_f_model')) ?></span>
      <input name="machine_model" list="ir-models" value="<?= $h($r['machine_model'] ?? '') ?>">
      <datalist id="ir-models">
        <?php foreach (Reports::MODELS as $m): ?><option value="<?= $h($m) ?>"><?php endforeach; ?>
      </datalist></label>
    <label class="fld"><span><?= $h($t('ir_f_serial')) ?></span>
      <input name="serial_number" value="<?= $h($r['serial_number'] ?? '') ?>"></label>
    <label class="fld"><span><?= $h($t('ir_f_ground')) ?></span>
      <input name="ground_value" value="<?= $h($r['ground_value'] ?? '') ?>" placeholder="01.7"></label>
  </div>
  <div class="row">
    <label class="fld"><span><?= $h($t('ir_f_local_ip')) ?></span>
      <input name="local_ip" value="<?= $h($r['local_ip'] ?? '') ?>" placeholder="192.168.1.115"></label>
    <label class="fld"><span><?= $h($t('ir_f_public_ip')) ?></span>
      <input name="public_ip" value="<?= $h($r['public_ip'] ?? '') ?>"></label>
    <label class="fld"><span><?= $h($t('ir_f_vpn')) ?></span>
      <input name="vpn_address" value="<?= $h($r['vpn_address'] ?? '') ?>"></label>
  </div>
  <div class="row">
    <label class="fld"><span><?= $h($t('ir_f_adsl')) ?></span>
      <input name="adsl_provider" value="<?= $h($r['adsl_provider'] ?? '') ?>" placeholder="TIM, WIND, Fastweb…"></label>
    <label class="fld"><span><?= $h($t('ir_f_remote')) ?></span>
      <input name="remote_assist_id" value="<?= $h($r['remote_assist_id'] ?? '') ?>" placeholder="SUPREMO: …"></label>
  </div>
  <div class="row">
    <label class="fld"><span><?= $h($t('ir_f_ups')) ?></span>
      <select name="ups_installed">
        <?php foreach (Reports::UPS_VALUES as $v): ?>
          <option value="<?= $h($v) ?>" <?= $r['ups_installed'] === $v ? 'selected' : '' ?>><?= $h($t('ir_ups_' . $v)) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="fld"><span><?= $h($t('ir_f_cash')) ?></span>
      <select name="cash_collected">
        <?php foreach (Reports::CASH_VALUES as $v): ?>
          <option value="<?= $h($v) ?>" <?= $r['cash_collected'] === $v ? 'selected' : '' ?>><?= $h($t('ir_cash_' . $v)) ?></option>
        <?php endforeach; ?>
      </select></label>
  </div>
  <label class="fld"><span><?= $h($t('ir_f_notes')) ?></span>
    <textarea name="notes" rows="4" style="width:100%;resize:vertical"><?= $h($r['notes'] ?? '') ?></textarea></label>
  <button class="btn" style="margin-top:10px"><?= $h($t('ir_save')) ?></button>
</form>

<div class="card" style="margin-bottom:14px">
  <h3 style="margin-top:0"><?= svg('eye') ?> <?= $h($t('ir_photos')) ?></h3>
  <?php foreach (['ground' => $ground, 'final' => $final] as $kind => $set): ?>
    <div style="margin-bottom:14px">
      <b class="small"><?= $h($t('ir_f_' . ($kind === 'ground' ? 'ground_photo' : 'final_photos'))) ?></b>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0">
        <?php foreach ($set as $p): ?>
          <span style="position:relative;display:inline-block">
            <a href="?ipf=<?= (int)$p['id'] ?>" target="_blank">
              <img src="?ipf=<?= (int)$p['id'] ?>" alt="" style="height:90px;width:120px;object-fit:cover;border-radius:8px;border:1px solid var(--line)"></a>
            <form method="post" style="position:absolute;top:-6px;right:-6px;margin:0"
                  onsubmit="return confirm('<?= $h($t('ir_photo_del_confirm')) ?>')">
              <input type="hidden" name="do" value="install_photo_del">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
              <button class="btn ghost tiny" style="padding:2px 7px;border-radius:50%">×</button>
            </form>
          </span>
        <?php endforeach; ?>
        <?php if (!$set): ?><span class="muted small"><?= $h($t('ir_no_photos')) ?></span><?php endif; ?>
      </div>
      <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="do" value="install_photos">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="kind" value="<?= $h($kind) ?>">
        <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
        <button class="btn ghost tiny"><?= $h($t('ir_photo_add')) ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-bottom:14px">
  <h3 style="margin-top:0"><?= svg('sign') ?> <?= $h($t('ir_send_title')) ?></h3>
  <p class="muted small"><?= $h($t('ir_send_help')) ?></p>
  <p class="small">
    <?= $h($t('f_phone')) ?>: <b><?= $h($r['customer_phone'] ?: '—') ?></b> ·
    <?= $h($t('f_email')) ?>: <b><?= $h($r['customer_email'] ?: '—') ?></b>
  </p>
  <?php if (!$hasChannel): ?>
    <p class="small" style="color:var(--red)"><?= $h($t('ir_no_channel')) ?></p>
  <?php endif; ?>
  <form method="post" onsubmit="return confirm('<?= $h($t('ir_send_confirm')) ?>')">
    <input type="hidden" name="do" value="install_send">
    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <button class="btn" <?= $hasChannel ? '' : 'disabled' ?>><?= svg('send') ?> <?= $h($t('ir_send')) ?></button>
  </form>
</div>

<?php else: /* sent: read-only sheet */ ?>
<div class="card" style="margin-bottom:14px">
  <h3 style="margin-top:0"><?= svg('installations') ?> <?= $h($t('ir_data')) ?></h3>
  <table>
    <?php
    $rows = [];
    if (($r['report_type'] ?? '') === 'test') {
        $rows['ir_f_type'] = $t('ir_type_test');
        $rows['ir_f_test_end'] = !empty($r['test_end_date'])
            ? date('d/m/Y', strtotime((string)$r['test_end_date'])) : '—';
    }
    $rows += [
        'ir_f_start'  => $dtHuman($r['started_at']),   'ir_f_end'    => $dtHuman($r['finished_at']),
        'ir_f_model'  => $r['machine_model'] ?: '—',   'ir_f_serial' => $r['serial_number'] ?: '—',
        'ir_f_ground' => $r['ground_value'] ?: '—',    'ir_f_local_ip' => $r['local_ip'] ?: '—',
        'ir_f_public_ip' => $r['public_ip'] ?: '—',    'ir_f_adsl'   => $r['adsl_provider'] ?: '—',
        'ir_f_vpn'    => $r['vpn_address'] ?: '—',     'ir_f_remote' => $r['remote_assist_id'] ?: '—',
        'ir_f_ups'    => $t('ir_ups_' . ($r['ups_installed'] === 'present' ? 'present' : 'absent')),
        'ir_f_cash'   => $t('ir_cash_' . (in_array($r['cash_collected'], Reports::CASH_VALUES, true) ? $r['cash_collected'] : 'none')),
        'ir_f_tech'   => $r['technician_name'] ?: '—', 'ir_sent_at'  => $dtHuman($r['sent_at']),
    ]; ?>
    <?php foreach ($rows as $k => $v): ?>
      <tr><td class="muted small" style="width:220px"><?= $h($t($k)) ?></td><td><b><?= $h((string)$v) ?></b></td></tr>
    <?php endforeach; ?>
    <?php if (trim((string)$r['notes']) !== ''): ?>
      <tr><td class="muted small"><?= $h($t('ir_f_notes')) ?></td><td style="white-space:pre-wrap"><?= $h($r['notes']) ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<?php if ($photos): ?>
<div class="card" style="margin-bottom:14px">
  <h3 style="margin-top:0"><?= svg('eye') ?> <?= $h($t('ir_photos')) ?></h3>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <?php foreach ($photos as $p): ?>
      <a href="?ipf=<?= (int)$p['id'] ?>" target="_blank">
        <img src="?ipf=<?= (int)$p['id'] ?>" alt="" style="height:110px;width:150px;object-fit:cover;border-radius:8px;border:1px solid var(--line)"></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px">
  <h3 style="margin-top:0"><?= svg('sign') ?> <?= $h($t('ir_doc_title')) ?></h3>
  <p class="small"><?= $h($t('ir_doc_state')) ?>:
    <span class="pill" style="color:<?= $statusColor[$st] ?? 'var(--muted)' ?>"><?= $h($stLabel($st)) ?></span>
    <?php if (!empty($r['doc_signed_at'])): ?>
      · <?= $h($t('ir_signed_at')) ?> <b><?= $h($dtHuman($r['doc_signed_at'])) ?></b>
    <?php endif; ?>
  </p>
  <?php if (!empty($r['sign_document_id'])): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn ghost tiny" href="?sdl=<?= (int)$r['sign_document_id'] ?>" target="_blank"><?= $h($t('ir_view_pdf')) ?></a>
      <?php if ($st === 'signed'): ?>
        <a class="btn ghost tiny" href="?sdl=<?= (int)$r['sign_document_id'] ?>&k=signed" target="_blank"><?= $h($t('ir_view_signed')) ?></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isAdminHere && $st !== 'signed'): ?>
  <form method="post" onsubmit="return confirm('<?= $h($t('ir_delete_confirm')) ?>')" style="margin-bottom:14px">
    <input type="hidden" name="do" value="install_delete">
    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <button class="btn ghost tiny" style="color:var(--red)"><?= $h($t('ir_delete')) ?></button>
  </form>
<?php endif; ?>

<?php else:
    // ======================= the list =======================
    $reports = Reports::all(200, $ownScope);
    $nq = trim((string)($_GET['nq'] ?? ''));
    $foundCustomers = $nq !== '' ? Customers::search(['q' => $nq], 1, 15)['rows'] : [];
?>
<h2><?= $h($t('nav_installations')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('ir_sub')) ?></p>

<details class="drawer" <?= $nq !== '' ? 'open' : '' ?>>
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('installations') ?> <?= $h($t('ir_new')) ?></summary>
  <div class="card" style="margin-top:12px;margin-bottom:14px">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="tab" value="installations">
      <input type="search" name="nq" value="<?= $h($nq) ?>" placeholder="<?= $h($t('ir_search_ph')) ?>"
             style="width:min(340px,70vw)" autofocus>
      <button class="btn ghost tiny"><?= $h($t('ir_search')) ?></button>
    </form>
    <?php if ($nq !== ''): ?>
      <?php if (!$foundCustomers): ?>
        <p class="muted small" style="margin-top:10px"><?= $h($t('ir_search_none')) ?></p>
      <?php else: ?>
        <table style="margin-top:10px">
          <?php foreach ($foundCustomers as $c): ?>
            <tr>
              <td><?= avatar($h, $c['name']) ?> <b><?= $h($c['name']) ?></b>
                <?php if (!empty($c['customer_code'])): ?><span class="muted small"> · <?= $h($c['customer_code']) ?></span><?php endif; ?>
                <div class="muted small"><?= $h(trim(implode(' · ', array_filter([(string)($c['city'] ?? ''), (string)($c['phone'] ?? '')], 'strlen')))) ?></div>
              </td>
              <td style="text-align:right">
                <form method="post" style="margin:0">
                  <input type="hidden" name="do" value="install_create">
                  <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn tiny"><?= $h($t('ir_open_for')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</details>

<?php if (!$reports): ?><div class="empty"><?= $h($t('ir_none')) ?></div><?php else: ?>
<table>
  <thead><tr>
    <th>#</th><th><?= $h($t('ir_customer')) ?></th><th><?= $h($t('ir_f_model')) ?></th>
    <th><?= $h($t('ir_f_serial')) ?></th><th><?= $h($t('ir_f_tech')) ?></th>
    <th><?= $h($t('th_created')) ?></th><th><?= $h($t('th_status')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($reports as $row): $st = Reports::displayStatus($row); ?>
    <tr>
      <td class="muted"><?= (int)$row['id'] ?></td>
      <td><b><?= $h($row['customer_name']) ?></b></td>
      <td><?= $h($row['machine_model'] ?: '—') ?>
        <?php if (($row['report_type'] ?? '') === 'test'): ?>
          <span class="pill" style="color:var(--amber)"><?= $h($t('ir_test_badge')) ?><?=
            !empty($row['test_end_date']) ? ' ' . $h(date('d/m', strtotime((string)$row['test_end_date']))) : '' ?></span>
        <?php endif; ?></td>
      <td class="small"><?= $h($row['serial_number'] ?: '—') ?></td>
      <td class="small"><?= $h($row['technician_name'] ?: ($row['full_name'] ?: ($row['username'] ?? '—'))) ?></td>
      <td class="small muted"><?= $h(short_time($row['created_at'])) ?></td>
      <td><span class="pill" style="color:<?= $statusColor[$st] ?? 'var(--muted)' ?>"><?= $h($stLabel($st)) ?></span></td>
      <td style="text-align:right"><a class="btn ghost tiny" href="?tab=installations&id=<?= (int)$row['id'] ?>"><?= $h($t('cu_open')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php endif; ?>
