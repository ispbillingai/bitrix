<?php
declare(strict_types=1);

/**
 * Partner area — the CRM dashboard wearing a much smaller nav. Same shell, same
 * sidebar, same cards and tables (views/_ui.php is the one stylesheet both pages
 * use), so a partner is not dropped onto a stray-looking page: it is the same
 * application, showing them their corner of it.
 *
 * Separate session and separate login from the staff dashboard — a partner is
 * not a CRM user and can never reach dashboard.php.
 *
 * What the nav deliberately holds, and nothing more (client's rule):
 *   Overview     their own numbers
 *   New lead     enter a lead themselves
 *   My leads     the STATUS of each — new, contacted, qualified, in negotiation,
 *                closed, lost — and no more: no assigned seller, no deal value,
 *                no internal stage codes. It has to MOVE as the office works the
 *                lead, or it tells the partner nothing (Partners::status).
 *   Commissions  what their closed leads earned
 *   Referral link  the ?ref= link, which files leads under them the same way
 *
 * They are messaged only when a lead ends — see Partner\Partners::notifyOutcome.
 */
require __DIR__ . '/../src/Bootstrap.php';
require_once __DIR__ . '/../views/_ui.php';   // svg() + css(), shared with the dashboard

use Glue\Bootstrap;
use Glue\Config;
use Glue\Partner\Partners;

Bootstrap::init();

session_name('crm_partner');
session_set_cookie_params(2592000, '/', '', false, true);
session_start();

$h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$avail = ['en', 'it'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $avail, true)) {
    setcookie('crm_partner_lang', $_GET['lang'], time() + 31536000, '/');
    $_COOKIE['crm_partner_lang'] = $_GET['lang'];
}
$lang = in_array($_COOKIE['crm_partner_lang'] ?? '', $avail, true)
    ? $_COOKIE['crm_partner_lang']
    : (in_array(Config::get('app.default_lang', 'it'), $avail, true) ? (string)Config::get('app.default_lang', 'it') : 'en');
$S = partner_strings($lang);
$t = fn(string $k): string => $S[$k] ?? $k;

// ---- logout ----
if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: partner.php');
    exit;
}

