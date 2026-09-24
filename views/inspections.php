<?php
/**
 * Surveys (sopralluoghi) — the technician's site report, and the opinion the
 * technical group writes once the customer has signed it.
 *
 * Four states in one page. DRAFT: fill the sheet, attach the photos. SENT: the
 * PDF is with the customer for signature and the survey is read-only. SIGNED:
 * it is a question for the technical group — one of them takes it in charge and
 * writes the opinion, text and stars. REVIEWED: the opinion has gone.
 *
 * In scope: $t, $h, $pdo, $uid, $isAgent, $isTech, $scopeId.
 */

use Glue\Crm\Customers;
use Glue\Inspect\Inspections;
use Glue\Install\Reports as InstallReports;

$isAdminHere = !$isAgent && !$isTech;
// A technician sees their own surveys AND every one waiting for an opinion:
// that queue is the whole group's until somebody claims it.
$ownScope = $isAdminHere ? null : (int)$uid;

$statusColor = ['draft' => 'var(--muted)', 'sent' => 'var(--accent)',
                'signed' => 'var(--amber)', 'reviewed' => 'var(--green)'];

$ispId = (int)($_GET['id'] ?? 0);
$r = $ispId > 0 ? Inspections::find($ispId) : null;
// A technician may open their own, or any one waiting to be judged.
if ($r && $ownScope !== null
    && (int)$r['created_by'] !== $ownScope
    && (int)($r['claimed_by'] ?? 0) !== $ownScope
    && (string)$r['status'] !== 'signed') {
    $r = null;
}

$dtLocal = fn(?string $v): string => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$dtHuman = fn(?string $v): string => $v ? date('d/m/Y H:i', strtotime($v)) : '—';

if ($r !== null):
    // ======================= one survey =======================
    $contact = \Glue\Crm\Contacts::find((int)$r['contact_id']) ?: [];
    $photos  = Inspections::photos((int)$r['id']);
    $items   = Inspections::items((int)$r['id']);
    $status  = (string)$r['status'];
    $isDraft = $status === 'draft';
    $mine    = (int)($r['claimed_by'] ?? 0) === (int)$uid;
    $mayJudge = $status === 'signed' && ($isAdminHere || $mine);
    $hasChannel = trim((string)($contact['phone'] ?? '')) !== '' || trim((string)($contact['email'] ?? '')) !== '';
?>
<div class="cu-top">
  <a class="btn ghost tiny" href="?tab=inspections">&larr; <?= $h($t('isp_back')) ?></a>
  <h2 style="margin:0"><?= avatar($h, (string)($contact['name'] ?? '')) ?> <?= $h($contact['name'] ?? '') ?>
    <span class="pill" style="color:<?= $statusColor[$status] ?? 'var(--muted)' ?>"><?= $h($t('isp_st_' . $status)) ?></span>
    <span class="muted small">#<?= (int)$r['id'] ?></span>
  </h2>
</div>

