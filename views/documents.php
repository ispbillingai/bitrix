<?php
/**
 * Documents (electronic signature) — upload a PDF, send it to a customer for
 * signature, watch it move, and pull out the sealed copy and its operation log.
 *
 * The signing itself happens on public/sign.php; this page is the staff side.
 * In scope: $t, $h, $pdo, $agents, $uid, $isAgent, $scopeId.
 */
use Glue\Sign\Audit;
use Glue\Sign\Certificate;
use Glue\Sign\Documents;
use Glue\Sign\Signer;
use Glue\Sign\Timestamp;

$docs     = Documents::all(200, $isAgent ? $scopeId : null);
$counts   = Documents::counts();
$contacts = \Glue\Crm\Contacts::all(300);

$statusColor = [
    'draft' => 'var(--muted)', 'sent' => 'var(--accent)', 'viewed' => 'var(--amber)',
    'signed' => 'var(--green)', 'declined' => 'var(--red)', 'expired' => 'var(--muted)',
    'void' => 'var(--muted)',
];

// The certificate is the thing operators forget until it expires, so its state
// is on the page rather than buried in Settings.
$certInfo = null;
$certErr  = null;
try {
    $certInfo = Certificate::load()->summary();
} catch (Throwable $e) {
    $certErr = $e->getMessage();
}
?>
<h2><?= $h($t('nav_documents')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('dc_sub')) ?></p>

