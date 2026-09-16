<?php
/**
 * Finanziamenti — the office side of the lead document area. The list of
 * applications (the ones handed over for checking first), one customer's folder
 * with every required document in its own row, and the button that sends the
 * folder on to one or more lenders, each through its own link. Admin only: the
 * view is in neither the agent nor the technician list.
 *
 * In scope: $t, $h, $uid, $lang, $pdo.
 */

use Glue\Finance\Docs;

$finStatuses = ['collecting', 'review', 'sent', 'closed'];
$fStatus = in_array($_GET['fs'] ?? '', $finStatuses, true) ? (string)$_GET['fs'] : '';
$openId  = (int)($_GET['app'] ?? 0);
if ($openId === 0 && !empty($_GET['lead'])) {
    $byLead = Docs::appForLead((int)$_GET['lead']);
    $openId = (int)($byLead['id'] ?? 0);
}
$apps    = Docs::allApps(['status' => $fStatus ?: null]);
$lenders = Docs::lenders(false);
$counts  = ['collecting' => 0, 'review' => 0, 'sent' => 0, 'closed' => 0];
foreach (Docs::allApps() as $a) { $counts[$a['status']] = ($counts[$a['status']] ?? 0) + 1; }
$money   = fn($n): string => '€ ' . number_format((float)$n, 2, ',', '.');
$stPill  = fn(string $s): string => 'cm-st-' . ['collecting' => 'sent', 'review' => 'invoiced', 'sent' => 'paid', 'closed' => 'cancelled'][$s];
?>
<h2><?= $h($t('nav_finance')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('fin_office_sub')) ?></p>

<div class="cm-chips">
  <?php foreach (['' => 'fin_filter_all', 'review' => 'fin_st_review', 'collecting' => 'fin_st_collecting', 'sent' => 'fin_st_sent', 'closed' => 'fin_st_closed'] as $k => $lbl): ?>
    <a class="cm-chip<?= $fStatus === $k ? ' on' : '' ?>" href="<?= $h('?' . http_build_query(array_filter(['tab' => 'finance', 'fs' => $k], 'strlen'))) ?>">
      <?= $h($t($lbl)) ?><?php if ($k !== '') { echo ' <b>' . (int)($counts[$k] ?? 0) . '</b>'; } ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$apps): ?><div class="card"><div class="empty"><?= $h($t('fin_none')) ?></div></div><?php endif; ?>

