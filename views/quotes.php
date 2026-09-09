<?php
/**
 * Quotes — the back-office quote queue, and the from-scratch request form.
 *
 * Two things happen here. A seller asks for a quote on a customer the CRM does
 * not have yet (the form at the top — the Jotform replacement: same fields as
 * the lead record, matched on company/VAT or phone/name, and a new lead is
 * created when nothing matches). And the office answers the queue: upload the
 * finished quote against a request, after which the seller who asked sends it
 * to the customer for review and signature.
 *
 * The request that starts INSIDE a lead is on the Leads tab — same table, same
 * queue, different door.
 *
 * In scope: $t, $h, $uid, $isAgent, $scopeId.
 */

use Glue\Crm\QuoteRequests;

// An agent sees the requests they made; the office sees the whole queue.
$qScope  = $isAgent ? (int)$scopeId : null;
$qRows   = QuoteRequests::all($qScope);
$qCounts = QuoteRequests::counts($qScope);
$zones   = \Glue\Crm\Leads::zones();
$sources = \Glue\Crm\Leads::sources();

$qColor = [QuoteRequests::OPEN => 'var(--amber)', QuoteRequests::READY => 'var(--accent)',
           QuoteRequests::SENT => 'var(--green)', QuoteRequests::CANCELLED => 'var(--muted)'];
?>
<h2><?= $h($t('nav_quotes')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('qt_sub')) ?></p>

<div class="grid" style="margin-bottom:16px">
  <?php foreach ([QuoteRequests::OPEN => 'clock', QuoteRequests::READY => 'documents',
                  QuoteRequests::SENT => 'send'] as $qk => $qic): ?>
    <div class="tile">
      <div class="tile-top"><?= svg($qic) ?><span><?= $h($t('qt_st_' . $qk)) ?></span></div>
      <span class="big" style="color:<?= $qColor[$qk] ?>"><?= (int)($qCounts[$qk] ?? 0) ?></span>
      <div class="sub"><?= $h($t('qt_st_' . $qk . '_sub')) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<details class="drawer">
  <summary class="btn" style="margin-bottom:14px"><?= svg('quotes') ?> <?= $h($t('qt_new')) ?></summary>
  <form method="post" class="card" style="margin-top:12px;max-width:920px">
    <input type="hidden" name="do" value="quote_scratch">
    <p class="muted small" style="margin:0 0 14px"><?= $h($t('qt_new_sub')) ?></p>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_first_name')) ?></span><input name="first_name"></label>
      <label class="fld"><span><?= $h($t('f_last_name')) ?></span><input name="last_name"></label>
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone" placeholder="+39…"></label>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_company')) ?></span><input name="company"></label>
      <label class="fld"><span><?= $h($t('f_vat')) ?></span><input name="vat_number" placeholder="<?= $h($t('f_vat_ph')) ?>"></label>
      <label class="fld"><span><?= $h($t('f_zone')) ?></span>
        <input name="zone" list="qzone-list" placeholder="<?= $h($t('f_zone_ph')) ?>"></label>
      <label class="fld"><span><?= $h($t('f_lang')) ?></span>
        <select name="lang"><option value="">—</option><option value="it">IT</option><option value="en">EN</option></select></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_source')) ?></span>
        <input name="source" list="qsrc-list" placeholder="quote"></label>
    </div>
    <label class="fld"><span><?= $h($t('qt_notes')) ?> *</span>
      <textarea name="notes" rows="4" required placeholder="<?= $h($t('qt_notes_ph')) ?>"></textarea></label>
    <p class="muted small" style="margin:-8px 0 14px"><?= $h($t('qt_match_hint')) ?></p>
    <button class="btn"><?= svg('send') ?> <?= $h($t('qt_send_request')) ?></button>
  </form>
</details>

<datalist id="qzone-list"><?php foreach ($zones as $z): ?><option value="<?= $h($z) ?>"><?php endforeach; ?></datalist>
<datalist id="qsrc-list"><?php foreach ($sources as $s): ?><option value="<?= $h($s) ?>"><?php endforeach; ?></datalist>

<h3><?= $h($t('qt_queue')) ?> · <?= count($qRows) ?></h3>
<?php if (!$qRows): ?>
  <div class="card"><div class="empty"><?= $h($t('qt_none')) ?></div></div>
