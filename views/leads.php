<?php
/**
 * Leads — kanban board (drag a card to change stage), a create form, and an
 * expandable list where each lead can be assigned to a seller, moved, converted
 * to a deal, annotated, and its timeline read. In scope: $t, $h, $pdo, $agents, $uid.
 */
$stages = \Glue\Crm\Pipelines::stagesForEntity('lead');
$partnerFilter = $filterPartnerId ?? null;
// The search box — every user has it. An agent's results stay inside their own
// leads: $scopeId confines everything on this page to them, the search included.
$leadQ = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$byStage = \Glue\Crm\Leads::byStage($scopeId ?? null, $partnerFilter, $leadQ !== '' ? $leadQ : null);
// Partner list for the filter, by name (Partners::all is newest-first, which is
// no order at all in a dropdown). Admins only — agents never see the bar.
$partnerOpts = empty($isAgent) ? \Glue\Partner\Partners::all() : [];
usort($partnerOpts, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
$partnerRow = null;
foreach ($partnerOpts as $po) { if ((int)$po['id'] === (int)$partnerFilter) { $partnerRow = $po; } }
$sources = \Glue\Crm\Leads::sources();
$zones   = \Glue\Crm\Leads::zones();
$fairs      = \Glue\Crm\Leads::fairs();
$fairCities = \Glue\Crm\Leads::fairCities();
$fairViews  = \Glue\Crm\FormViews::stats('fair');
$fairUrl    = \Glue\Config::appBaseUrl() . '/fair.php';
$srcFilter = mb_strtolower(trim((string)($_GET['src'] ?? '')));
$zoneFilter = trim((string)($_GET['zone'] ?? ''));
// ?lead=<id> — "open this lead", followed from the Quotes tab and anywhere else
// that names one. It shows that lead alone, already expanded: linking to an
// anchor on a collapsed <details> scrolled somewhere and opened nothing, which
// reads as a dead button, and a lead older than the 300 most recent was not on
// the page to scroll to at all.
$openLeadId = (int)($_GET['lead'] ?? 0);
$rows = \Glue\Crm\Leads::all(300, $scopeId ?? null, $srcFilter ?: null, $zoneFilter ?: null, $partnerFilter,
    $openLeadId ?: null, $leadQ !== '' ? $leadQ : null);
// Leads that may be customers the registry already holds — a phone or email
// shared with a card (by VAT they are linked automatically). One query for the
// board and the list together. See LeadCustomers.
$custHints = \Glue\Crm\LeadCustomers::suggestions(array_merge($rows, ...array_values($byStage)));
// monthly per-source report (admin): ?m=YYYY-MM, defaults to the current month
$ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
$ymPrev = date('Y-m', strtotime($ym . '-01 -1 month'));
$ymNext = date('Y-m', strtotime($ym . '-01 +1 month'));
$srcReport = empty($isAgent) ? \Glue\Crm\Leads::sourceReport($ym) : [];
// Focus mode: ?lead=<id> asked for ONE lead, so the page shows that lead and
// nothing else. Narrowing the list underneath was not enough — the board, the
// entry forms and the source report still filled the screen above it, so
// following "open the lead" still landed on what looked like the whole leads
// page, which is exactly how it was reported.
$focus = $openLeadId > 0;
?>
<?php if ($focus): ?>
  <div class="cu-top">
    <a class="btn ghost tiny" href="?tab=leads">&larr; <?= $h($t('lead_back_all')) ?></a>
    <h2 style="margin:0"><?= $h($t('nav_leads')) ?> <span class="muted small">#<?= (int)$openLeadId ?></span></h2>
  </div>
<?php else: ?>
<h2><?= $h($t('nav_leads')) ?></h2>

<?php if (empty($isAgent)): pipeline_filter($h, $t, $agents, 'leads', $filterAgentId ?? null, $partnerOpts, $partnerFilter, ['q' => $leadQ]); ?>
<?php endif; ?>

<?php // Searches the whole leads table, not just the 300 newest the list shows,
      // and narrows the board and the list alike. Filters already on stay on. ?>
<form method="get" style="margin:0 0 14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <input type="hidden" name="tab" value="leads">
  <?php if ($filterAgentId ?? null): ?><input type="hidden" name="agent" value="<?= (int)$filterAgentId ?>"><?php endif; ?>
  <?php if ($partnerFilter): ?><input type="hidden" name="partner" value="<?= (int)$partnerFilter ?>"><?php endif; ?>
  <?php if ($srcFilter !== ''): ?><input type="hidden" name="src" value="<?= $h($srcFilter) ?>"><?php endif; ?>
  <?php if ($zoneFilter !== ''): ?><input type="hidden" name="zone" value="<?= $h($zoneFilter) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= $h($leadQ) ?>" placeholder="<?= $h($t('lead_search_ph')) ?>" style="width:min(420px,100%)">
  <button class="btn ghost tiny"><?= $h($t('search')) ?></button>
  <?php if ($leadQ !== ''): ?>
    <a class="btn ghost tiny" href="?<?= $h(http_build_query(array_filter(['tab' => 'leads', 'agent' => $filterAgentId ?? null,
        'partner' => $partnerFilter, 'src' => $srcFilter, 'zone' => $zoneFilter]))) ?>">&times; <?= $h($t('clear')) ?></a>
  <?php endif; ?>
</form>