<?php foreach ($apps as $a): $id = (int)$a['id']; $p = Docs::progress($id); $open = $openId === $id; ?>
  <details class="cm-card" id="app-<?= $id ?>"<?= $open ? ' open' : '' ?>>
    <summary>
      <span class="cm-t"><b><?= $h($a['customer_name'] ?: ('#' . (int)$a['lead_id'])) ?><?= $a['company'] ? ' · ' . $h($a['company']) : '' ?></b>
        <span class="muted small"><?= $h($t('fin_app')) ?> <?= $id ?> · <?= $h(substr((string)$a['created_at'], 0, 10)) ?>
          <?= $a['agent_name'] ? ' · ' . $h($a['agent_name']) : '' ?></span></span>
      <span class="cm-amt"><?= $a['amount'] !== null ? $h($money($a['amount'])) : '—' ?></span>
      <span class="muted small"><?= (int)$p['done'] ?>/<?= (int)$p['required'] ?></span>
      <span class="cm-st <?= $h($stPill((string)$a['status'])) ?>"><?= $h($t('fin_st_' . $a['status'])) ?></span>
    </summary>
    <div class="cm-body">
      <?php $rows = Docs::checklist($id); $general = Docs::forLead((int)$a['lead_id']); $shares = Docs::shares($id); $appLenders = Docs::lenderIds($a); ?>

      <div class="cm-steps">
        <div class="cm-step done"><b>1 · <?= $h($t('fin_step_docs')) ?></b><?= (int)$p['done'] ?>/<?= (int)$p['required'] ?></div>
        <div class="cm-step<?= $a['reviewed_at'] ? ' done' : '' ?>"><b>2 · <?= $h($t('fin_step_check')) ?></b><?= $h($a['reviewed_at'] ? substr((string)$a['reviewed_at'], 0, 16) : $t('fin_waiting')) ?></div>
        <div class="cm-step<?= $a['sent_at'] ? ' done' : '' ?>"><b>3 · <?= $h($t('fin_step_send')) ?></b><?= $h($a['sent_at'] ? substr((string)$a['sent_at'], 0, 16) : $t('fin_waiting')) ?></div>
      </div>

      <?php if ($p['missing']): ?>
        <div class="cm-warn">⚠ <?= $h($t('fin_missing')) ?>: <?= $h(implode(', ', $p['missing'])) ?></div>
      <?php endif; ?>

      <div class="cm-strip" style="margin-bottom:12px">
        <a class="btn tiny ghost" href="?tab=leads&lead=<?= (int)$a['lead_id'] ?>#lead-<?= (int)$a['lead_id'] ?>"><?= $h($t('fin_open_lead')) ?></a>
        <a class="btn tiny ghost" href="<?= $h(Docs::uploadUrl((string)$a['token'])) ?>" target="_blank"><?= $h($t('fin_open_link')) ?></a>
        <span class="muted small" style="flex:1;overflow-wrap:anywhere"><?= $h(Docs::uploadUrl((string)$a['token'])) ?></span>
      </div>

      <?php // The application itself: how much, what for, and which lenders it is aimed at. ?>
      <form method="post" class="card" style="background:var(--surface2)">
        <input type="hidden" name="do" value="fin_save"><input type="hidden" name="app_id" value="<?= $id ?>">
        <div class="row">
          <label class="fld"><span><?= $h($t('fin_amount')) ?></span><input name="amount" inputmode="decimal" value="<?= $a['amount'] !== null ? $h(number_format((float)$a['amount'], 2, ',', '')) : '' ?>"></label>
          <label class="fld"><span><?= $h($t('fin_purpose')) ?></span><input name="purpose" maxlength="190" value="<?= $h($a['purpose'] ?? '') ?>" placeholder="<?= $h($t('fin_purpose_ph')) ?>"></label>
        </div>
        <label class="fld"><span><?= $h($t('fin_lenders_for')) ?></span>
          <span style="display:flex;gap:12px;flex-wrap:wrap">
            <?php foreach ($lenders as $l): if (!(int)$l['active'] && !in_array((int)$l['id'], $appLenders, true)) { continue; } ?>
              <label style="display:inline-flex;gap:6px;align-items:center;font-weight:500">
                <input type="checkbox" name="lender_ids[]" value="<?= (int)$l['id'] ?>" style="width:auto"<?= in_array((int)$l['id'], $appLenders, true) ? ' checked' : '' ?>>
                <?= $h($l['name']) ?></label>
            <?php endforeach; ?>
            <?php if (!$lenders): ?><span class="muted small"><?= $h($t('fin_no_lenders')) ?></span><?php endif; ?>
          </span>
          <small class="muted"><?= $h($t('fin_lenders_for_h')) ?></small>
        </label>
        <label class="fld"><span><?= $h($t('fin_notes')) ?></span><textarea name="notes" rows="2"><?= $h($a['notes'] ?? '') ?></textarea></label>
        <button class="btn tiny"><?= $h($t('save')) ?></button>
      </form>

      <h3 style="margin:16px 0 8px"><?= $h($t('fin_checklist')) ?></h3>
      <?php foreach ($rows as $row): ?>
        <div class="cm-acc">
          <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap">
            <b><?= $h($row['label']) ?></b>
            <?php if ($row['lender']): ?><span class="pill"><?= $h($row['lender']) ?></span><?php endif; ?>
            <?php if ($row['required']): ?>
              <span class="cm-st <?= $row['files'] ? 'cm-st-paid' : 'cm-st-sent' ?>"><?= $h($row['files'] ? $t('fin_have') : $t('fin_missing_one')) ?></span>
            <?php else: ?><span class="muted small"><?= $h($t('fin_optional')) ?></span><?php endif; ?>
          </div>
          <?php if (!empty($row['per_lender']) && !$row['lender']): // one row per lender, once they are chosen ?>
            <div class="muted small" style="margin-top:4px"><?= $h($t('fin_privacy_pick_lender')) ?></div>
          <?php endif; ?>
          <?php foreach ($row['files'] as $f): ?>
            <div class="lb">
              <span class="nm" style="min-width:0"><a href="?ldl=<?= (int)$f['id'] ?>">📎 <?= $h($f['name']) ?></a>
                <div class="muted small"><?= $h(Docs::size((int)$f['size_bytes'])) ?> · <?= $h(substr((string)$f['created_at'], 0, 16)) ?><?= $f['uploader_name'] ? ' · ' . $h($f['uploader_name']) : '' ?></div></span>
              <form method="post" class="inline" onsubmit="return confirm('<?= $h($t('fin_del_confirm')) ?>')">
                <input type="hidden" name="do" value="fin_file_del"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                <input type="hidden" name="app_id" value="<?= $id ?>">
                <button class="btn tiny ghost" style="color:var(--red)"><?= $h($t('delete')) ?></button></form>
            </div>
          <?php endforeach; ?>
          <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px">
            <input type="hidden" name="do" value="fin_upload"><input type="hidden" name="app_id" value="<?= $id ?>">
            <input type="hidden" name="slot" value="<?= $h($row['code']) ?>"><input type="hidden" name="lender_id" value="<?= (int)$row['lender_id'] ?>">
            <input type="file" name="files[]" multiple required style="flex:1 1 200px;min-width:0">
            <button class="btn tiny ghost"><?= $h($t('fin_upload_one')) ?></button>
          </form>
        </div>
      <?php endforeach; ?>

      <?php if ($general): ?>
        <h3 style="margin:16px 0 8px"><?= $h($t('fin_general_docs')) ?> · <?= count($general) ?></h3>
        <div class="card" style="background:var(--surface2)">
          <?php foreach ($general as $f): ?>
            <div class="lb"><span class="nm" style="min-width:0"><a href="?ldl=<?= (int)$f['id'] ?>">📎 <?= $h($f['name']) ?></a>
              <div class="muted small"><?= $h(Docs::size((int)$f['size_bytes'])) ?> · <?= $h(substr((string)$f['created_at'], 0, 16)) ?></div></span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php // Checked, then sent: one link per lender, the same folder with its own privacy form. ?>
      <div class="cm-form" style="margin-top:14px">
        <b class="small"><?= $h($t('fin_send_h')) ?></b>
        <p class="muted small" style="margin:4px 0 10px"><?= $h($t('fin_send_hint')) ?></p>
        <?php if (!$a['reviewed_at']): ?>
          <form method="post" class="inline"><input type="hidden" name="do" value="fin_review"><input type="hidden" name="app_id" value="<?= $id ?>">
            <button class="btn tiny">✓ <?= $h($t('fin_review_btn')) ?></button></form>
        <?php else: ?>
          <form method="post" class="inline"><input type="hidden" name="do" value="fin_reopen"><input type="hidden" name="app_id" value="<?= $id ?>">
            <button class="btn tiny ghost"><?= $h($t('fin_reopen_btn')) ?></button></form>
        <?php endif; ?>
        <?php $activeLenders = array_values(array_filter($lenders, fn($fl) => (int)$fl['active'] === 1)); ?>
        <?php if (!$activeLenders): // nothing to send to yet: say so instead of a dead button ?>
          <div class="cm-warn" style="margin:0">⚠ <?= $h($t('fin_need_lender')) ?>
            <a href="#finanziarie"><?= $h($t('fin_need_lender_go')) ?></a></div>
        <?php else: ?>
          <form method="post" style="margin-top:10px">
            <input type="hidden" name="do" value="fin_share"><input type="hidden" name="app_id" value="<?= $id ?>">
            <span style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px">
              <?php foreach ($activeLenders as $l): ?>
                <label style="display:inline-flex;gap:6px;align-items:center">
                  <input type="checkbox" name="lender_ids[]" value="<?= (int)$l['id'] ?>" style="width:auto"<?= in_array((int)$l['id'], $appLenders, true) ? ' checked' : '' ?>>
                  <?= $h($l['name']) ?></label>
              <?php endforeach; ?>
            </span>
            <button class="btn tiny"><?= svg('send') ?> <?= $h($t('fin_send_btn')) ?></button>
          </form>
        <?php endif; ?>
        <?php if ($shares): ?>
          <div style="margin-top:12px">
            <?php foreach ($shares as $sh): $url = Docs::shareUrl((string)$sh['token']); ?>
              <div class="lb">
                <span class="nm" style="min-width:0"><b><?= $h($sh['lender_name']) ?></b>
                  <div class="muted small" style="overflow-wrap:anywhere"><?= $h($url) ?></div>
                  <div class="muted small"><?= $sh['revoked_at']
                      ? $h($t('fin_revoked'))
                      : ((int)$sh['opens'] > 0 ? (int)$sh['opens'] . ' ' . $h($t('fin_opens')) . ' · ' . $h(substr((string)$sh['last_opened_at'], 0, 16)) : $h($t('fin_never_opened'))) ?>
                    <?= !empty($sh['sent_to']) ? ' · ' . $h(sprintf($t('fin_sent_note'), (string)$sh['sent_to'], substr((string)$sh['sent_at'], 0, 16))) : '' ?></div></span>
                <?php if (!$sh['revoked_at']): ?>
                  <form method="post" class="inline" style="flex-wrap:wrap">
                    <input type="hidden" name="do" value="fin_share_send"><input type="hidden" name="share_id" value="<?= (int)$sh['id'] ?>">
                    <input type="hidden" name="app_id" value="<?= $id ?>">
                    <input name="to" value="<?= $h($sh['lender_email'] ?: ($sh['lender_phone'] ?? '')) ?>" placeholder="<?= $h($t('fin_send_to_ph')) ?>" style="max-width:210px">
                    <button class="btn tiny"><?= svg('send') ?> <?= $h($t('fin_send_to_btn')) ?></button>
                  </form>
                  <button type="button" class="btn tiny ghost" onclick="navigator.clipboard.writeText('<?= $h($url) ?>').then(()=>{this.textContent='✓';})"><?= $h($t('fin_copy')) ?></button>
                  <form method="post" class="inline" onsubmit="return confirm('<?= $h($t('fin_revoke_confirm')) ?>')">
                    <input type="hidden" name="do" value="fin_share_revoke"><input type="hidden" name="share_id" value="<?= (int)$sh['id'] ?>">
                    <input type="hidden" name="app_id" value="<?= $id ?>">
                    <button class="btn tiny ghost" style="color:var(--red)"><?= $h($t('fin_revoke')) ?></button></form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <form method="post" style="margin-top:12px" onsubmit="return confirm('<?= $h($t('fin_close_confirm')) ?>')">
        <input type="hidden" name="do" value="fin_close"><input type="hidden" name="app_id" value="<?= $id ?>">
        <button class="btn tiny ghost"><?= $h($t($a['status'] === 'closed' ? 'fin_closed' : 'fin_close_btn')) ?></button>
      </form>
    </div>
  </details>
