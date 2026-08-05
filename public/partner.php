<?php
declare(strict_types=1);

/**
 * Partner area — a partner's own corner of the CRM. Separate from the staff
 * dashboard and the customer portal: own session, own login.
 *
 * What a partner can do here, and deliberately nothing more (client's rule):
 *   - ENTER their own leads, typed straight in;
 *   - see the STATUS of those leads — in progress, closed, or lost — and none of
 *     how they are being worked: no pipeline stage, no assigned seller, no value;
 *   - share their referral link (request.php?ref=<their code>), which files leads
 *     under them the same way;
 *   - follow their commissions (pending / approved / paid).
 *
 * They are messaged only when a lead ends — see Partner\Partners::notifyOutcome.
 */
require __DIR__ . '/../src/Bootstrap.php';

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
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'login') {
    $p = Partners::login((string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($p) {
        $_SESSION['partner_id'] = (int)$p['id'];
        header('Location: partner.php');
        exit;
    }
    $flash = $t('login_err');
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
    } else {
        $msg = match ($res['error'] ?? '') {
            'required'  => $t('err_required'),
            'vat_taken' => sprintf($t('err_vat_taken'), (string)($res['available_at'] ?? '')),
            default     => $t('err_generic'),
        };
        $_SESSION['partner_notice'] = ['ok' => false, 'msg' => $msg];
    }
    header('Location: partner.php');
    exit;
}
$brand = (string)Config::get('app.company_name', '') ?: 'Partner';
$money = fn($n): string => (string)Config::get('crm.currency', 'EUR') . ' ' . number_format((float)$n, 2);

?><!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($brand) ?> — <?= $h($t('title')) ?></title>
<style>
:root{--bg:#0e131c;--surface:#161c28;--surface2:#1c2533;--line:#28303f;--txt:#e7ecf4;--muted:#8b95a7;--accent:#5b6cff;--green:#3fb868;--amber:#d9a40a;--red:#e5616e;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--txt);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;}
.wrap{max-width:960px;margin:0 auto;padding:24px 18px;}
.center{min-height:100vh;display:flex;align-items:center;justify-content:center;}
.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:22px;}
.login{width:min(400px,92vw);}
h1{font-size:22px;margin-bottom:4px;} h2{font-size:16px;margin:22px 0 10px;}
.muted{color:var(--muted);} .small{font-size:12px;}
label{display:block;margin-bottom:12px;} label span{display:block;font-size:12px;color:var(--muted);margin-bottom:5px;}
input{width:100%;padding:10px 12px;border-radius:9px;border:1px solid var(--line);background:var(--surface2);color:var(--txt);}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:9px;border:0;background:var(--accent);color:#fff;font-weight:600;cursor:pointer;text-decoration:none;}
.btn.ghost{background:var(--surface2);border:1px solid var(--line);color:var(--txt);}
.wide{width:100%;justify-content:center;}
table{width:100%;border-collapse:collapse;margin-top:6px;}
th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line);}
th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);}
.pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;background:var(--surface2);}
.tiles{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;}
.tile{flex:1;min-width:150px;background:var(--surface2);border:1px solid var(--line);border-radius:12px;padding:16px;}
.tile .n{font-size:24px;font-weight:700;letter-spacing:-.02em;}
.tile.p .n{color:var(--amber);} .tile.a .n{color:var(--accent);} .tile.pd .n{color:var(--green);}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;flex-wrap:wrap;}
.reflink{background:var(--surface2);border:1px dashed var(--line);border-radius:10px;padding:12px 14px;font-family:ui-monospace,Menlo,monospace;font-size:13px;word-break:break-all;}
.flash{background:rgba(229,97,110,.13);color:var(--red);padding:11px 14px;border-radius:9px;margin-bottom:14px;}
.flash.ok{background:rgba(63,184,104,.13);color:var(--green);}
.st-open{color:var(--amber);} .st-won{color:var(--green);} .st-lost{color:var(--red);}
.row{display:flex;gap:12px;flex-wrap:wrap;} .row label{flex:1;min-width:180px;}
textarea{width:100%;padding:10px 12px;border-radius:9px;border:1px solid var(--line);background:var(--surface2);color:var(--txt);font-family:inherit;font-size:14px;resize:vertical;}
.hint{font-size:11px;color:var(--muted);margin-top:-8px;margin-bottom:12px;}
summary.btn{list-style:none;} summary.btn::-webkit-details-marker{display:none;}
.langsw a{color:var(--muted);text-decoration:none;padding:2px 6px;} .langsw a.on{color:var(--txt);font-weight:700;}
</style></head><body>

