<?php
/**
 * Partners (referrers) — admin management. Create/edit partners, set their
 * commission rate, copy their referral link, and review + approve/pay the
 * commission accruals their won referrals generated. In scope: $t, $h, $pdo.
 */
use Glue\Partner\Partners;

$partners = Partners::all();
$base = \Glue\Config::appBaseUrl();
$money = fn($n) => (string)\Glue\Config::get('crm.currency', 'EUR') . ' ' . number_format((float)$n, 2);
?>
<h2><?= $h($t('nav_partners')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('pt_sub')) ?></p>

<details class="drawer">
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('partners') ?> <?= $h($t('pt_add')) ?></summary>
  <form method="post" class="card" style="margin-top:12px">
    <input type="hidden" name="do" value="partner_save">
    <input type="hidden" name="id" value="">
    <div class="row">
      <label class="fld"><span><?= $h($t('pt_name')) ?></span><input name="name" required></label>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email"></label>
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('pt_ref')) ?></span><input name="ref_code" placeholder="<?= $h($t('pt_ref_ph')) ?>"></label>
      <label class="fld"><span><?= $h($t('pt_pct')) ?></span><input name="commission_pct" type="number" step="0.5" min="0" max="100" value="10"></label>
      <label class="fld"><span><?= $h($t('pt_pw')) ?></span><input name="password" type="password" placeholder="<?= $h($t('pt_pw_ph')) ?>"></label>
    </div>
    <label style="display:inline-flex;gap:8px;align-items:center;margin-bottom:10px"><input type="checkbox" name="active" checked> <?= $h($t('na_active')) ?></label>
    <button class="btn"><?= $h($t('save')) ?></button>
  </form>
</details>

<?php if (!$partners): ?><div class="empty"><?= $h($t('pt_none')) ?></div><?php endif; ?>
<?php foreach ($partners as $p): $pid = (int)$p['id'];
    $tot = Partners::totals($pid);
    $refs = Partners::referrals($pid);
    $accr = Partners::accruals($pid);
    $refUrl = $base . '/request.php?ref=' . rawurlencode((string)$p['ref_code']);
?>
  <details class="drawer card" style="padding:0;margin-bottom:8px">
    <summary style="display:flex;align-items:center;gap:12px;padding:13px 18px;cursor:pointer">
      <?= avatar($h, $p['name']) ?>
      <span style="flex:1;min-width:0"><b><?= $h($p['name']) ?></b>
        <span class="muted small"> · <?= number_format((float)$p['commission_pct'], 1) ?>% · <?= count($refs) ?> <?= $h($t('pt_refs')) ?></span></span>
      <span class="pill" title="<?= $h($t('pt_pending')) ?>" style="color:var(--amber)"><?= $h($money($tot['pending'])) ?></span>
      <span class="badge <?= (int)$p['active'] ? 'ok' : 'no' ?>"><span class="dot"></span><?= (int)$p['active'] ? $h($t('u_active')) : $h($t('u_disabled')) ?></span>
    </summary>
    <div style="padding:6px 18px 18px;border-top:1px solid var(--line)">
      <div class="reflink" style="background:var(--surface2);border:1px dashed var(--line);border-radius:10px;padding:10px 12px;font-family:ui-monospace,Menlo,monospace;font-size:12px;word-break:break-all;margin:6px 0 14px">
        <?= $h($refUrl) ?>
      </div>

      <form method="post" class="card" style="background:var(--surface2)">
        <input type="hidden" name="do" value="partner_save"><input type="hidden" name="id" value="<?= $pid ?>">
        <div class="row">
          <label class="fld"><span><?= $h($t('pt_name')) ?></span><input name="name" value="<?= $h($p['name']) ?>" required></label>
          <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email" value="<?= $h($p['email'] ?? '') ?>"></label>
          <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone" value="<?= $h($p['phone'] ?? '') ?>"></label>
        </div>
        <div class="row">
          <label class="fld"><span><?= $h($t('pt_ref')) ?></span><input name="ref_code" value="<?= $h($p['ref_code']) ?>"></label>
          <label class="fld"><span><?= $h($t('pt_pct')) ?></span><input name="commission_pct" type="number" step="0.5" min="0" max="100" value="<?= $h($p['commission_pct']) ?>"></label>
          <label class="fld"><span><?= $h($t('pt_pw')) ?></span><input name="password" type="password" placeholder="<?= $h($t('pt_pw_keep')) ?>"></label>
        </div>
        <label style="display:inline-flex;gap:8px;align-items:center;margin-bottom:10px"><input type="checkbox" name="active" <?= (int)$p['active'] ? 'checked' : '' ?>> <?= $h($t('na_active')) ?></label>
        <button class="btn tiny"><?= $h($t('save')) ?></button>
      </form>

      <div class="cols c-1-1" style="margin-top:14px">
        <div>
          <h3><?= $h($t('pt_accruals')) ?></h3>
          <div class="muted small" style="margin-bottom:8px">
            <?= $h($t('pt_pending')) ?>: <strong style="color:var(--amber)"><?= $h($money($tot['pending'])) ?></strong> ·
            <?= $h($t('pt_approved')) ?>: <strong style="color:var(--accent)"><?= $h($money($tot['approved'])) ?></strong> ·
            <?= $h($t('pt_paid')) ?>: <strong style="color:var(--green)"><?= $h($money($tot['paid'])) ?></strong>
          </div>
          <?php if (!$accr): ?><div class="empty"><?= $h($t('pt_no_accruals')) ?></div><?php else: ?>
          <table><thead><tr><th><?= $h($t('customer')) ?></th><th><?= $h($t('amount')) ?></th><th><?= $h($t('th_status')) ?></th><th></th></tr></thead><tbody>
            <?php foreach ($accr as $a): ?>
            <tr>
              <td class="small"><?= $h($a['customer_name'] ?: $a['deal_title'] ?: '—') ?></td>
              <td><strong><?= $h($money($a['amount'])) ?></strong></td>
              <td><span class="pill"><?= $h($t('acc_' . $a['status'])) ?></span></td>
              <td>
                <?php if ($a['status'] === 'pending'): ?>
                  <form method="post" class="inline"><input type="hidden" name="do" value="accrual_status"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="status" value="approved"><button class="btn tiny ghost"><?= $h($t('pt_approve')) ?></button></form>
                <?php elseif ($a['status'] === 'approved'): ?>
                  <form method="post" class="inline"><input type="hidden" name="do" value="accrual_status"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="status" value="paid"><button class="btn tiny"><?= $h($t('pt_mark_paid')) ?></button></form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody></table>
          <?php endif; ?>
        </div>
        <div>
          <h3><?= $h($t('pt_referrals')) ?></h3>
          <?php if (!$refs): ?><div class="empty"><?= $h($t('pt_no_refs')) ?></div><?php else: ?>
          <table><thead><tr><th><?= $h($t('customer')) ?></th><th><?= $h($t('pt_stage')) ?></th><th><?= $h($t('th_status')) ?></th></tr></thead><tbody>
            <?php foreach ($refs as $r): ?>
            <tr>
              <td class="small"><?= $h($r['customer_name'] ?: ('#' . $r['id'])) ?></td>
              <td><span class="pill"><?= $h(stage_label($t, $r['stage_code'], \Glue\Crm\Pipelines::label('lead', $r['stage_code']))) ?></span></td>
              <td><?= pill($h, $r['status'], $t) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody></table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </details>
<?php endforeach; ?>