<details class="drawer">
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('leads') ?> <?= $h($t('lead_new')) ?></summary>
  <form method="post" class="card" style="margin-top:12px">
    <input type="hidden" name="do" value="lead_create">
    <div class="row">
      <label class="fld"><span><?= $h($t('f_first_name')) ?></span><input name="first_name" required></label>
      <label class="fld"><span><?= $h($t('f_last_name')) ?></span><input name="last_name"></label>
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone" placeholder="+39…"></label>
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_company')) ?></span><input name="company"></label>
      <label class="fld"><span><?= $h($t('f_vat')) ?></span><input name="vat_number" placeholder="<?= $h($t('f_vat_ph')) ?>"></label>
      <label class="fld"><span><?= $h($t('f_source')) ?></span>
        <select name="source" onchange="document.getElementById('src-new').style.display=this.value===''?'':'none'">
          <?php foreach ($sources as $s): ?>
            <option value="<?= $h($s) ?>"<?= $s === 'manual' ? ' selected' : '' ?>><?= $h($s) ?></option>
          <?php endforeach; ?>
          <option value=""><?= $h($t('src_new_opt')) ?></option>
        </select>
        <input name="source_new" id="src-new" placeholder="<?= $h($t('src_new_ph')) ?>" style="display:none;margin-top:6px"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_zone')) ?></span>
        <input name="zone" list="zone-list" placeholder="<?= $h($t('f_zone_ph')) ?>"></label>
      <label class="fld"><span><?= $h($t('f_lang')) ?></span>
        <select name="lang"><option value="">—</option><option value="it">IT</option><option value="en">EN</option></select></label>
      <?php if ($partnerOpts): ?>
      <label class="fld"><span><?= $h($t('f_partner')) ?></span>
        <?php partner_select($h, $t, $partnerOpts); ?>
        <span class="muted small" style="margin-top:4px"><?= $h($t('f_partner_hint')) ?></span></label>
      <?php endif; ?>
    </div>
    <label class="fld"><span><?= $h($t('f_message')) ?></span><textarea name="comments" rows="2"></textarea></label>
    <button class="btn"><?= $h($t('save')) ?></button>
  </form>
</details>
<details class="drawer">
  <summary class="btn ghost" style="margin-bottom:14px"><?= svg('leads') ?> <?= $h($t('fair_new')) ?></summary>
  <div class="card" style="margin-top:12px">
    <div class="muted small" style="margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--line)">
      <?= $h($t('fair_public_link')) ?>:
      <a href="<?= $h($fairUrl) ?>" target="_blank"><?= $h($fairUrl) ?></a>
      · <strong><?= (int)$fairViews['total'] ?></strong> <?= $h($t('fair_views')) ?>
      (<?= (int)$fairViews['month'] ?> <?= $h($t('fair_views_month')) ?>)
      <div style="margin-top:4px"><?= $h($t('fair_link_hint')) ?></div>
    </div>
    <form method="post">
      <input type="hidden" name="do" value="lead_create">
      <input type="hidden" name="source" value="fiera">
      <div class="row">
        <label class="fld"><span><?= $h($t('f_fair')) ?></span>
          <input name="fair_name" list="fair-list" placeholder="<?= $h($t('f_fair_ph')) ?>" required></label>
        <label class="fld"><span><?= $h($t('f_fair_city')) ?></span>
          <input name="fair_city" list="faircity-list" placeholder="<?= $h($t('f_fair_city_ph')) ?>"></label>
      </div>
      <div class="row">
        <label class="fld"><span><?= $h($t('f_first_name')) ?></span><input name="first_name" required></label>
        <label class="fld"><span><?= $h($t('f_last_name')) ?></span><input name="last_name"></label>
        <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone" placeholder="+39…"></label>
        <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email"></label>
      </div>
      <div class="row">
        <label class="fld"><span><?= $h($t('f_company')) ?></span><input name="company"></label>
        <label class="fld"><span><?= $h($t('f_vat')) ?></span><input name="vat_number" placeholder="<?= $h($t('f_vat_ph')) ?>"></label>
        <label class="fld"><span><?= $h($t('f_zone')) ?></span><input name="zone" list="zone-list" placeholder="<?= $h($t('f_zone_ph')) ?>"></label>
        <label class="fld"><span><?= $h($t('f_lang')) ?></span>
          <select name="lang"><option value="">—</option><option value="it">IT</option><option value="en">EN</option></select></label>
      </div>
      <?php if ($partnerOpts): ?>
      <div class="row">
        <label class="fld"><span><?= $h($t('f_partner')) ?></span>
          <?php partner_select($h, $t, $partnerOpts); ?>
          <span class="muted small" style="margin-top:4px"><?= $h($t('f_partner_hint')) ?></span></label>
      </div>
      <?php endif; ?>
      <label class="fld"><span><?= $h($t('f_message')) ?></span><textarea name="comments" rows="2"></textarea></label>
      <button class="btn"><?= $h($t('save')) ?></button>
    </form>
  </div>
</details>

<?php endif; /* !$focus — the entry forms */ ?>

<?php // The datalists stay in both modes: the lead EDIT form inside the drawer
      // below suggests from them too. ?>
<datalist id="zone-list"><?php foreach ($zones as $z): ?><option value="<?= $h($z) ?>"><?php endforeach; ?></datalist>
<datalist id="src-list"><?php foreach ($sources as $s): ?><option value="<?= $h($s) ?>"><?php endforeach; ?></datalist>
<datalist id="fair-list"><?php foreach ($fairs as $f): ?><option value="<?= $h($f) ?>"><?php endforeach; ?></datalist>
<datalist id="faircity-list"><?php foreach ($fairCities as $fc): ?><option value="<?= $h($fc) ?>"><?php endforeach; ?></datalist>