<?php if (!$partner): ?>
  <div class="center"><form method="post" class="card login">
    <h1><?= $h($brand) ?></h1>
    <p class="muted" style="margin-bottom:16px"><?= $h($t('login_sub')) ?></p>
    <?php if ($flash): ?><div class="flash"><?= $h($flash) ?></div><?php endif; ?>
    <input type="hidden" name="do" value="login">
    <label><span><?= $h($t('login_id')) ?></span><input name="login" autofocus></label>
    <label><span><?= $h($t('password')) ?></span><input type="password" name="password"></label>
    <button class="btn wide"><?= $h($t('login_btn')) ?></button>
    <div class="langsw" style="margin-top:14px;text-align:center">
      <a href="?lang=en" class="<?= $lang === 'en' ? 'on' : '' ?>">EN</a> ·
      <a href="?lang=it" class="<?= $lang === 'it' ? 'on' : '' ?>">IT</a>
    </div>
  </form></div>
<?php else:
    $refs   = Partners::referrals($pid);
    $accr   = Partners::accruals($pid);
    $tot    = Partners::totals($pid);
    $base   = Config::appBaseUrl();
    $refUrl = $base . '/request.php?ref=' . rawurlencode((string)$partner['ref_code']);
?>
  <div class="wrap">
    <div class="topbar">
      <div><h1><?= $h($partner['name']) ?></h1><span class="muted small"><?= $h($t('subtitle')) ?></span></div>
      <div class="langsw">
        <a href="?lang=en" class="<?= $lang === 'en' ? 'on' : '' ?>">EN</a> ·
        <a href="?lang=it" class="<?= $lang === 'it' ? 'on' : '' ?>">IT</a>
        &nbsp; <a class="btn ghost" href="?action=logout"><?= $h($t('logout')) ?></a>
      </div>
    </div>

    <?php if ($notice): ?>
      <div class="flash <?= !empty($notice['ok']) ? 'ok' : '' ?>"><?= $h($notice['msg']) ?></div>
    <?php endif; ?>

    <div class="card">
      <h2 style="margin-top:0"><?= $h($t('new_lead')) ?></h2>
      <p class="muted small" style="margin-bottom:14px"><?= $h($t('new_lead_sub')) ?></p>
      <form method="post">
        <input type="hidden" name="do" value="lead">
        <div class="row">
          <label><span><?= $h($t('f_name')) ?> *</span><input name="name" required></label>
          <label><span><?= $h($t('f_company')) ?></span><input name="company"></label>
        </div>
        <div class="row">
          <label><span><?= $h($t('f_email')) ?></span><input type="email" name="email"></label>
          <label><span><?= $h($t('f_phone')) ?></span><input name="phone" placeholder="<?= $h($t('f_phone_ph')) ?>"></label>
        </div>
        <p class="hint"><?= $h($t('f_contact_hint')) ?></p>
        <label><span><?= $h($t('f_vat')) ?></span><input name="vat_number" placeholder="<?= $h($t('f_vat_ph')) ?>"></label>
        <p class="hint"><?= $h($t('f_vat_hint')) ?></p>
        <label><span><?= $h($t('f_message')) ?></span><textarea name="message" rows="3" placeholder="<?= $h($t('f_message_ph')) ?>"></textarea></label>
        <button class="btn"><?= $h($t('f_send')) ?></button>
      </form>
    </div>

    <h2><?= $h($t('referrals')) ?> · <?= count($refs) ?></h2>
    <div class="card" style="padding:6px 0">
      <?php if (!$refs): ?>
        <p class="muted" style="padding:16px"><?= $h($t('no_referrals')) ?></p>
      <?php else: ?>
      <table><thead><tr>
        <th style="padding-left:16px"><?= $h($t('customer')) ?></th>
        <th><?= $h($t('status')) ?></th><th><?= $h($t('date')) ?></th>
      </tr></thead><tbody>
        <?php foreach ($refs as $r): $st = Partners::outcome($r); ?>
        <tr>
          <td style="padding-left:16px"><?= $h($r['customer_name'] ?: ('#' . $r['id'])) ?></td>
          <td class="st-<?= $h($st) ?>"><strong><?= $h($t('st_' . $st)) ?></strong></td>
          <td class="muted small"><?= $h(substr((string)$r['received_at'], 0, 10)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>

    <details class="card" style="margin-top:12px">
      <summary style="cursor:pointer;font-weight:600"><?= $h($t('your_link')) ?></summary>
      <p class="muted small" style="margin:10px 0 8px"><?= $h($t('your_link_sub')) ?></p>
      <div class="reflink" id="reflink"><?= $h($refUrl) ?></div>
      <button type="button" class="btn ghost" style="margin-top:10px" onclick="navigator.clipboard.writeText(document.getElementById('reflink').textContent).then(()=>{this.textContent='✓';})"><?= $h($t('copy')) ?></button>
    </details>

    <h2><?= $h($t('accruals')) ?> <span class="muted small">(<?= $h($t('commission')) ?> <?= number_format((float)$partner['commission_pct'], 1) ?>%)</span></h2>
    <div class="tiles">
      <div class="tile p"><div class="muted small"><?= $h($t('pending')) ?></div><div class="n"><?= $h($money($tot['pending'])) ?></div></div>
      <div class="tile a"><div class="muted small"><?= $h($t('approved')) ?></div><div class="n"><?= $h($money($tot['approved'])) ?></div></div>
      <div class="tile pd"><div class="muted small"><?= $h($t('paid')) ?></div><div class="n"><?= $h($money($tot['paid'])) ?></div></div>
    </div>

    <?php if ($accr): ?>
    <div class="card" style="margin-top:12px;padding:6px 0">
      <table><thead><tr>
        <th style="padding-left:16px"><?= $h($t('customer')) ?></th><th><?= $h($t('base')) ?></th>
        <th><?= $h($t('amount')) ?></th><th><?= $h($t('status')) ?></th><th><?= $h($t('date')) ?></th>
      </tr></thead><tbody>
        <?php foreach ($accr as $a): ?>
        <tr>
          <td style="padding-left:16px"><?= $h($a['customer_name'] ?: $a['deal_title'] ?: '—') ?></td>
          <td class="muted"><?= $h($money($a['base_amount'])) ?></td>
          <td><strong><?= $h($money($a['amount'])) ?></strong></td>
          <td><span class="pill"><?= $h($t('acc_' . $a['status'])) ?></span></td>
          <td class="muted small"><?= $h(substr((string)$a['created_at'], 0, 10)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php endif; ?>

  </div>
<?php endif; ?>

</body></html>
<?php

/** Minimal bilingual copy for the partner area. */
function partner_strings(string $lang): array
{
    $en = [
        'title' => 'Partner area', 'subtitle' => 'Your leads and commissions',
        'login_sub' => 'Sign in to enter your leads and follow them.',
        'login_id' => 'Email or phone', 'password' => 'Password', 'login_btn' => 'Sign in',
        'login_err' => 'Wrong credentials, or your account is not active.',
        'logout' => 'Log out',
        'your_link' => 'Your referral link', 'your_link_sub' => 'Share this link. Anyone who requests a quote through it is counted as your lead, exactly as if you had entered them here.',
        'copy' => 'Copy link',
        'accruals' => 'Commissions', 'commission' => 'rate',
        'pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid',
        'customer' => 'Customer', 'base' => 'Deal value', 'amount' => 'Commission', 'status' => 'Status', 'date' => 'Date',
        'acc_pending' => 'Pending', 'acc_approved' => 'Approved', 'acc_paid' => 'Paid', 'acc_cancelled' => 'Cancelled',
        'referrals' => 'Your leads', 'no_referrals' => 'No leads yet — enter your first one above.',
        // The only three words a partner sees about a lead.
        'st_open' => 'In progress', 'st_won' => 'Closed', 'st_lost' => 'Lost',
        // Lead entry.
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
    ];
    if ($lang === 'it') {
        return [
            'title' => 'Area partner', 'subtitle' => 'Le tue segnalazioni e provvigioni',
            'login_sub' => 'Accedi per inserire le tue segnalazioni e seguirle.',
            'login_id' => 'Email o telefono', 'password' => 'Password', 'login_btn' => 'Accedi',
            'login_err' => 'Credenziali errate o account non attivo.',
            'logout' => 'Esci',
            'your_link' => 'Il tuo link di segnalazione', 'your_link_sub' => 'Condividi questo link. Chi richiede un preventivo tramite esso diventa una tua segnalazione, esattamente come se l’avessi inserita qui.',
            'copy' => 'Copia link',
            'accruals' => 'Provvigioni', 'commission' => 'aliquota',
            'pending' => 'In attesa', 'approved' => 'Approvate', 'paid' => 'Pagate',
            'customer' => 'Cliente', 'base' => 'Valore trattativa', 'amount' => 'Provvigione', 'status' => 'Stato', 'date' => 'Data',
            'acc_pending' => 'In attesa', 'acc_approved' => 'Approvata', 'acc_paid' => 'Pagata', 'acc_cancelled' => 'Annullata',
            'referrals' => 'Le tue segnalazioni', 'no_referrals' => 'Ancora nessuna segnalazione — inserisci la prima qui sopra.',
            'st_open' => 'In corso', 'st_won' => 'Chiusa', 'st_lost' => 'Persa',
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
        ];
    }
    return $en;
}