<?php if ($isDraft): ?>
  <form method="post" class="card" style="margin-bottom:14px">
    <input type="hidden" name="do" value="insp_save">
    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <h3 style="margin-top:0"><?= svg('installations') ?> <?= $h($t('isp_data')) ?></h3>
    <label class="fld"><span><?= $h($t('isp_f_when')) ?></span>
      <input type="datetime-local" name="inspected_at" value="<?= $h($dtLocal($r['inspected_at'])) ?>"></label>

    <?php // The checklist: tick what is on site, describe it beside the tick.
          // The description is kept either way — "non presente, ne vuole uno"
          // is exactly what a survey is for. ?>
    <fieldset class="isp-items">
      <legend><?= $h($t('isp_items')) ?></legend>
      <?php foreach ($items as $it): ?>
        <div class="isp-item">
          <label class="isp-tick">
            <input type="checkbox" name="items[<?= $h($it['code']) ?>]" value="1" <?= $it['present'] ? 'checked' : '' ?>>
            <span><?= $h($t('isp_it_' . strtolower($it['code']))) ?></span>
          </label>
          <input name="item_note[<?= $h($it['code']) ?>]" value="<?= $h($it['note']) ?>"
                 placeholder="<?= $h($t('isp_it_ph')) ?>">
        </div>
      <?php endforeach; ?>
    </fieldset>
    <label class="fld"><span><?= $h($t('isp_f_site')) ?></span>
      <input name="site_address" value="<?= $h($r['site_address'] ?? '') ?>" placeholder="<?= $h($t('isp_f_site_ph')) ?>"></label>
    <label class="fld"><span><?= $h($t('isp_f_findings')) ?></span>
      <textarea name="findings" rows="4"><?= $h($r['findings'] ?? '') ?></textarea></label>
    <label class="fld"><span><?= $h($t('isp_f_works')) ?></span>
      <textarea name="works_needed" rows="3"><?= $h($r['works_needed'] ?? '') ?></textarea></label>
    <label class="fld"><span><?= $h($t('f_notes')) ?></span>
      <textarea name="notes" rows="2"><?= $h($r['notes'] ?? '') ?></textarea></label>
    <button class="btn"><?= $h($t('save')) ?></button>
  </form>

  <div class="card" style="margin-bottom:14px">
    <h3 style="margin-top:0"><?= $h($t('isp_photos')) ?></h3>
    <form method="post" enctype="multipart/form-data" style="margin-bottom:10px">
      <input type="hidden" name="do" value="insp_photos">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="file" name="photos[]" accept="image/*" multiple capture="environment">
      <button class="btn tiny"><?= $h($t('ir_photo_add')) ?></button>
    </form>
    <?php if (!$photos): ?><p class="muted small"><?= $h($t('isp_no_photos')) ?></p><?php endif; ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px">
      <?php foreach ($photos as $p): ?>
        <div style="text-align:center">
          <img src="?ispf=<?= (int)$p['id'] ?>" alt="" style="max-width:150px;max-height:120px;border-radius:8px;border:1px solid var(--line)">
          <form method="post" onsubmit="return confirm('<?= $h($t('ir_photo_del_confirm')) ?>')">
            <input type="hidden" name="do" value="insp_photo_del">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
            <button class="btn ghost tiny" style="color:var(--red);margin-top:4px">&times;</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <form method="post" onsubmit="return confirm('<?= $h($t('isp_send_confirm')) ?>')">
    <input type="hidden" name="do" value="insp_send">
    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <button class="btn" <?= $hasChannel ? '' : 'disabled' ?>><?= svg('send') ?> <?= $h($t('isp_send')) ?></button>
    <?php if (!$hasChannel): ?><span class="muted small"><?= $h($t('ir_no_channel')) ?></span><?php endif; ?>
  </form>