<?php if (!$focus): ?>
<div class="kanban" id="kb-lead">
  <?php foreach ($stages as $s): $cards = $byStage[$s['code']] ?? []; ?>
    <div class="kcol">
      <div class="kcol-h">
        <span><span class="dotc" style="background:<?= $h($s['color'] ?: '#5b6cff') ?>"></span><?= $h(stage_label($t, $s['code'], $s['name'])) ?></span>
        <span class="cnt"><?= count($cards) ?></span>
      </div>
      <div class="kbody" data-stage="<?= $h($s['code']) ?>">
        <?php foreach ($cards as $c): $nm = $c['customer_name'] ?: ('#' . $c['id']); $ag = $c['agent_name'] ?: $c['agent_username'];
              $cby = $c['creator_name'] ?: $c['creator_username']; ?>
          <div class="kcard" draggable="true" data-id="<?= $h($c['id']) ?>">
            <b><?= $h($nm) ?></b>
            <?php if (!empty($c['company'])): ?>
              <div class="muted small" style="margin-top:2px"><?= $h($c['company']) ?></div>
            <?php endif; ?>
            <?php if (!empty($c['ct_is_customer'])): ?>
              <div style="margin-top:4px"><span class="pill" style="color:var(--green)">✓ <?= $h($t('lead_is_customer')) ?><?= !empty($c['ct_code']) ? ' · ' . $h($c['ct_code']) : '' ?></span></div>
            <?php elseif (!empty($custHints[(int)$c['id']])): ?>
              <div style="margin-top:4px"><span class="pill" style="color:var(--amber)"><?= $h($t('lead_maybe_customer')) ?></span></div>
            <?php endif; ?>
            <div class="meta">
              <?php // The card IS the lead right after saving it: a seller who cannot
                    // see the number has to open the row below to call anybody.
                    if (($tel = phone_link($h, $c['customer_phone'])) !== ''): ?>
                <span><?= $tel ?></span>
              <?php endif; ?>
              <?php if (!empty($c['customer_email'])): ?><span><?= $h($c['customer_email']) ?></span><?php endif; ?>
              <span><?= $h($c['source']) ?></span>
              <span title="<?= $h(short_time($c['received_at'])) ?>"><?= $h(time_ago($c['received_at'], $t)) ?></span>
              <?php if ($ag): ?><span><?= avatar($h, $ag) ?> <?= $h($ag) ?></span><?php endif; ?>
              <?php if ($cby): ?>
                <span class="byhand" title="<?= $h($t('entered_by_title')) ?>">
                  <?= svg('pen') ?><?= $h($t('entered_by')) ?> <?= $h($cby) ?></span>
              <?php endif; ?>
              <?php if (!empty($c['partner_name'])): ?>
                <span class="bypartner" title="<?= $h($t('from_partner_title')) ?>">
                  <?= svg('partners') ?><?= $h($c['partner_name']) ?></span>
              <?php endif; ?>
            </div>
            <?php $cmsg = trim((string)($c['comments'] ?? '')); if ($cmsg !== ''): ?>
              <div class="muted small note-clip l4" style="margin-top:7px" title="<?= $h($cmsg) ?>">“<?= $h($cmsg) ?>”</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; /* !$focus — the board */ ?>

<?php // Hidden while searching: it is a month's report, not a match, and it stood
      // between the search box and the results. ?>
<?php if (empty($isAgent) && !$focus && $leadQ === ''): ?>
<div class="panel" style="margin-top:22px">
  <div class="panel-h">
    <h3><?= svg('leads') ?><?= $h($t('src_report')) ?></h3>
    <span style="display:flex;align-items:center;gap:8px">
      <a class="btn ghost tiny" href="?tab=leads&m=<?= $h($ymPrev) ?>">‹</a>
      <b><?= $h($ym) ?></b>
      <a class="btn ghost tiny" href="?tab=leads&m=<?= $h($ymNext) ?>">›</a>
      <a class="btn ghost tiny" href="?export=leads&m=<?= $h($ym) ?>" title="<?= $h($t('exp_all_title')) ?>"><?= $h($t('exp_excel')) ?></a>
    </span>
  </div>
  <div class="muted small" style="margin:-4px 0 12px"><?= $h($t('src_report_sub')) ?></div>
  <?php if (!$srcReport): ?><div class="empty"><?= $h($t('none_yet')) ?></div>
  <?php else: $tot = ['received' => 0, 'converted' => 0, 'junk' => 0, 'still_open' => 0]; ?>
  <div style="overflow-x:auto"><table><thead>
    <tr><th><?= $h($t('f_source')) ?></th>
        <th><?= $h($t('src_received')) ?></th><th><?= $h($t('src_converted')) ?></th>
        <th><?= $h($t('src_junk')) ?></th><th><?= $h($t('src_open')) ?></th><th><?= $h($t('ov_conv')) ?></th><th></th></tr>
  </thead><tbody>
    <?php foreach ($srcReport as $sr): foreach ($tot as $k => $v) { $tot[$k] += (int)$sr[$k]; }
        $pct = (int)$sr['received'] > 0 ? round(100 * (int)$sr['converted'] / (int)$sr['received']) : 0; ?>
      <tr><td><a href="?tab=leads&src=<?= $h(urlencode($sr['source'])) ?>"><?= $h($sr['source']) ?></a></td>
          <td><?= (int)$sr['received'] ?></td><td><?= (int)$sr['converted'] ?></td>
          <td><?= (int)$sr['junk'] ?></td><td><?= (int)$sr['still_open'] ?></td><td><?= $pct ?>%</td>
          <td><a class="btn ghost tiny" href="?export=leads&m=<?= $h($ym) ?>&src=<?= $h(urlencode($sr['source'])) ?>"><?= $h($t('exp_excel')) ?></a></td></tr>
    <?php endforeach; $tpct = $tot['received'] > 0 ? round(100 * $tot['converted'] / $tot['received']) : 0; ?>
    <tr style="font-weight:600"><td><?= $h($t('src_total')) ?></td>
        <td><?= $tot['received'] ?></td><td><?= $tot['converted'] ?></td>
        <td><?= $tot['junk'] ?></td><td><?= $tot['still_open'] ?></td><td><?= $tpct ?>%</td><td></td></tr>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$focus): ?>