<?php else: ?>
<table>
  <thead><tr>
    <th>#</th><th><?= $h($t('th_customer')) ?></th><th><?= $h($t('qt_notes')) ?></th>
    <th><?= $h($t('qt_asked_by')) ?></th><th><?= $h($t('th_status')) ?></th>
    <th><?= $h($t('qt_quote')) ?></th><th><?= $h($t('th_created')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($qRows as $q): $qst = (string)$q['status']; ?>
    <tr>
      <td class="muted"><?= (int)$q['id'] ?></td>
      <td>
        <b><?= $h($q['customer_name'] ?: ('#' . (int)$q['lead_id'])) ?></b>
        <div class="muted small">
          <?php if (!empty($q['company'])): ?><?= $h($q['company']) ?><?php endif; ?>
          <?php if (!empty($q['vat_number'])): ?> · <?= $h($t('f_vat')) ?> <?= $h($q['vat_number']) ?><?php endif; ?>
          <?php if (!empty($q['zone'])): ?> · <?= $h($q['zone']) ?><?php endif; ?>
        </div>
        <div class="muted small"><?= phone_link($h, $q['customer_phone']) ?> <?= $h($q['customer_email']) ?></div>
      </td>
      <td><div class="note-clip l4" style="max-width:320px;white-space:pre-wrap"><?= $h($q['notes']) ?></div></td>
      <td class="small"><?= $h($q['requester_name'] ?: ($q['requester_username'] ?: '—')) ?></td>
      <td><span class="pill" style="color:<?= $qColor[$qst] ?? 'var(--muted)' ?>"><?= $h($t('qt_st_' . $qst)) ?></span></td>
      <td class="small">
        <?php if (!empty($q['document_id'])): ?>
          <a href="?sdl=<?= (int)$q['document_id'] ?>&k=orig"><?= $h($q['doc_title'] ?: $t('qt_quote')) ?></a>
          <?php // The signing state, in the signing flow's own words (dc_st_*) —
                // draft, awaiting signature, opened, signed. ?>
          <?php $ds = (string)($q['doc_status'] ?? 'draft'); ?>
          <div class="muted small">
            <span class="pill pill-<?= $h($ds) ?>"><?= $h($t('dc_st_' . $ds)) ?></span>
            <?php if (!empty($q['signed_at'])): ?> ✅ <?= $h(short_time($q['signed_at'])) ?><?php endif; ?>
          </div>
        <?php else: ?><span class="muted">—</span><?php endif; ?>
      </td>
      <td class="small muted"><?= $h(short_time($q['created_at'])) ?></td>
      <td style="text-align:right;white-space:nowrap">
        <?php if (empty($isAgent) && $qst !== QuoteRequests::CANCELLED): ?>
          <details class="drawer" style="display:inline-block;text-align:left">
            <summary class="btn ghost tiny"><?= $h($t($qst === QuoteRequests::OPEN ? 'qt_upload' : 'qt_replace')) ?></summary>
            <form method="post" enctype="multipart/form-data" class="card" style="margin-top:8px;min-width:260px">
              <input type="hidden" name="do" value="quote_upload">
              <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
              <label class="fld"><span><?= $h($t('qt_file')) ?></span>
                <input type="file" name="quote" accept="application/pdf" required></label>
              <button class="btn tiny"><?= $h($t('qt_upload')) ?></button>
            </form>
          </details>
        <?php endif; ?>
        <?php if (!empty($q['document_id']) && $qst !== QuoteRequests::CANCELLED): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= $h($t('qt_send_confirm')) ?>')">
            <input type="hidden" name="do" value="quote_send"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
            <button class="btn tiny"><?= svg('send') ?> <?= $h($t($qst === QuoteRequests::SENT ? 'qt_resend' : 'qt_send')) ?></button>
          </form>
        <?php endif; ?>
        <a class="btn ghost tiny" href="?tab=leads&amp;lead=<?= (int)$q['lead_id'] ?>"><?= $h($t('qt_open_lead')) ?></a>
        <?php if (empty($isAgent) && $qst !== QuoteRequests::SENT && $qst !== QuoteRequests::CANCELLED): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= $h($t('qt_cancel_confirm')) ?>')">
            <input type="hidden" name="do" value="quote_cancel"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
            <button class="btn ghost tiny" style="color:var(--red)"><?= $h($t('qt_cancel')) ?></button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