<?php else: ?>
  <?php // Sent and beyond: the sheet is what the customer signed, so it is read only. ?>
  <div class="card" style="margin-bottom:14px">
    <h3 style="margin-top:0"><?= $h($t('isp_data')) ?></h3>
    <dl class="cm-kv">
      <dt><?= $h($t('isp_f_when')) ?></dt><dd><?= $h($dtHuman($r['inspected_at'])) ?></dd>
      <dt><?= $h($t('isp_items')) ?></dt>
      <dd><?php foreach ($items as $it): ?>
            <div><?= $it['present'] ? '☑' : '☐' ?> <?= $h($t('isp_it_' . strtolower($it['code']))) ?>
              <?php if ($it['note'] !== ''): ?><span class="muted">— <?= $h($it['note']) ?></span><?php endif; ?></div>
          <?php endforeach; ?></dd>
      <dt><?= $h($t('isp_f_site')) ?></dt><dd><?= $h($r['site_address'] ?: '—') ?></dd>
      <dt><?= $h($t('ir_f_tech')) ?></dt><dd><?= $h($r['technician_name'] ?: '—') ?></dd>
      <dt><?= $h($t('isp_f_findings')) ?></dt><dd style="white-space:pre-wrap"><?= $h($r['findings'] ?: '—') ?></dd>
      <dt><?= $h($t('isp_f_works')) ?></dt><dd style="white-space:pre-wrap"><?= $h($r['works_needed'] ?: '—') ?></dd>
      <?php if (!empty($r['notes'])): ?>
        <dt><?= $h($t('f_notes')) ?></dt><dd style="white-space:pre-wrap"><?= $h($r['notes']) ?></dd>
      <?php endif; ?>
    </dl>
    <?php if (!empty($r['sign_document_id'])): ?>
      <a class="btn ghost tiny" href="?tab=documents&doc=<?= (int)$r['sign_document_id'] ?>">
        <?= svg('documents') ?> <?= $h($t('isp_open_doc')) ?></a>
    <?php endif; ?>
    <?php if ($photos): ?>
      <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px">
        <?php foreach ($photos as $p): ?>
          <img src="?ispf=<?= (int)$p['id'] ?>" alt="" style="max-width:150px;max-height:120px;border-radius:8px;border:1px solid var(--line)">
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php // ---- the technical group's opinion ---- ?>
  <?php if ($status === 'sent'): ?>
    <div class="card"><p class="muted" style="margin:0"><?= $h($t('isp_waiting_sign')) ?></p></div>
  <?php else: ?>
    <div class="card" style="border-left:3px solid <?= $status === 'reviewed' ? 'var(--green)' : 'var(--amber)' ?>">
      <h3 style="margin-top:0">⭐ <?= $h($t('isp_opinion_h')) ?></h3>

      <?php if ($status === 'signed' && empty($r['claimed_by'])): ?>
        <p class="muted small"><?= $h($t('isp_unclaimed')) ?></p>
        <form method="post">
          <input type="hidden" name="do" value="insp_claim">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn"><?= $h($t('isp_claim')) ?></button>
        </form>
      <?php else: ?>
        <?php if (!empty($r['claimed_by'])): ?>
          <p class="muted small" style="margin-top:0">
            <?= $h(sprintf($t('isp_claimed_by'),
                (string)($pdo->query('SELECT COALESCE(NULLIF(full_name, ""), username) FROM users WHERE id = '
                    . (int)$r['claimed_by'])->fetchColumn() ?: '—'))) ?></p>
        <?php endif; ?>

        <?php if ($mayJudge): ?>
          <form method="post">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <label class="fld"><span><?= $h($t('isp_stars')) ?></span>
              <select name="opinion_stars">
                <option value="0">—</option>
                <?php for ($sN = Inspections::STARS_MIN; $sN <= Inspections::STARS_MAX; $sN++): ?>
                  <option value="<?= $sN ?>" <?= (int)($r['opinion_stars'] ?? 0) === $sN ? 'selected' : '' ?>>
                    <?= $h(Inspections::starBar($sN)) ?> — <?= $sN ?>/<?= Inspections::STARS_MAX ?>
                  </option>
                <?php endfor; ?>
              </select></label>
            <label class="fld"><span><?= $h($t('isp_opinion_text')) ?></span>
              <textarea name="opinion_text" rows="5" placeholder="<?= $h($t('isp_opinion_ph')) ?>"><?= $h($r['opinion_text'] ?? '') ?></textarea></label>
            <p class="muted small" style="margin:0 0 10px"><?= $h($t('isp_opinion_hint')) ?></p>
            <button class="btn ghost" name="do" value="insp_opinion"><?= $h($t('save')) ?></button>
            <button class="btn" name="do" value="insp_opinion_send"
                    onclick="return confirm('<?= $h($t('isp_opinion_send_confirm')) ?>')">
              <?= svg('send') ?> <?= $h($t('isp_opinion_send')) ?></button>
          </form>
        <?php else: ?>
          <dl class="cm-kv">
            <dt><?= $h($t('isp_stars')) ?></dt>
            <dd><?= $h(Inspections::starBar((int)($r['opinion_stars'] ?? 0)) ?: '—') ?></dd>
            <dt><?= $h($t('isp_opinion_text')) ?></dt>
            <dd style="white-space:pre-wrap"><?= $h($r['opinion_text'] ?: '—') ?></dd>
            <?php if (!empty($r['opinion_sent_at'])): ?>
              <dt><?= $h($t('isp_opinion_sent_at')) ?></dt>
              <dd><?= $h($dtHuman($r['opinion_sent_at'])) ?> · <?= $h($r['opinion_channel'] ?? '') ?></dd>
            <?php endif; ?>
          </dl>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php else:
    // ======================= the list =======================
    $nq = mb_substr(trim((string)($_GET['nq'] ?? '')), 0, 60);
    $foundCustomers = $nq !== '' ? (Customers::search(['q' => $nq], 1, 12)['rows'] ?? []) : [];
    $foundLeads     = $nq !== '' ? InstallReports::leadContacts($nq) : [];
    $leadCids = array_map(static fn(array $l): int => (int)$l['id'], $foundLeads);
    $foundCustomers = array_values(array_filter($foundCustomers,
        static fn(array $c): bool => !in_array((int)$c['id'], $leadCids, true)));
    $rows = Inspections::all(200, $ownScope);
?>
<h2><?= $h($t('nav_inspections')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('isp_sub')) ?></p>