<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:22px">
  <h3 style="margin:0"><?= $h($t('all')) ?> · <?= count($rows) ?></h3>
  <?php if ($leadQ !== ''): ?>
    <span class="pill"><?= $h(str_replace('{q}', $leadQ, $t('lead_search_pill'))) ?></span>
  <?php endif; ?>
  <?php if ($srcFilter !== ''): ?>
    <span class="pill"><?= $h($t('f_source')) ?>: <?= $h($srcFilter) ?></span>
  <?php endif; ?>
  <?php if ($partnerRow): ?>
    <span class="bypartner" title="<?= $h($t('from_partner_title')) ?>">
      <?= svg('partners') ?><?= $h($t('from_partner')) ?> <?= $h($partnerRow['name']) ?></span>
    <a class="btn ghost tiny" href="?export=leads&m=<?= $h($ym) ?>&partner=<?= (int)$partnerFilter ?>"><?= $h($t('exp_excel')) ?></a>
  <?php endif; ?>
  <?php if ($zones): ?>
    <form method="get" class="inline" style="margin:0">
      <input type="hidden" name="tab" value="leads">
      <?php if ($srcFilter !== ''): ?><input type="hidden" name="src" value="<?= $h($srcFilter) ?>"><?php endif; ?>
      <?php if ($partnerFilter): ?><input type="hidden" name="partner" value="<?= (int)$partnerFilter ?>"><?php endif; ?>
      <?php if ($filterAgentId ?? null): ?><input type="hidden" name="agent" value="<?= (int)$filterAgentId ?>"><?php endif; ?>
      <?php if ($leadQ !== ''): ?><input type="hidden" name="q" value="<?= $h($leadQ) ?>"><?php endif; ?>
      <select name="zone" onchange="this.form.submit()">
        <option value=""><?= $h($t('zone_all')) ?></option>
        <?php foreach ($zones as $z): ?>
          <option value="<?= $h($z) ?>"<?= $z === $zoneFilter ? ' selected' : '' ?>><?= $h($z) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>
  <?php if ($srcFilter !== '' || $zoneFilter !== '' || $partnerFilter): ?>
    <a class="btn ghost tiny" href="?tab=leads"><?= $h($t('clear')) ?></a>
  <?php endif; ?>
</div>
<?php endif; /* !$focus — the list header and its filters */ ?>
<?php if (!$rows): ?>
  <div class="empty"><?= $h($openLeadId ? $t('lead_not_here')
      : ($leadQ !== '' ? str_replace('{q}', $leadQ, $t('lead_search_none')) : $t('none_yet'))) ?></div>