<?php if (empty($isAgent)): ?>
  <?php if ($certErr !== null): ?>
    <div class="card" style="border-color:var(--red);margin-bottom:14px">
      <b style="color:var(--red)"><?= $h($t('dc_cert_error')) ?></b>
      <div class="muted small" style="margin-top:6px"><?= $h($certErr) ?></div>
    </div>
  <?php elseif ($certInfo !== null): ?>
    <div class="card" style="margin-bottom:14px;<?= $certInfo['expired'] || ($certInfo['days_left'] !== null && $certInfo['days_left'] < 30) ? 'border-color:var(--amber)' : '' ?>">
      <div class="row-line" style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap">
        <div style="min-width:0">
          <b><?= $h($t('dc_cert')) ?></b>
          <div class="muted small" style="margin-top:4px;word-break:break-word"><?= $h($certInfo['subject']) ?></div>
          <div class="muted small" style="margin-top:2px">
            <?= $h($t('dc_cert_until')) ?> <?= $h($certInfo['not_after'] ?? '—') ?>
            <?php if ($certInfo['days_left'] !== null): ?>
              · <span style="color:<?= $certInfo['days_left'] < 30 ? 'var(--amber)' : 'var(--muted)' ?>">
                <?= $h(str_replace('{n}', (string)$certInfo['days_left'], $t('dc_cert_days'))) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <span class="pill" style="color:<?= $certInfo['self_signed'] ? 'var(--amber)' : 'var(--green)' ?>">
            <?= $h($certInfo['self_signed'] ? $t('dc_cert_self') : $t('dc_cert_qualified')) ?></span>
          <span class="pill" style="color:<?= Timestamp::enabled() ? 'var(--green)' : 'var(--muted)' ?>">
            <?= $h(Timestamp::enabled() ? $t('dc_tsa_on') : $t('dc_tsa_off')) ?></span>
        </div>
      </div>
      <?php if ($certInfo['self_signed']): ?>
        <div class="muted small" style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
          <?= $h($t('dc_cert_self_help')) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="cards" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
  <?php foreach (['sent' => 'var(--accent)', 'viewed' => 'var(--amber)', 'signed' => 'var(--green)', 'declined' => 'var(--red)'] as $k => $col): ?>
    <div class="card" style="flex:1;min-width:120px;padding:12px 14px">
      <div style="font-size:22px;font-weight:800;color:<?= $col ?>"><?= (int)($counts[$k] ?? 0) ?></div>
      <div class="muted small"><?= $h($t('dc_st_' . $k)) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<details class="drawer">
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('sign') ?> <?= $h($t('dc_new')) ?></summary>
  <form method="post" enctype="multipart/form-data" class="card" style="margin-top:12px">
    <input type="hidden" name="do" value="doc_create">
    <div class="row">
      <label class="fld"><span><?= $h($t('dc_title')) ?></span>
        <input name="title" required placeholder="<?= $h($t('dc_title_ph')) ?>"></label>
      <label class="fld"><span><?= $h($t('dc_file')) ?></span>
        <input type="file" name="document" accept="application/pdf,.pdf" required>
        <small class="muted"><?= $h($t('dc_file_help')) ?></small></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('dc_contact')) ?></span>
        <select name="contact_id">
          <option value=""><?= $h($t('dc_contact_new')) ?></option>
          <?php foreach ($contacts as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= $h($c['name']) ?><?= $c['phone'] ? ' · ' . $h($c['phone']) : '' ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="fld"><span><?= $h($t('dc_lang')) ?></span>
        <select name="lang"><option value="it">Italiano</option><option value="en">English</option></select></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_name')) ?></span><input name="name"></label>
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone"></label>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email"></label>
    </div>
    <p class="muted small" style="margin-bottom:10px"><?= $h($t('dc_contact_help')) ?></p>
    <label style="display:inline-flex;gap:8px;align-items:center;margin-bottom:10px">
      <input type="checkbox" name="send_now" value="1" checked> <?= $h($t('dc_send_now')) ?></label>
    <button class="btn"><?= $h($t('dc_create')) ?></button>
  </form>
</details>

<?php if (!$docs): ?><div class="empty"><?= $h($t('dc_none')) ?></div><?php endif; ?>

<?php foreach ($docs as $d): $did = (int)$d['id']; $status = (string)$d['status'];
    $sig = $status === 'signed' ? Documents::signature($did) : null;
    $link = !empty($d['access_token']) ? Documents::signUrl((string)$d['access_token']) : null;
?>
  <details class="drawer card" style="padding:0;margin-bottom:8px">
    <summary class="dw-sum">
      <?= avatar($h, $d['contact_name'] ?: $d['signer_name']) ?>
      <span class="dw-info">
        <b><?= $h($d['title']) ?></b>
        <span class="muted small"> · <?= $h($d['contact_name'] ?: $d['signer_name']) ?>
          · <?= $h(date('d/m/Y', strtotime((string)$d['created_at']))) ?></span>
      </span>
      <span class="pill" style="color:<?= $statusColor[$status] ?? 'var(--muted)' ?>"><?= $h($t('dc_st_' . $status)) ?></span>
    </summary>

    <div style="padding:6px 18px 18px;border-top:1px solid var(--line)">
      <div class="cols c-1-1" style="margin-bottom:12px">
        <div>
          <h3><?= $h($t('dc_h_doc')) ?></h3>
          <table><tbody>
            <tr><td class="muted small"><?= $h($t('dc_file')) ?></td><td class="small"><?= $h($d['orig_name']) ?>
              · <?= $h(number_format((float)$d['orig_bytes'] / 1024, 0, ',', '.')) ?> KB</td></tr>
            <tr><td class="muted small"><?= $h($t('dc_ref')) ?></td>
              <td class="small" style="font-family:ui-monospace,Menlo,monospace;word-break:break-all"><?= $h(strtoupper((string)$d['uid'])) ?></td></tr>
            <tr><td class="muted small">SHA-256</td>
              <td class="small" style="font-family:ui-monospace,Menlo,monospace;word-break:break-all"><?= $h(strtoupper((string)$d['orig_sha256'])) ?></td></tr>
            <tr><td class="muted small"><?= $h($t('th_agent')) ?></td>
              <td class="small"><?= $h($d['full_name'] ?: ($d['username'] ?: '—')) ?></td></tr>
          </tbody></table>
        </div>
        <div>
          <h3><?= $h($t('dc_h_signer')) ?></h3>
          <table><tbody>
            <tr><td class="muted small"><?= $h($t('f_name')) ?></td><td class="small"><?= $h($d['signer_name']) ?></td></tr>
            <tr><td class="muted small"><?= $h($t('f_phone')) ?></td><td class="small"><?= $h($d['signer_phone'] ?: '—') ?></td></tr>
            <tr><td class="muted small"><?= $h($t('f_email')) ?></td><td class="small"><?= $h($d['signer_email'] ?: '—') ?></td></tr>
            <?php if ($sig): ?>
              <tr><td class="muted small"><?= $h($t('dc_signed_at')) ?></td>
                <td class="small"><?= $h(date('d/m/Y H:i:s', strtotime((string)$sig['signed_at']))) ?></td></tr>
              <tr><td class="muted small"><?= $h($t('dc_otp_to')) ?></td><td class="small"><?= $h($sig['otp_sent_to']) ?></td></tr>
              <tr><td class="muted small">IP</td><td class="small"><?= $h($sig['ip'] ?: '—') ?></td></tr>
            <?php elseif (!empty($d['viewed_at'])): ?>
              <tr><td class="muted small"><?= $h($t('dc_viewed_at')) ?></td>
                <td class="small"><?= $h(date('d/m/Y H:i', strtotime((string)$d['viewed_at']))) ?></td></tr>
            <?php endif; ?>
            <?php if ($status === 'declined'): ?>
              <tr><td class="muted small"><?= $h($t('dc_decline_reason')) ?></td>
                <td class="small"><?= $h($d['decline_reason'] ?: '—') ?></td></tr>
            <?php endif; ?>
          </tbody></table>
        </div>
      </div>

      <?php if ($link !== null && $status !== 'signed'): ?>
        <div style="background:var(--surface2);border:1px dashed var(--line);border-radius:10px;padding:10px 12px;
                    font-family:ui-monospace,Menlo,monospace;font-size:12px;word-break:break-all;margin-bottom:12px">
          <?= $h($link) ?>
        </div>
      <?php endif; ?>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        <a class="btn tiny ghost" href="?tab=documents&amp;sdl=<?= $did ?>&amp;k=orig"><?= $h($t('dc_dl_orig')) ?></a>
        <?php if ($status === 'signed'): ?>
          <a class="btn tiny" href="?tab=documents&amp;sdl=<?= $did ?>&amp;k=signed"><?= $h($t('dc_dl_signed')) ?></a>
          <a class="btn tiny ghost" href="<?= $h(Signer::verifyUrl((string)$d['uid'])) ?>" target="_blank" rel="noopener">
            <?= $h($t('dc_verify')) ?></a>
        <?php else: ?>
          <form method="post" class="inline">
            <input type="hidden" name="do" value="doc_send"><input type="hidden" name="id" value="<?= $did ?>">
            <button class="btn tiny"><?= $h($status === 'draft' ? $t('dc_send') : $t('dc_resend')) ?></button>
          </form>
          <form method="post" class="inline" onsubmit="return confirm('<?= $h($t('dc_void_confirm')) ?>')">
            <input type="hidden" name="do" value="doc_void"><input type="hidden" name="id" value="<?= $did ?>">
            <button class="btn tiny ghost"><?= $h($t('dc_void')) ?></button>
          </form>
        <?php endif; ?>
      </div>

      <?php $trail = Audit::forDocument($did); $chain = Audit::verify($did); ?>
      <h3><?= $h($t('dc_h_log')) ?>
        <span class="pill" style="color:<?= $chain['ok'] ? 'var(--green)' : 'var(--red)' ?>;margin-left:8px">
          <?= $h($chain['ok'] ? $t('dc_chain_ok') : $t('dc_chain_bad')) ?></span></h3>
      <?php if (!$chain['ok']): ?>
        <p class="small" style="color:var(--red)">
          <?= $h(str_replace('{n}', (string)$chain['broken_at'], $t('dc_chain_broken'))) ?> <?= $h((string)$chain['reason']) ?></p>
      <?php endif; ?>
      <table><thead><tr>
        <th>#</th><th><?= $h($t('dc_c_when')) ?></th><th><?= $h($t('dc_c_event')) ?></th>
        <th><?= $h($t('dc_c_actor')) ?></th><th>IP</th>
      </tr></thead><tbody>
        <?php foreach ($trail as $r): ?>
          <tr>
            <td class="small muted"><?= (int)$r['seq'] ?></td>
            <td class="small" style="white-space:nowrap"><?= $h(substr((string)$r['occurred_at'], 0, 19)) ?></td>
            <td class="small"><?= $h($t('dc_ev_' . $r['event']) !== 'dc_ev_' . $r['event'] ? $t('dc_ev_' . $r['event']) : $r['event']) ?></td>
            <td class="small"><?= $h(trim((string)($r['actor_label'] ?? '')) ?: (string)$r['actor_type']) ?></td>
            <td class="small muted"><?= $h($r['ip'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
  </details>
<?php endforeach; ?>
