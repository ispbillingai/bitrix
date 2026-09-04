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
</div>

<div class="grid stats">
  <?php stat_card($h, 'invoices', $t('cu_open_invoices'), 'EUR ' . number_format($ov['owed'], 2, ',', '.'), $ov['owed'] <= 0.009); ?>
  <?php stat_card($h, 'payments', $t('cu_support'), $activeContract
      ? $eurC($activeContract['amount_cents'], $activeContract['currency']) . ' / ' . $t('pay_per_month')
      : $t('cu_no_contract'), $activeContract !== null); ?>
  <?php stat_card($h, 'devices', $t('cu_routers'), count($ov['areas']) . ($routersDown ? ' (' . $routersDown . ' ⚠)' : ''), $routersDown === 0); ?>
  <?php stat_card($h, 'tickets', $t('cu_open_tickets'), (string)$openTickets, $openTickets === 0); ?>
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

  <!-- ---- pipeline history ---- -->
  <div class="card">
    <h3><?= svg('deals') ?> <?= $h($t('cu_pipeline')) ?></h3>
    <?php if (!$ov['deals'] && !$ov['leads']): ?><p class="muted small"><?= $h($t('cu_none')) ?></p><?php endif; ?>
    <?php if ($ov['deals']): ?>
    <table><thead><tr>
      <th><?= $h($t('nav_deals')) ?></th><th><?= $h($t('cu_amount')) ?></th><th><?= $h($t('th_status')) ?></th><th class="small"><?= $h($t('th_created')) ?></th>
    </tr></thead><tbody>
    <?php foreach ($ov['deals'] as $d): ?>
      <tr><td><?= $h($d['title']) ?></td><td><?= $eur($d['amount'], $d['currency']) ?></td>
          <td><?= pill($h, (string)$d['status'], $t) ?></td><td class="small muted"><?= $h(short_time($d['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?php if ($ov['leads']): ?>
    <table><thead><tr>
      <th><?= $h($t('nav_leads')) ?></th><th><?= $h($t('th_source')) ?></th><th><?= $h($t('th_status')) ?></th><th class="small"><?= $h($t('th_created')) ?></th>
    </tr></thead><tbody>
    <?php foreach ($ov['leads'] as $l): ?>
      <tr><td><?= $h($l['title'] ?: $l['customer_name'] ?: ('#' . $l['id'])) ?></td><td class="small"><?= $h($l['source'] ?? '') ?: $dash ?></td>
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
        <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone" value="<?= $h($c['phone'] ?? '') ?>"></label>
        <label class="fld"><span><?= $h($t('cu_phone2')) ?></span><input name="phone2" value="<?= $h($c['phone2'] ?? '') ?>"></label>
      </div>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email" value="<?= $h($c['email'] ?? '') ?>"></label>
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
    <p class="muted" style="margin:4px 0"><?= $h($t('th_source')) ?>: <?= $h($c['source'] ?? '') ?: $dash ?></p>
    <p class="muted" style="margin:4px 0"><?= $h($t('cu_portal')) ?>:
      <?= (int)($c['portal_enabled'] ?? 0) === 1
          ? '<span class="pill pill-up">' . $h($t('cu_portal_on')) . '</span>' . ($c['last_login_at'] ? ' ' . $h(short_time($c['last_login_at'])) : '')
          : '<span class="pill">' . $h($t('cu_portal_off')) . '</span>' ?></p>
  </div>
</div>
</div>

<style>
.cu-top{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap}
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
@media (max-width:1000px){.cu-cols{flex-direction:column}.cu-side{width:100%}}
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
  <?= $chip('no_contact', $t('cu_f_no_contact'), $cnt['no_contact']) ?>
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
    <td><?= (int)$r['active_contracts'] > 0 ? '<span class="pill pill-up">✓</span>' : $dash ?></td>
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