// ---- login ----
$loginErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'login') {
    $p = Partners::login((string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($p) {
        $_SESSION['partner_id'] = (int)$p['id'];
        header('Location: partner.php');
        exit;
    }
    $loginErr = $t('login_err');
}

$pid = (int)($_SESSION['partner_id'] ?? 0);
$partner = $pid > 0 ? Partners::find($pid) : null;
if ($partner && (int)$partner['active'] !== 1) {
    session_destroy();
    $partner = null;
}

// ---- new lead, typed in by the partner ----
// Post/Redirect/Get: the outcome rides in the session so a refresh of the page
// can't file the same customer twice.
$notice = $_SESSION['partner_notice'] ?? null;
unset($_SESSION['partner_notice']);

if ($partner && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'lead') {
    $res = Partners::submitLead($pid, [
        'name'       => (string)($_POST['name'] ?? ''),
        'company'    => (string)($_POST['company'] ?? ''),
        'vat_number' => (string)($_POST['vat_number'] ?? ''),
        'email'      => (string)($_POST['email'] ?? ''),
        'phone'      => (string)($_POST['phone'] ?? ''),
        'comments'   => (string)($_POST['message'] ?? ''),
        'lang'       => $lang,
    ]);
    if (!empty($res['ok'])) {
        $key = match ($res['duplicate'] ?? '') {
            'own'   => 'ok_dup_own',
            'other' => 'ok_dup_other',
            default => 'ok_created',
        };
        $_SESSION['partner_notice'] = ['ok' => true, 'msg' => $t($key)];
        $back = 'leads';
    } else {
        $msg = match ($res['error'] ?? '') {
            'required'  => $t('err_required'),
            'vat_taken' => sprintf($t('err_vat_taken'), (string)($res['available_at'] ?? '')),
            default     => $t('err_generic'),
        };
        $_SESSION['partner_notice'] = ['ok' => false, 'msg' => $msg];
        $back = 'new';
    }
    header('Location: partner.php?tab=' . $back);
    exit;
}

// ---- not logged in: the same login card the staff dashboard shows ----
if (!$partner) {
    $brand = (string)Config::get('app.company_name', '') ?: $t('title');
    ?><!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $h($brand) ?> — <?= $h($t('title')) ?></title><?php css(); ?></head>
    <body class="center">
      <form class="login" method="post">
        <div class="logo"><?= $h(strtoupper(substr($brand, 0, 1)) ?: 'P') ?></div>
        <h1><?= $h($t('title')) ?></h1>
        <p class="muted"><?= $h($t('login_sub')) ?></p>
        <?php if ($loginErr): ?><p class="err"><?= $h($loginErr) ?></p><?php endif; ?>
        <input type="hidden" name="do" value="login">
        <input type="text" name="login" placeholder="<?= $h($t('login_id')) ?>" autofocus>
        <input type="password" name="password" placeholder="<?= $h($t('password')) ?>">
        <button type="submit" class="btn"><?= $h($t('login_btn')) ?></button>
        <div class="langsw" style="margin-top:16px;justify-content:center">
          <a href="?lang=en" class="<?= $lang === 'en' ? 'on' : '' ?>">EN</a>
          <a href="?lang=it" class="<?= $lang === 'it' ? 'on' : '' ?>">IT</a>
        </div>
      </form>
    </body></html>
    <?php
    exit;
}

// ---- logged in ----
$nav = ['overview' => 'nav_overview', 'new' => 'nav_new', 'leads' => 'nav_leads',
        'commissions' => 'nav_commissions', 'link' => 'nav_link'];
$tab = (string)($_GET['tab'] ?? 'overview');
if (!isset($nav[$tab])) {
    $tab = 'overview';
}
$icons = ['overview' => 'overview', 'new' => 'pen', 'leads' => 'leads',
          'commissions' => 'money', 'link' => 'link'];

$brand  = (string)Config::get('app.company_name', '') ?: $t('title');
$money  = fn($n): string => (string)Config::get('crm.currency', 'EUR') . ' ' . number_format((float)$n, 2);
$refs   = Partners::referrals($pid);
$tot    = Partners::totals($pid);
$refUrl = Config::appBaseUrl() . '/request.php?ref=' . rawurlencode((string)$partner['ref_code']);

// Their own numbers, counted the same way the list shows them.
$count = ['open' => 0, 'won' => 0, 'lost' => 0];
foreach ($refs as $r) {
    $count[Partners::outcome($r)]++;
}
?><!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($brand) ?> — <?= $h($t('title')) ?></title><?php css(); ?></head>
<body>
<div class="shell">
  <div class="nav-backdrop" id="navBackdrop" onclick="closeNav()"></div>
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="logo"><?= $h(strtoupper(substr($brand, 0, 1)) ?: 'P') ?></div>
      <div><strong><?= $h($brand) ?></strong><span class="muted small"><?= $h($t('title')) ?></span></div>
    </div>
    <nav>
      <?php foreach ($nav as $key => $label): ?>
        <a class="<?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= $h($key) ?>"><?= svg($icons[$key]) ?><span><?= $h($t($label)) ?></span></a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <main>
    <header class="topbar">
      <button class="navtoggle" id="navToggle" onclick="openNav()" aria-label="Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="crumb"><?= $h($t($nav[$tab])) ?></div>
      <div class="actions">
        <span class="langsw">
          <a class="<?= $lang === 'en' ? 'on' : '' ?>" href="?tab=<?= $h($tab) ?>&lang=en">EN</a>
          <a class="<?= $lang === 'it' ? 'on' : '' ?>" href="?tab=<?= $h($tab) ?>&lang=it">IT</a>
        </span>
        <span class="muted small who"><?= $h($partner['name']) ?></span>
        <a class="btn ghost" href="?action=logout"><?= $h($t('logout')) ?></a>
      </div>
    </header>
    <div class="content">

<?php if ($notice): ?>
  <div class="flash <?= empty($notice['ok']) ? 'flash-err' : '' ?>"><?= $h($notice['msg']) ?></div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
  <h2><?= $h($t('ov_title')) ?></h2>
  <p class="lead"><?= $h($t('ov_sub')) ?></p>
  <div class="grid">
    <?php foreach ([['leads', 'ov_total', count($refs), ''],
                    ['clock', 'st_open', $count['open'], 'ov_open_sub'],
                    ['trophy', 'st_won', $count['won'], 'ov_won_sub'],
                    ['alert', 'st_lost', $count['lost'], 'ov_lost_sub']] as [$ic, $lbl, $n, $sub]): ?>
      <div class="tile">
        <div class="tile-top"><?= svg($ic) ?><span><?= $h($t($lbl)) ?></span></div>
        <span class="big"><?= (int)$n ?></span>
        <?php if ($sub !== ''): ?><div class="sub"><?= $h($t($sub)) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cols c-2-1">
    <div class="panel">
      <div class="panel-h"><h3><?= svg('leads') ?> <?= $h($t('ov_recent')) ?></h3>
        <a class="btn ghost tiny" href="?tab=leads"><?= $h($t('ov_all')) ?></a></div>
      <?php if (!$refs): ?>
        <div class="empty"><?= $h($t('no_referrals')) ?></div>
      <?php else: ?>
        <div class="feed">
          <?php foreach (array_slice($refs, 0, 6) as $r): $st = Partners::status($r); ?>
            <div class="feed-row">
              <div class="feed-ic"><?= svg('leads') ?></div>
              <div class="feed-main">
                <b><?= $h($r['customer_name'] ?: ('#' . $r['id'])) ?></b>
                <div class="meta"><?= $h(substr((string)$r['received_at'], 0, 10)) ?></div>
              </div>
              <span class="pill pill-<?= $h($st) ?>"><?= $h($t('st_' . $st)) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-h"><h3><?= svg('money') ?> <?= $h($t('nav_commissions')) ?></h3></div>
      <div class="lb"><span class="nm"><?= $h($t('pending')) ?></span><span class="sc" style="color:var(--amber)"><?= $h($money($tot['pending'])) ?></span></div>
      <div class="lb"><span class="nm"><?= $h($t('approved')) ?></span><span class="sc"><?= $h($money($tot['approved'])) ?></span></div>
      <div class="lb"><span class="nm"><?= $h($t('paid')) ?></span><span class="sc" style="color:var(--green)"><?= $h($money($tot['paid'])) ?></span></div>
      <p class="muted small" style="margin-top:12px"><?= $h($t('commission')) ?>: <strong><?= number_format((float)$partner['commission_pct'], 1) ?>%</strong></p>
    </div>
  </div>

<?php elseif ($tab === 'new'): ?>
  <h2><?= $h($t('new_lead')) ?></h2>
  <p class="lead"><?= $h($t('new_lead_sub')) ?></p>
  <form method="post" class="card" style="max-width:820px">
    <input type="hidden" name="do" value="lead">
    <div class="row">
      <label class="fld"><span><?= $h($t('f_name')) ?> *</span><input name="name" required autofocus></label>
      <label class="fld"><span><?= $h($t('f_company')) ?></span><input name="company"></label>
    </div>
    <div class="row">
      <label class="fld"><span><?= $h($t('f_email')) ?></span><input type="email" name="email"></label>
      <label class="fld"><span><?= $h($t('f_phone')) ?></span><input name="phone" placeholder="<?= $h($t('f_phone_ph')) ?>"></label>
    </div>
    <p class="muted small" style="margin:-8px 0 16px"><?= $h($t('f_contact_hint')) ?></p>
    <label class="fld"><span><?= $h($t('f_vat')) ?></span><input name="vat_number" placeholder="<?= $h($t('f_vat_ph')) ?>">
      <small class="muted"><?= $h($t('f_vat_hint')) ?></small></label>
    <label class="fld"><span><?= $h($t('f_message')) ?></span><textarea name="message" rows="4" placeholder="<?= $h($t('f_message_ph')) ?>"></textarea></label>
    <button class="btn"><?= svg('send') ?> <?= $h($t('f_send')) ?></button>
  </form>

<?php elseif ($tab === 'leads'): ?>
  <h2><?= $h($t('referrals')) ?> · <?= count($refs) ?></h2>
  <p class="lead"><?= $h($t('leads_sub')) ?></p>
  <a class="btn" href="?tab=new" style="margin-bottom:16px"><?= svg('pen') ?> <?= $h($t('nav_new')) ?></a>
  <?php if (!$refs): ?>
    <div class="card"><div class="empty"><?= $h($t('no_referrals')) ?></div></div>
  <?php else: ?>
    <table>
      <thead><tr><th><?= $h($t('customer')) ?></th><th><?= $h($t('status')) ?></th><th><?= $h($t('date')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($refs as $r): $st = Partners::status($r); ?>
          <tr>
            <td><strong><?= $h($r['customer_name'] ?: ('#' . $r['id'])) ?></strong></td>
            <td><span class="pill pill-<?= $h($st) ?>"><?= $h($t('st_' . $st)) ?></span></td>
            <td class="muted small"><?= $h(substr((string)$r['received_at'], 0, 10)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

<?php elseif ($tab === 'commissions'): ?>
  <h2><?= $h($t('accruals')) ?></h2>
  <p class="lead"><?= $h($t('comm_sub')) ?> <?= $h($t('commission')) ?>: <strong><?= number_format((float)$partner['commission_pct'], 1) ?>%</strong></p>
  <div class="grid">
    <div class="tile"><div class="tile-top"><?= svg('clock') ?><span><?= $h($t('pending')) ?></span></div>
      <span class="big" style="color:var(--amber)"><?= $h($money($tot['pending'])) ?></span></div>
    <div class="tile"><div class="tile-top"><?= svg('check') ?><span><?= $h($t('approved')) ?></span></div>
      <span class="big"><?= $h($money($tot['approved'])) ?></span></div>
    <div class="tile"><div class="tile-top"><?= svg('money') ?><span><?= $h($t('paid')) ?></span></div>
      <span class="big" style="color:var(--green)"><?= $h($money($tot['paid'])) ?></span></div>
  </div>
  <?php $accr = Partners::accruals($pid); ?>
  <?php if (!$accr): ?>
    <div class="card"><div class="empty"><?= $h($t('no_accruals')) ?></div></div>
  <?php else: ?>
    <table>
      <thead><tr><th><?= $h($t('customer')) ?></th><th><?= $h($t('base')) ?></th>
        <th><?= $h($t('amount')) ?></th><th><?= $h($t('status')) ?></th><th><?= $h($t('date')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($accr as $a): ?>
          <tr>
            <td><?= $h($a['customer_name'] ?: $a['deal_title'] ?: '—') ?></td>
            <td class="muted"><?= $h($money($a['base_amount'])) ?></td>
            <td><strong><?= $h($money($a['amount'])) ?></strong></td>
            <td><span class="pill pill-<?= $h($a['status']) ?>"><?= $h($t('acc_' . $a['status'])) ?></span></td>
            <td class="muted small"><?= $h(substr((string)$a['created_at'], 0, 10)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

<?php else: /* link */ ?>
  <h2><?= $h($t('your_link')) ?></h2>
  <p class="lead"><?= $h($t('your_link_sub')) ?></p>
  <div class="card" style="max-width:820px">
    <div id="reflink" style="background:var(--surface2);border:1px dashed var(--line2);border-radius:9px;
         padding:14px 16px;font-family:ui-monospace,Menlo,monospace;font-size:13px;word-break:break-all;margin-bottom:14px">
      <?= $h($refUrl) ?>
    </div>
    <button type="button" class="btn ghost" onclick="navigator.clipboard.writeText(document.getElementById('reflink').textContent.trim()).then(()=>{this.textContent='✓';})">
      <?= $h($t('copy')) ?>
    </button>
  </div>
<?php endif; ?>

</div></main></div>
<script>
function openNav(){document.getElementById('sidebar').classList.add('open');
  document.getElementById('navBackdrop').classList.add('show');}
function closeNav(){document.getElementById('sidebar').classList.remove('open');
  document.getElementById('navBackdrop').classList.remove('show');}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNav(); });
document.querySelectorAll('.sidebar nav a').forEach(a => a.addEventListener('click', closeNav));
// Wide tables scroll inside their own box rather than pushing the page sideways.
document.querySelectorAll('table').forEach(tb => {
  if (tb.parentElement.classList.contains('table-wrap')) return;
  const w = document.createElement('div'); w.className = 'table-wrap';
  tb.parentNode.insertBefore(w, tb); w.appendChild(tb);
});
</script>
</body></html>
<?php

/** Bilingual copy for the partner area. */
function partner_strings(string $lang): array
{
    $en = [
        'title' => 'Partner area',
        'login_sub' => 'Sign in to enter your leads and follow them.',
        'login_id' => 'Email or phone', 'password' => 'Password', 'login_btn' => 'Sign in',
        'login_err' => 'Wrong credentials, or your account is not active.',
        'logout' => 'Log out',
        // nav
        'nav_overview' => 'Overview', 'nav_new' => 'New lead', 'nav_leads' => 'My leads',
        'nav_commissions' => 'Commissions', 'nav_link' => 'Referral link',
        // overview
        'ov_title' => 'Your activity', 'ov_sub' => 'Everything you have brought in, and how it ended.',
        'ov_total' => 'Total leads', 'ov_recent' => 'Latest leads', 'ov_all' => 'See all',
        'ov_open_sub' => 'we are working on them',
        'ov_won_sub' => 'closed successfully', 'ov_lost_sub' => 'closed without agreement',
        // what a partner is shown about a lead — a status that actually moves
        'st_new' => 'New', 'st_contacted' => 'Contacted', 'st_qualified' => 'Qualified',
        'st_working' => 'Being worked', 'st_negotiation' => 'In negotiation',
        'st_won' => 'Closed', 'st_lost' => 'Lost',
        'st_open' => 'In progress',   // the tile, which counts every one of the above
        // leads
        'referrals' => 'My leads', 'leads_sub' => 'The status of every lead you brought in. We message you as soon as one is closed or lost.',
        'no_referrals' => 'No leads yet — enter your first one.',
        'customer' => 'Customer', 'status' => 'Status', 'date' => 'Date',
        // lead entry
        'new_lead' => 'Enter a new lead',
        'new_lead_sub' => 'Fill in your contact and we take it from here. You will hear from us when the lead is closed or lost.',
        'f_name' => 'Contact name', 'f_company' => 'Company',
        'f_email' => 'Email', 'f_phone' => 'Phone', 'f_phone_ph' => 'e.g. 339 1234567',
        'f_contact_hint' => 'Give at least one of email or phone. For a number outside Italy, start it with + and the country code.',
        'f_vat' => 'VAT number (optional)', 'f_vat_ph' => 'e.g. 01234567890',
        'f_vat_hint' => 'Entering the VAT number reserves the customer for you for 90 days.',
        'f_message' => 'Notes', 'f_message_ph' => 'What do they need?',
        'f_send' => 'Send lead',
        'ok_created' => 'Thank you — your lead has been received. We will let you know how it ends.',
        'ok_dup_own' => 'You had already entered this customer. Your notes have been added to their existing lead.',
        'ok_dup_other' => 'This customer is already in our system under another entry. Your notes have been recorded, but the lead is not assigned to you.',
        'err_required' => 'Please give the contact name and at least an email or a phone number.',
        'err_vat_taken' => 'This VAT number has already been entered by another associate. It becomes available again on %s.',
        'err_generic' => 'The lead could not be saved. Please try again.',
        // commissions
        'accruals' => 'Commissions', 'commission' => 'Your rate',
        'comm_sub' => 'What your closed leads have earned.',
        'pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid',
        'base' => 'Deal value', 'amount' => 'Commission',
        'acc_pending' => 'Pending', 'acc_approved' => 'Approved', 'acc_paid' => 'Paid', 'acc_cancelled' => 'Cancelled',
        'no_accruals' => 'No commissions yet — they appear here once a lead of yours is closed.',
        // referral link
        'your_link' => 'Your referral link',
        'your_link_sub' => 'Share this link. Anyone who requests a quote through it becomes your lead, exactly as if you had entered them here.',
        'copy' => 'Copy link',
    ];
    if ($lang !== 'it') {
        return $en;
    }
    return [
        'title' => 'Area partner',
        'login_sub' => 'Accedi per inserire le tue segnalazioni e seguirle.',
        'login_id' => 'Email o telefono', 'password' => 'Password', 'login_btn' => 'Accedi',
        'login_err' => 'Credenziali errate o account non attivo.',
        'logout' => 'Esci',
        'nav_overview' => 'Panoramica', 'nav_new' => 'Nuova segnalazione', 'nav_leads' => 'Le mie segnalazioni',
        'nav_commissions' => 'Provvigioni', 'nav_link' => 'Link di segnalazione',
        'ov_title' => 'La tua attività', 'ov_sub' => 'Tutto quello che hai portato, e come è andato a finire.',
        'ov_total' => 'Segnalazioni totali', 'ov_recent' => 'Ultime segnalazioni', 'ov_all' => 'Vedi tutte',
        'ov_open_sub' => 'ci stiamo lavorando',
        'ov_won_sub' => 'chiuse positivamente', 'ov_lost_sub' => 'chiuse senza accordo',
        'st_new' => 'Nuova', 'st_contacted' => 'Contattata', 'st_qualified' => 'Qualificata',
        'st_working' => 'In lavorazione', 'st_negotiation' => 'In trattativa',
        'st_won' => 'Chiusa', 'st_lost' => 'Persa',
        'st_open' => 'In corso',
        'referrals' => 'Le mie segnalazioni', 'leads_sub' => 'Lo stato di ogni segnalazione che hai portato. Ti scriviamo appena una viene chiusa o persa.',
        'no_referrals' => 'Ancora nessuna segnalazione — inserisci la prima.',
        'customer' => 'Cliente', 'status' => 'Stato', 'date' => 'Data',
        'new_lead' => 'Inserisci una nuova segnalazione',
        'new_lead_sub' => 'Compila i dati del contatto e al resto pensiamo noi. Ti avviseremo quando la segnalazione sarà chiusa o persa.',
        'f_name' => 'Nome del contatto', 'f_company' => 'Azienda',
        'f_email' => 'Email', 'f_phone' => 'Telefono', 'f_phone_ph' => 'es. 339 1234567',
        'f_contact_hint' => 'Indica almeno email o telefono. Per un numero estero, inizia con + e il prefisso internazionale.',
        'f_vat' => 'Partita IVA (facoltativa)', 'f_vat_ph' => 'es. 01234567890',
        'f_vat_hint' => 'Inserendo la partita IVA il cliente resta riservato a te per 90 giorni.',
        'f_message' => 'Note', 'f_message_ph' => 'Di cosa ha bisogno?',
        'f_send' => 'Invia segnalazione',
        'ok_created' => 'Grazie — abbiamo ricevuto la tua segnalazione. Ti faremo sapere come va a finire.',
        'ok_dup_own' => 'Avevi già inserito questo cliente. Le tue note sono state aggiunte alla segnalazione esistente.',
        'ok_dup_other' => 'Questo cliente è già presente con un’altra segnalazione. Le tue note sono state registrate, ma la segnalazione non è attribuita a te.',
        'err_required' => 'Inserisci il nome del contatto e almeno email o telefono.',
        'err_vat_taken' => 'Questa partita IVA è già stata inserita da un altro collaboratore. Tornerà disponibile il %s.',
        'err_generic' => 'Non è stato possibile salvare la segnalazione. Riprova.',
        'accruals' => 'Provvigioni', 'commission' => 'La tua aliquota',
        'comm_sub' => 'Quanto hanno reso le tue segnalazioni chiuse.',
        'pending' => 'In attesa', 'approved' => 'Approvate', 'paid' => 'Pagate',
        'base' => 'Valore trattativa', 'amount' => 'Provvigione',
        'acc_pending' => 'In attesa', 'acc_approved' => 'Approvata', 'acc_paid' => 'Pagata', 'acc_cancelled' => 'Annullata',
        'no_accruals' => 'Ancora nessuna provvigione — compaiono qui quando una tua segnalazione viene chiusa.',
        'your_link' => 'Il tuo link di segnalazione',
        'your_link_sub' => 'Condividi questo link. Chi richiede un preventivo tramite esso diventa una tua segnalazione, esattamente come se l’avessi inserita qui.',
        'copy' => 'Copia link',
    ];
}
