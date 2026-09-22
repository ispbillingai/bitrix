<?php
/**
 * Support — the assistance requests from the portal and the public form.
 * Every open request reached all the technicians by WhatsApp/email; this is
 * where exactly one of them takes charge (first press wins — the claim is
 * atomic). Admins additionally see the held-for-payment queue with the
 * forward-now waiver and the cancel button.
 *
 * In scope: $t, $h, $uid, $isAgent, $isTech.
 */

use Glue\Crm\Interventions;
use Glue\Portal\AssistRequests;

$isAdminHere = !$isAgent && !$isTech;
$asRows = AssistRequests::listAll(200);

// The visit outstanding on each request, so the queue shows when someone is
// actually going — one query for the page rather than one per row.
$ivByReq = Interventions::openByRequest(400);
$techList = $isAdminHere ? \Glue\Auth::technicians() : [];

$stColor = ['open' => 'var(--accent)', 'taken' => 'var(--green)',
            'awaiting_payment' => 'var(--amber)', 'cancelled' => 'var(--muted)'];
?>
<h2><?= $h($t('nav_support')) ?></h2>
<p class="muted small" style="margin:-6px 0 14px"><?= $h($t('as_sub')) ?></p>

<?php if (!$asRows): ?><div class="empty"><?= $h($t('as_none')) ?></div><?php else: ?>
<table>
  <thead><tr>
    <th>#</th><th><?= $h($t('th_customer')) ?></th><th><?= $h($t('tk_subject_l')) ?></th>
    <th><?= $h($t('as_priority')) ?></th><th><?= $h($t('th_status')) ?></th>
    <th><?= $h($t('as_taken_by')) ?></th><th><?= $h($t('th_created')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($asRows as $ar): $st = (string)$ar['status']; ?>
    <tr>
      <td class="muted"><?= (int)$ar['id'] ?></td>
      <td><b><?= $h($ar['customer_name']) ?></b>
        <div class="muted small">
          <?= $h($ar['contact_phone'] ?: ($ar['registry_phone'] ?: '')) ?>
          <?php if (!empty($ar['vat_number'])): ?> · <?= $h($t('cu_vat')) ?> <?= $h($ar['vat_number']) ?><?php endif; ?>
        </div></td>
      <td><?= $h($ar['subject']) ?>
        <?php if (!empty($ar['body'])): ?>
          <div class="muted small" style="max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= $h(mb_substr((string)$ar['body'], 0, 160)) ?></div>
        <?php endif; ?></td>
      <td><span class="pill" style="color:<?= $ar['priority'] === 'business_hours' ? 'var(--muted)' : 'var(--green)' ?>">
        <?= $h($t($ar['priority'] === 'business_hours' ? 'as_p_hours' : 'as_p_priority')) ?></span></td>
      <td><span class="pill" style="color:<?= $stColor[$st] ?? 'var(--muted)' ?>"><?= $h($t('as_st_' . $st)) ?></span>
        <?php if ($st === 'awaiting_payment' && !empty($ar['reference'])): ?>
          <div class="small"><a href="?tab=payments&q=<?= urlencode((string)$ar['reference']) ?>"><?= $h($ar['reference']) ?></a></div>
        <?php endif; ?>
        <?php /* The booked visit rides in this cell rather than a column of its
                 own: on a phone the table already scrolls sideways, and another
                 column pushes the buttons further off-screen. */
              $iv = $ivByReq[(int)$ar['id']] ?? null; ?>
        <?php if ($iv && !empty($iv['starts_at'])): ?>
          <div class="small" style="margin-top:4px;color:var(--green)">🔧
            <?= $h(\Glue\Reminder\Templates::when((int)strtotime((string)$iv['starts_at']), $lang, true)) ?></div>
          <?php if (!empty($iv['location'])): ?>
            <div class="muted small"><?= $h($iv['location']) ?></div>
          <?php endif; ?>
        <?php endif; ?></td>
      <td class="small"><?= $h($ar['claimer_name'] ?: ($ar['claimer_username'] ?: '—')) ?>
        <?php if (!empty($ar['claimed_at'])): ?>
          <div class="muted small"><?= $h(short_time($ar['claimed_at'])) ?></div>
        <?php endif; ?></td>
      <td class="small muted"><?= $h(short_time($ar['created_at'])) ?></td>
      <td style="text-align:right;white-space:nowrap">
        <?php if ($st === 'open'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= $h($t('as_claim_confirm')) ?>')">
            <input type="hidden" name="do" value="assist_claim"><input type="hidden" name="id" value="<?= (int)$ar['id'] ?>">
            <button class="btn tiny"><?= $h($t('as_claim')) ?></button>
          </form>
        <?php endif; ?>
        <?php if ($isAdminHere && $st === 'awaiting_payment'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= $h($t('as_forward_confirm')) ?>')">
            <input type="hidden" name="do" value="assist_forward"><input type="hidden" name="id" value="<?= (int)$ar['id'] ?>">
            <button class="btn ghost tiny"><?= $h($t('as_forward')) ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= $h($t('as_cancel_confirm')) ?>')">
            <input type="hidden" name="do" value="assist_cancel"><input type="hidden" name="id" value="<?= (int)$ar['id'] ?>">
            <button class="btn ghost tiny" style="color:var(--red)"><?= $h($t('as_cancel')) ?></button>
          </form>
        <?php endif; ?>
        <?php if (!empty($ar['ticket_id']) && ($isAdminHere || ($isTech && (int)$ar['claimed_by'] === (int)$uid))): ?>
          <a class="btn ghost tiny" href="?tab=tickets&tk=<?= (int)$ar['ticket_id'] ?>"><?= $h($t('cu_open')) ?></a>
        <?php endif; ?>
        <?php /* Booking the visit: the office on any claimed request, a
                 technician only on the one they took charge of. */
              $mayBook = $st === 'taken'
                  && ($isAdminHere || ($isTech && (int)$ar['claimed_by'] === (int)$uid)); ?>
        <?php if ($mayBook): ?>
          <details class="drawer" style="display:inline-block">
            <summary class="btn tiny <?= $iv ? 'ghost' : '' ?>">
              <?= $h($t($iv ? 'iv_reschedule' : 'iv_schedule')) ?></summary>
            <form method="post" class="card" style="margin-top:10px;min-width:300px;text-align:left">
              <input type="hidden" name="do" value="interv_schedule">
              <input type="hidden" name="req_id" value="<?= (int)$ar['id'] ?>">
              <?php if ($isAdminHere): ?>
                <label class="fld"><span><?= $h($t('iv_tech')) ?></span>
                  <select name="tech_id">
                    <?php foreach ($techList as $tu):
                        $sel = (int)$tu['id'] === (int)($iv['agent_id'] ?? $ar['claimed_by']); ?>
                      <option value="<?= (int)$tu['id'] ?>" <?= $sel ? 'selected' : '' ?>>
                        <?= $h($tu['full_name'] ?: $tu['username']) ?></option>
                    <?php endforeach; ?>
                  </select></label>
              <?php endif; ?>
              <label class="fld"><span><?= $h($t('iv_when')) ?></span>
                <input type="datetime-local" name="starts_at" required
                       value="<?= $h(!empty($iv['starts_at']) ? date('Y-m-d\TH:i', strtotime((string)$iv['starts_at'])) : '') ?>"></label>
              <label class="fld"><span><?= $h($t('iv_duration')) ?></span>
                <input type="number" name="duration_min" min="15" max="480" step="15"
                       value="<?= (int)(!empty($iv['starts_at']) && !empty($iv['ends_at'])
                           ? max(15, (strtotime((string)$iv['ends_at']) - strtotime((string)$iv['starts_at'])) / 60)
                           : \Glue\Crm\Interventions::DEFAULT_MIN) ?>"></label>
              <label class="fld"><span><?= $h($t('iv_where')) ?></span>
                <input name="location" value="<?= $h($iv['location'] ?? '') ?>"></label>
              <label class="fld"><span><?= $h($t('iv_notes')) ?></span>
                <textarea name="notes" rows="2"><?= $h($iv['notes'] ?? '') ?></textarea></label>
              <p class="muted small" style="margin:0 0 10px"><?= $h($t('iv_hint')) ?></p>
              <button class="btn tiny"><?= $h($t('iv_save')) ?></button>
            </form>
            <?php if ($iv): ?>
              <div style="margin-top:8px;text-align:left">
                <?php foreach (['done' => 'iv_done', 'cancelled' => 'iv_cancel', 'no_show' => 'iv_noshow'] as $ivSt => $key): ?>
                  <form method="post" class="inline">
                    <input type="hidden" name="do" value="interv_status">
                    <input type="hidden" name="id" value="<?= (int)$iv['id'] ?>">
                    <input type="hidden" name="status" value="<?= $ivSt ?>">
                    <button class="btn tiny ghost"><?= $h($t($key)) ?></button></form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </details>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