<?php endif; ?>
<?php foreach ($rows as $r):
    $ag = $r['agent_name'] ?: $r['agent_username'];
    $msg = trim((string)($r['comments'] ?? ''));
    $timeline = \Glue\Crm\Activities::forEntity('lead', (int)$r['id'], 20);
    $quotes   = \Glue\Crm\QuoteRequests::forLead((int)$r['id']); ?>
  <details class="drawer card" id="lead-<?= (int)$r['id'] ?>"<?= $openLeadId === (int)$r['id'] ? ' open' : '' ?> style="padding:0;margin-bottom:8px">
    <summary class="dw-sum">
      <?= avatar($h, $r['customer_name']) ?>
      <span class="dw-info"><b><?= $h($r['customer_name'] ?: ('#' . $r['id'])) ?></b>
        <span class="muted small"> · <?= phone_link($h, $r['customer_phone']) ?> <?= $h($r['customer_email']) ?><?= !empty($r['company']) ? ' · ' . $h($r['company']) : '' ?><?= !empty($r['vat_number']) ? ' · ' . $h($t('f_vat')) . ' ' . $h($r['vat_number']) : '' ?><?= !empty($r['zone']) ? ' · ' . $h($t('f_zone')) . ' ' . $h($r['zone']) : '' ?><?= !empty($r['fair_name']) ? ' · ' . $h($t('f_fair')) . ' ' . $h($r['fair_name']) . (!empty($r['fair_city']) ? ' (' . $h($r['fair_city']) . ')' : '') : '' ?></span>
        <?php if ($msg !== ''): ?><span class="muted small note-clip l2" style="margin-top:2px">“<?= $h($msg) ?>”</span><?php endif; ?></span>
      <span class="pill"><?= $h(stage_label($t, $r['stage_code'], \Glue\Crm\Pipelines::label('lead', $r['stage_code']))) ?></span>
      <?= pill($h, $r['status'], $t) ?>
      <?php if (!empty($r['ct_is_customer'])): ?>
        <span class="pill" style="color:var(--green)">✓ <?= $h($t('lead_is_customer')) ?></span>
      <?php elseif (!empty($custHints[(int)$r['id']])): ?>
        <span class="pill" style="color:var(--amber)"><?= $h($t('lead_maybe_customer')) ?></span>
      <?php endif; ?>
      <span class="muted small"><?= $ag ? $h($ag) : $h($t('unassigned')) ?></span>
      <?php $cby = $r['creator_name'] ?: $r['creator_username']; if ($cby): ?>
        <span class="byhand" title="<?= $h($t('entered_by_title')) ?>">
          <?= svg('pen') ?><?= $h($t('entered_by')) ?> <?= $h($cby) ?></span>
      <?php endif; ?>
      <?php if (!empty($r['partner_name'])): ?>
        <span class="bypartner" title="<?= $h($t('from_partner_title')) ?>">
          <?= svg('partners') ?><?= $h($t('from_partner')) ?> <?= $h($r['partner_name']) ?></span>
      <?php endif; ?>
      <?php $acc = \Glue\Portal\Account::accessStats((int)$r['contact_id']); if ($acc['count'] > 0): ?>
        <span class="pill" title="<?= $h($t('portal_access_title')) ?>" style="background:var(--accent-soft,rgba(91,108,255,.14));color:var(--accent,#5b6cff)">
          <?= svg('users') ?> <?= (int)$acc['count'] ?></span>
      <?php endif; ?>
    </summary>
    <div style="padding:4px 18px 18px;border-top:1px solid var(--line)">
      <?php if ($msg !== ''): ?>
        <div style="background:var(--surface2);border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin:8px 0 4px">
          <div class="muted small" style="margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em;font-weight:600"><?= $h($t('f_message')) ?></div>
          <div style="white-space:pre-wrap;line-height:1.55"><?= nl2br($h($msg)) ?></div>
        </div>
      <?php endif; ?>
      <?php if (!empty($r['source_url'])): ?>
        <div class="muted small" style="margin:8px 0 4px">
          <?= $h($t('f_source_url')) ?>:
          <a href="<?= $h($r['source_url']) ?>" target="_blank" rel="noopener nofollow"><?= $h($r['source_url']) ?></a>
        </div>
      <?php endif; ?>
      <?php // A request from a customer the registry already holds: a new sale
            // (another product), not a stranger — and not a duplicate either. ?>
      <?php if (!empty($r['ct_is_customer'])): ?>
        <div style="background:var(--surface2);border:1px solid var(--line);border-left:3px solid var(--green);border-radius:10px;padding:12px 14px;margin:8px 0 4px">
          <div style="display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center">
            <b style="color:var(--green)">✓ <?= $h($t('lead_cust_h')) ?></b>
            <span><b><?= $h($r['ct_name']) ?></b><?= !empty($r['ct_code']) ? ' · ' . $h($t('cu_code')) . ' ' . $h($r['ct_code']) : '' ?><?= !empty($r['ct_vat']) ? ' · ' . $h($t('f_vat')) . ' ' . $h($r['ct_vat']) : '' ?></span>
            <?php if (empty($isAgent)): ?>
              <a class="btn ghost tiny" href="?tab=customers&amp;id=<?= (int)$r['contact_id'] ?>"><?= $h($t('qt_open_customer')) ?></a>
            <?php endif; ?>
          </div>
          <div class="muted small" style="margin-top:4px"><?= $h($t('lead_cust_hint')) ?></div>
        </div>
      <?php elseif (!empty($custHints[(int)$r['id']])): ?>
        <div style="background:var(--surface2);border:1px solid var(--line);border-left:3px solid var(--amber);border-radius:10px;padding:12px 14px;margin:8px 0 4px">
          <div><b style="color:var(--amber)"><?= $h($t('lead_maybe_h')) ?></b> — <?= $h($t('lead_maybe_sub')) ?></div>
          <?php foreach ($custHints[(int)$r['id']] as $hc): ?>
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:6px 10px;margin-top:8px">
              <span><b><?= $h($hc['name']) ?></b><?= $hc['code'] !== null ? ' · ' . $h($t('cu_code')) . ' ' . $h($hc['code']) : '' ?><?= $hc['vat'] !== null ? ' · ' . $h($t('f_vat')) . ' ' . $h($hc['vat']) : '' ?>
                <span class="muted small"> · <?= $h(implode(', ', array_map(fn($w) => $t('lead_match_' . $w), $hc['how']))) ?></span></span>
              <?php if (empty($isAgent)): ?>
                <span style="display:flex;gap:6px;flex-wrap:wrap">
                  <a class="btn ghost tiny" href="?tab=customers&amp;id=<?= (int)$hc['id'] ?>"><?= $h($t('cu_open')) ?></a>
                  <form method="post" style="margin:0" onsubmit="return confirm(<?= $h(json_encode($t('lead_link_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
                    <input type="hidden" name="do" value="lead_link_customer">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="customer_id" value="<?= (int)$hc['id'] ?>">
                    <button class="btn tiny"><?= $h($t('lead_link_btn')) ?></button>
                  </form>
                </span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (!empty($isAgent)): ?><div class="muted small" style="margin-top:6px"><?= $h($t('lead_link_ask')) ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="cols c-1-1" style="margin-bottom:0">
        <div>
          <h3><?= $h($t('actions')) ?></h3>
          <details class="drawer" style="margin-bottom:12px">
            <summary class="btn tiny ghost"><?= $h($t('lead_edit')) ?></summary>
            <form method="post" class="card" style="margin-top:10px">
              <input type="hidden" name="do" value="lead_edit">
              <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
              <?php [$edFirst, $edLast] = \Glue\Crm\Contacts::splitName((string)$r['customer_name']); ?>
              <div class="row">
                <label class="fld"><span><?= $h($t('f_first_name')) ?></span><input name="first_name" value="<?= $h($edFirst) ?>" required></label>
                <label class="fld"><span><?= $h($t('f_last_name')) ?></span><input name="last_name" value="<?= $h($edLast) ?>"></label>
                <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone" value="<?= $h($r['customer_phone']) ?>" placeholder="+39…"></label>
              </div>
              <div class="row">
                <label class="fld"><span><?= $h($t('f_email')) ?></span><input name="email" value="<?= $h($r['customer_email']) ?>"></label>
                <label class="fld"><span><?= $h($t('f_company')) ?></span><input name="company" value="<?= $h($r['company'] ?? '') ?>"></label>
                <label class="fld"><span><?= $h($t('f_vat')) ?></span><input name="vat_number" value="<?= $h($r['vat_number'] ?? '') ?>" placeholder="<?= $h($t('f_vat_ph')) ?>"></label>
              </div>
              <div class="row">
                <label class="fld"><span><?= $h($t('f_source')) ?></span><input name="source" list="src-list" value="<?= $h($r['source']) ?>"></label>
                <label class="fld"><span><?= $h($t('f_zone')) ?></span><input name="zone" list="zone-list" value="<?= $h($r['zone'] ?? '') ?>" placeholder="<?= $h($t('f_zone_ph')) ?>"></label>
                <label class="fld"><span><?= $h($t('f_lang')) ?></span>
                  <select name="lang">
                    <option value="it"<?= ($r['lang'] ?? '') === 'it' ? ' selected' : '' ?>>IT</option>
                    <option value="en"<?= ($r['lang'] ?? '') === 'en' ? ' selected' : '' ?>>EN</option>
                  </select></label>
              </div>
              <div class="row">
                <label class="fld"><span><?= $h($t('f_fair')) ?></span><input name="fair_name" list="fair-list" value="<?= $h($r['fair_name'] ?? '') ?>"></label>
                <label class="fld"><span><?= $h($t('f_fair_city')) ?></span><input name="fair_city" list="faircity-list" value="<?= $h($r['fair_city'] ?? '') ?>"></label>
                <?php if ($partnerOpts): ?>
                <label class="fld"><span><?= $h($t('f_partner')) ?></span>
                  <?php partner_select($h, $t, $partnerOpts, $r['referred_by_partner_id'] ?? null); ?></label>
                <?php endif; ?>
              </div>
              <label class="fld"><span><?= $h($t('f_message')) ?></span><textarea name="comments" rows="2"><?= $h($r['comments'] ?? '') ?></textarea></label>
              <button class="btn tiny"><?= $h($t('save')) ?></button>
            </form>
          </details>
          <?php if (empty($isAgent)): ?>
          <form method="post" class="inline"><input type="hidden" name="do" value="lead_assign">
            <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <?php agent_select($h, $agents, 'agent_id', $r['assigned_to'], $t('assign_seller')); ?>
            <button class="btn tiny"><?= $h($t('assign')) ?></button></form>
          <?php endif; ?>
          <form method="post" class="inline"><input type="hidden" name="do" value="lead_move">
            <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <select name="stage">
              <?php foreach ($stages as $s): ?>
                <option value="<?= $h($s['code']) ?>"<?= $s['code'] === $r['stage_code'] ? ' selected' : '' ?>><?= $h(stage_label($t, $s['code'], $s['name'])) ?></option>
              <?php endforeach; ?>
            </select>
            <input name="note" placeholder="<?= $h($t('move_note_ph')) ?>" style="max-width:220px">
            <button class="btn tiny ghost"><?= $h($t('move')) ?></button></form>
          <?php if ($r['status'] === 'open'): ?>
          <form method="post" class="inline" onsubmit="return confirm('<?= $h($t('confirm_convert')) ?>')">
            <input type="hidden" name="do" value="lead_convert"><input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <button class="btn tiny"><?= svg('deals') ?> <?= $h($t('convert')) ?></button></form>
          <?php endif; ?>
          <?php if (empty($isAgent)): ?>
          <form method="post" class="inline" onsubmit="return confirm('<?= $h($t('confirm_lead_delete')) ?>')">
            <input type="hidden" name="do" value="lead_delete"><input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <button class="btn tiny ghost" style="color:var(--red)"><?= $h($t('delete')) ?></button></form>
          <?php endif; ?>
          <form method="post" style="margin-top:12px"><input type="hidden" name="do" value="lead_note">
            <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
            <label class="fld"><span><?= $h($t('add_note')) ?></span>
              <textarea name="body" rows="2" required></textarea></label>
            <button class="btn tiny ghost"><?= $h($t('save')) ?></button></form>

          <?php // The installer opens the report straight from the lead: it is filed
                // on the lead's own contact, so the name, phone and email the seller
                // entered come with it. The office, and agents who also install. ?>
          <?php if ((empty($isAgent) || !empty($agentInstalls)) && !empty($r['contact_id'])): ?>
            <form method="post" style="margin:14px 0 4px">
              <input type="hidden" name="do" value="install_create">
              <input type="hidden" name="contact_id" value="<?= (int)$r['contact_id'] ?>">
              <button class="btn tiny"><?= svg('installations') ?> <?= $h($t('ir_open_from_lead')) ?></button>
            </form>
          <?php endif; ?>

          <?php // Book the visit from here, rather than retyping the customer on
                // the Appointments tab. Saving it confirms the appointment: the
                // customer and the seller are both told at once, and both are
                // reminded again as it approaches. ?>
          <h3 style="margin-top:18px"><?= $h($t('ap_h')) ?></h3>
          <details class="drawer" style="margin-bottom:10px">
            <summary class="btn tiny"><?= svg('appointments') ?> <?= $h($t('ap_book')) ?></summary>
            <form method="post" class="card" style="margin-top:10px">
              <input type="hidden" name="do" value="lead_appointment">
              <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
              <div class="row">
                <label class="fld"><span><?= $h($t('ap_when')) ?> *</span>
                  <input type="datetime-local" name="starts_at" required></label>
                <label class="fld"><span><?= $h($t('ap_where')) ?></span>
                  <input name="location" placeholder="<?= $h($t('ap_where_ph')) ?>"></label>
              </div>
              <div class="row">
                <label class="fld"><span><?= $h($t('ap_title')) ?></span>
                  <input name="title" placeholder="<?= $h($t('ap_title_ph')) ?>"></label>
                <?php if (empty($isAgent)): ?>
                  <label class="fld"><span><?= $h($t('th_agent')) ?></span>
                    <?php agent_select($h, $agents, 'agent_id', $r['assigned_to'], $t('assign_seller')); ?></label>
                <?php endif; ?>
              </div>
              <label class="fld"><span><?= $h($t('f_notes')) ?></span>
                <textarea name="notes" rows="2"></textarea></label>
              <p class="muted small" style="margin:-8px 0 12px"><?= $h($t('ap_hint')) ?></p>
              <button class="btn tiny"><?= svg('appointments') ?> <?= $h($t('ap_book')) ?></button>
            </form>
          </details>
          <?php $leadAppts = \Glue\Crm\Appointments::forLead((int)$r['id']); ?>
          <?php if (!$leadAppts): ?>
            <div class="muted small"><?= $h($t('ap_none')) ?></div>
          <?php else: foreach ($leadAppts as $ap): ?>
            <div class="lb">
              <span class="nm" style="min-width:0">
                <b><?= $h($ap['starts_at'] ? date('d/m/Y H:i', strtotime((string)$ap['starts_at'])) : $t('ap_no_time')) ?></b>
                <?php if (!empty($ap['location'])): ?><span class="muted small"> · <?= $h($ap['location']) ?></span><?php endif; ?>
                <div class="muted small"><?= $h($ap['agent_name'] ?: ($ap['agent_username'] ?: '')) ?></div>
              </span>
              <span class="sc"><?= pill($h, (string)$ap['status'], $t) ?></span>
            </div>
          <?php endforeach; endif; ?>

          <?php // Ask the back office to price this customer. Everything they need
                // — company, legal details, contacts — is already on the lead, so
                // the only thing asked for here is what the quote must contain. ?>
          <h3 style="margin-top:18px"><?= $h($t('qt_h')) ?></h3>
          <details class="drawer" style="margin-bottom:10px">
            <summary class="btn tiny"><?= svg('quotes') ?> <?= $h($t('qt_request')) ?></summary>
            <form method="post" class="card" style="margin-top:10px">
              <input type="hidden" name="do" value="lead_quote">
              <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
              <label class="fld"><span><?= $h($t('qt_notes')) ?> *</span>
                <textarea name="notes" rows="3" required placeholder="<?= $h($t('qt_notes_ph')) ?>"></textarea></label>
              <p class="muted small" style="margin:-8px 0 12px"><?= $h($t('qt_request_hint')) ?></p>
              <button class="btn tiny"><?= svg('send') ?> <?= $h($t('qt_send_request')) ?></button>
            </form>
          </details>
          <?php if (!$quotes): ?>
            <div class="muted small"><?= $h($t('qt_none_lead')) ?></div>
          <?php else: foreach ($quotes as $q): $qst = (string)$q['status']; ?>
            <div class="lb" style="align-items:flex-start;gap:10px">
              <span class="nm" style="min-width:0">
                <b>#<?= (int)$q['id'] ?></b>
                <span class="muted small"> · <?= $h(short_time($q['created_at'])) ?>
                  <?= $h($q['requester_name'] ?: ($q['requester_username'] ?: '')) ?></span>
                <div class="muted small note-clip l2"><?= $h($q['notes']) ?></div>
                <?php if (!empty($q['document_id'])): ?>
                  <div class="small" style="margin-top:3px">
                    <a href="?sdl=<?= (int)$q['document_id'] ?>&k=orig"><?= $h($q['doc_title'] ?: $t('qt_quote')) ?></a>
                    <?php if (!empty($q['signed_at'])): ?> · ✅ <?= $h(short_time($q['signed_at'])) ?><?php endif; ?>
                  </div>
                <?php endif; ?>
              </span>
              <span class="sc"><span class="pill"><?= $h($t('qt_st_' . $qst)) ?></span></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <div>
          <?php $acc = \Glue\Portal\Account::accessStats((int)$r['contact_id']); ?>
          <h3><?= $h($t('portal_access_h')) ?></h3>
          <div class="muted small" style="margin:-4px 0 14px">
            <?php if ($acc['count'] > 0): ?>
              <?= $h($t('portal_access_count')) ?>: <strong><?= (int)$acc['count'] ?></strong>
              · <?= $h($t('portal_access_last')) ?>: <?= $h(short_time($acc['last'])) ?>
            <?php else: ?>
              <?= $h($t('portal_access_never')) ?>
            <?php endif; ?>
          </div>
          <h3><?= $h($t('timeline')) ?></h3>
          <div class="tl">
            <?php if (!$timeline): ?><div class="empty"><?= $h($t('none_yet')) ?></div><?php endif; ?>
            <?php foreach ($timeline as $a): ?>
              <div class="tl-row"><div class="tl-ic"><?= svg($a['type'] === 'note' ? 'messages' : ($a['type'] === 'stage' ? 'pipeline' : 'events')) ?></div>
                <div class="tl-main"><?= $h($a['body']) ?>
                  <div class="meta"><?= $h($a['full_name'] ?: $a['username'] ?: $t('system')) ?> · <?= $h(short_time($a['created_at'])) ?></div></div></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </details>