<?php endforeach; ?>

<?php // The institutions themselves, each with the blank privacy form the customer signs. ?>
<h3 id="finanziarie" style="margin:22px 0 10px"><?= $h($t('fin_lenders_h')) ?></h3>
<details class="drawer">
  <summary class="btn ghost" style="margin-bottom:12px"><?= $h($t('fin_lender_add')) ?></summary>
  <form method="post" enctype="multipart/form-data" class="card" style="margin-top:10px;max-width:820px">
    <input type="hidden" name="do" value="fin_lender_save"><input type="hidden" name="id" value="">
    <div class="row">
      <label class="fld"><span><?= $h($t('fin_lender_name')) ?> *</span><input name="name" required maxlength="150"></label>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email" maxlength="190"></label>
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone" maxlength="32"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('fin_privacy_file')) ?></span><input type="file" name="privacy" accept=".pdf,.doc,.docx,image/*">
        <small class="muted"><?= $h($t('fin_privacy_h')) ?></small></label>
      <label class="fld"><span><?= $h($t('fin_lender_notes')) ?></span><input name="notes" maxlength="500"></label>
    </div>
    <label style="display:inline-flex;gap:8px;align-items:center;margin-bottom:10px"><input type="checkbox" name="active" value="1" checked style="width:auto"> <?= $h($t('na_active')) ?></label>
    <button class="btn"><?= $h($t('save')) ?></button>
  </form>
</details>

<?php if (!$lenders): ?><div class="empty"><?= $h($t('fin_no_lenders')) ?></div><?php endif; ?>
<?php foreach ($lenders as $l): $lid = (int)$l['id']; ?>
  <details class="drawer card" style="padding:0;margin-bottom:8px">
    <summary class="dw-sum">
      <?= avatar($h, $l['name']) ?>
      <span class="dw-info"><b><?= $h($l['name']) ?></b>
        <span class="muted small"><?= $l['email'] ? ' · ' . $h($l['email']) : '' ?><?= $l['phone'] ? ' · ' . $h($l['phone']) : '' ?></span></span>
      <?php if ($l['privacy_path']): ?><span class="pill"><?= $h($t('fin_privacy_ok')) ?></span><?php endif; ?>
      <span class="badge <?= (int)$l['active'] ? 'ok' : 'no' ?>"><span class="dot"></span><?= (int)$l['active'] ? $h($t('u_active')) : $h($t('u_disabled')) ?></span>
    </summary>
    <div style="padding:6px 18px 18px;border-top:1px solid var(--line)">
      <form method="post" enctype="multipart/form-data" class="card" style="background:var(--surface2)">
        <input type="hidden" name="do" value="fin_lender_save"><input type="hidden" name="id" value="<?= $lid ?>">
        <div class="row">
          <label class="fld"><span><?= $h($t('fin_lender_name')) ?> *</span><input name="name" required maxlength="150" value="<?= $h($l['name']) ?>"></label>
          <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email" maxlength="190" value="<?= $h($l['email'] ?? '') ?>"></label>
          <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone" maxlength="32" value="<?= $h($l['phone'] ?? '') ?>"></label>
        </div>
        <div class="row">
          <label class="fld"><span><?= $h($t('fin_privacy_file')) ?></span><input type="file" name="privacy" accept=".pdf,.doc,.docx,image/*">
            <small class="muted"><?php if ($l['privacy_path']): ?><a href="?lpr=<?= $lid ?>">📄 <?= $h($l['privacy_name']) ?></a> · <?php endif; ?><?= $h($t('fin_privacy_h')) ?></small></label>
          <label class="fld"><span><?= $h($t('fin_lender_notes')) ?></span><input name="notes" maxlength="500" value="<?= $h($l['notes'] ?? '') ?>"></label>
        </div>
        <label style="display:inline-flex;gap:8px;align-items:center;margin-bottom:10px"><input type="checkbox" name="active" value="1"<?= (int)$l['active'] ? ' checked' : '' ?> style="width:auto"> <?= $h($t('na_active')) ?></label>
        <button class="btn tiny"><?= $h($t('save')) ?></button>
      </form>
      <form method="post" style="margin-top:10px" onsubmit="return confirm('<?= $h($t('fin_lender_del_confirm')) ?>')">
        <input type="hidden" name="do" value="fin_lender_del"><input type="hidden" name="id" value="<?= $lid ?>">
        <button class="btn tiny ghost" style="color:var(--red)"><?= $h($t('delete')) ?></button>
        <span class="muted small" style="margin-left:8px"><?= $h($t('fin_lender_del_hint')) ?></span>
      </form>
    </div>
  </details>
<?php endforeach; ?>