<details class="drawer" <?= $nq !== '' ? 'open' : '' ?>>
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('installations') ?> <?= $h($t('isp_new')) ?></summary>
  <div class="card" style="margin-top:12px;margin-bottom:14px">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="tab" value="inspections">
      <input type="search" name="nq" value="<?= $h($nq) ?>" placeholder="<?= $h($t('ir_search_ph')) ?>"
             style="width:min(340px,70vw)" autofocus>
      <button class="btn ghost tiny"><?= $h($t('ir_search')) ?></button>
    </form>
    <?php if ($nq !== ''): ?>
      <?php if (!$foundCustomers && !$foundLeads): ?>
        <p class="muted small" style="margin-top:10px"><?= $h($t('ir_search_none')) ?></p>
      <?php else: ?>
        <div class="ir-pick">
          <?php foreach (array_merge(
                    array_map(fn(array $c): array => $c + ['_lead' => true], $foundLeads),
                    array_map(fn(array $c): array => $c + ['_lead' => false], $foundCustomers)) as $c):
                $sub = $c['_lead']
                    ? [(string)($c['company'] ?? ''), (string)($c['phone'] ?? ''), (string)($c['email'] ?? '')]
                    : [(string)($c['city'] ?? ''), (string)($c['phone'] ?? '')]; ?>
            <form method="post">
              <input type="hidden" name="do" value="insp_create">
              <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
              <button class="ir-pick-row">
                <?= avatar($h, $c['name']) ?>
                <span class="ir-pick-who">
                  <b><?= $h($c['name']) ?></b>
                  <span class="muted small ir-pick-sub"><?= $h(trim(implode(' · ', array_filter($sub, 'strlen')))) ?></span>
                </span>
                <span class="btn tiny"><?= $h($t('isp_open_for')) ?></span>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</details>

<?php if (!$rows): ?><div class="empty"><?= $h($t('isp_none')) ?></div><?php else: ?>
<table class="acts-pinned"><thead><tr>
  <th>#</th><th><?= $h($t('th_customer')) ?></th><th><?= $h($t('isp_f_site')) ?></th>
  <th><?= $h($t('th_status')) ?></th><th><?= $h($t('isp_stars')) ?></th><th><?= $h($t('th_created')) ?></th><th></th>
</tr></thead><tbody>
<?php foreach ($rows as $row): $st = (string)$row['status']; ?>
  <tr>
    <td class="muted"><?= (int)$row['id'] ?></td>
    <td><b><?= $h($row['customer_name']) ?></b>
      <?php if (!empty($row['company'])): ?><div class="muted small"><?= $h($row['company']) ?></div><?php endif; ?></td>
    <td class="small"><?= $h($row['site_address'] ?: '—') ?></td>
    <td><span class="pill" style="color:<?= $statusColor[$st] ?? 'var(--muted)' ?>"><?= $h($t('isp_st_' . $st)) ?></span>
      <?php if ($st === 'signed' && empty($row['claimed_by'])): ?>
        <div class="small" style="color:var(--amber)"><?= $h($t('isp_unclaimed_short')) ?></div>
      <?php elseif (!empty($row['claimer_name']) || !empty($row['claimer_username'])): ?>
        <div class="muted small"><?= $h($row['claimer_name'] ?: $row['claimer_username']) ?></div>
      <?php endif; ?></td>
    <td class="small"><?= $h(Inspections::starBar((int)($row['opinion_stars'] ?? 0)) ?: '—') ?></td>
    <td class="small muted"><?= $h(short_time($row['created_at'])) ?></td>
    <td style="text-align:right"><a class="btn ghost tiny" href="?tab=inspections&id=<?= (int)$row['id'] ?>"><?= $h($t('cu_open')) ?></a></td>
  </tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<style>
.ir-pick{display:flex;flex-direction:column;gap:8px;margin-top:10px;}
.ir-pick form{margin:0;}
.ir-pick-row{display:flex;align-items:center;gap:10px;width:100%;text-align:left;cursor:pointer;
  background:var(--surface);color:var(--txt);border:1px solid var(--line);border-radius:var(--radius);padding:10px 12px;}
.ir-pick-row:hover{border-color:var(--accent);}
.ir-pick-who{flex:1;min-width:0;}
.ir-pick-sub{display:block;}
/* The checklist: a tick and its description on one line, stacking on a phone. */
.isp-items{border:1px solid var(--line);border-radius:10px;padding:10px 14px 14px;margin:0 0 12px;}
.isp-items legend{font-size:12px;color:var(--muted);padding:0 6px;}
.isp-item{display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap;}
.isp-tick{display:flex;align-items:center;gap:7px;min-width:150px;margin:0;cursor:pointer;}
.isp-tick input{width:auto;margin:0;}
.isp-item>input[type=text],.isp-item>input:not([type]){flex:1 1 220px;min-width:0;}
</style>
<?php endif; ?>