<?php endforeach; ?>

<script>
(function(){
  const STAGES = <?= json_encode(array_map(
      fn($s) => ['code' => $s['code'], 'name' => stage_label($t, $s['code'], $s['name']), 'color' => $s['color'] ?: '#5b6cff'],
      $stages
  ), JSON_UNESCAPED_UNICODE) ?>;
  const NOTE_PROMPT = <?= json_encode($t('move_note_prompt'), JSON_UNESCAPED_UNICODE) ?>;
  const MOVE_TITLE  = <?= json_encode($t('move_to'), JSON_UNESCAPED_UNICODE) ?>;
  const CANCEL_TXT  = <?= json_encode($t('cancel'), JSON_UNESCAPED_UNICODE) ?>;

  function doMove(id, stage){
    const note=(prompt(NOTE_PROMPT)||'').trim();
    const fd=new FormData();fd.append('do','lead_move');fd.append('ajax','1');fd.append('id',id);fd.append('stage',stage);
    if(note)fd.append('note',note);
    fetch('?',{method:'POST',body:fd}).then(r=>r.json()).then(()=>location.reload());
  }

  // Tap-to-move: HTML5 drag events never fire on iOS/Android touch screens, so
  // tapping a card opens a stage menu instead. Desktop keeps drag & drop too.
  function openMoveMenu(id, current){
    const ov=document.createElement('div');
    ov.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:flex;align-items:flex-end;justify-content:center';
    const box=document.createElement('div');
    box.style.cssText='background:var(--surface,#161c28);border:1px solid var(--line,#28303f);border-radius:14px 14px 0 0;padding:16px;width:100%;max-width:480px;max-height:75vh;overflow-y:auto';
    const title=document.createElement('div');
    title.style.cssText='font-weight:700;margin-bottom:10px';
    title.textContent=MOVE_TITLE;
    box.appendChild(title);
    STAGES.forEach(s=>{
      const b=document.createElement('button');
      b.type='button';
      b.style.cssText='display:flex;align-items:center;gap:10px;width:100%;padding:12px;margin-bottom:8px;border:1px solid var(--line,#28303f);border-radius:10px;background:var(--surface2,#1c2533);color:inherit;font:inherit;cursor:pointer'+(s.code===current?';opacity:.45':'');
      b.innerHTML='<span style="flex:0 0 auto;width:9px;height:9px;border-radius:50%;background:'+s.color+'"></span>';
      b.appendChild(document.createTextNode(s.name));
      b.addEventListener('click',()=>{document.body.removeChild(ov);if(s.code!==current)doMove(id,s.code);});
      box.appendChild(b);
    });
    const c=document.createElement('button');
    c.type='button';c.textContent=CANCEL_TXT;
    c.style.cssText='width:100%;padding:12px;border:none;border-radius:10px;background:transparent;color:var(--muted,#8b95a7);font:inherit;cursor:pointer';
    c.addEventListener('click',()=>document.body.removeChild(ov));
    box.appendChild(c);
    ov.addEventListener('click',e=>{if(e.target===ov)document.body.removeChild(ov);});
    ov.appendChild(box);
    document.body.appendChild(ov);
  }

  let dragId=null, dragging=false;
  document.querySelectorAll('#kb-lead .kcard').forEach(card=>{
    card.addEventListener('dragstart',e=>{dragId=card.dataset.id;dragging=true;e.dataTransfer.effectAllowed='move';});
    card.addEventListener('dragend',()=>setTimeout(()=>{dragging=false;},80));
    card.addEventListener('click',()=>{
      if(dragging)return;
      openMoveMenu(card.dataset.id, card.closest('.kbody').dataset.stage);
    });
  });
  document.querySelectorAll('#kb-lead .kbody').forEach(body=>{
    body.addEventListener('dragover',e=>{e.preventDefault();body.classList.add('drag');});
    body.addEventListener('dragleave',()=>body.classList.remove('drag'));
    body.addEventListener('drop',e=>{
      e.preventDefault();body.classList.remove('drag');
      if(!dragId)return;
      doMove(dragId, body.dataset.stage);
      dragId=null;
    });
  });
})();
</script>
