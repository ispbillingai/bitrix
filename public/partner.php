<?php
declare(strict_types=1);

/**
 * Partner area — referrers log in here to see their referrals (and each one's
 * progress) plus their commission accruals (pending / approved / paid). Separate
 * from the staff dashboard and the customer portal: own session, own login.
 *
 * Referral link partners share: request.php?ref=<their code>.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\Pipelines;
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
$brand = (string)Config::get('app.company_name', '') ?: 'Partner';
$money = fn($n): string => (string)Config::get('crm.currency', 'EUR') . ' ' . number_format((float)$n, 2);

// Stage/status label helpers (reuse the lead pipeline labels).
$stageLabel = function (string $code) use ($lang): string {
    $l = Pipelines::label('lead', $code);
    return $l ?: $code;
};

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
.st-open{color:var(--amber);} .st-converted{color:var(--green);} .st-junk{color:var(--red);}
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

    <div class="card">
      <h2 style="margin-top:0"><?= $h($t('your_link')) ?></h2>
      <p class="muted small" style="margin-bottom:8px"><?= $h($t('your_link_sub')) ?></p>
      <div class="reflink" id="reflink"><?= $h($refUrl) ?></div>
      <button class="btn ghost" style="margin-top:10px" onclick="navigator.clipboard.writeText(document.getElementById('reflink').textContent).then(()=>{this.textContent='✓';})"><?= $h($t('copy')) ?></button>
    </div>

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

    <h2><?= $h($t('referrals')) ?> · <?= count($refs) ?></h2>
    <div class="card" style="padding:6px 0">
      <?php if (!$refs): ?>
        <p class="muted" style="padding:16px"><?= $h($t('no_referrals')) ?></p>
      <?php else: ?>
      <table><thead><tr>
        <th style="padding-left:16px"><?= $h($t('customer')) ?></th><th><?= $h($t('stage')) ?></th>
        <th><?= $h($t('status')) ?></th><th><?= $h($t('date')) ?></th>
      </tr></thead><tbody>
        <?php foreach ($refs as $r): ?>
        <tr>
          <td style="padding-left:16px"><?= $h($r['customer_name'] ?: ('#' . $r['id'])) ?></td>
          <td><span class="pill"><?= $h($stageLabel((string)$r['stage_code'])) ?></span></td>
          <td class="st-<?= $h($r['status']) ?>"><?= $h($t('ls_' . $r['status']) !== 'ls_' . $r['status'] ? $t('ls_' . $r['status']) : $r['status']) ?></td>
          <td class="muted small"><?= $h(substr((string)$r['received_at'], 0, 10)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

</body></html>
<?php

/** Minimal bilingual copy for the partner area. */
function partner_strings(string $lang): array
{
    $en = [
        'title' => 'Partner area', 'subtitle' => 'Your referrals and commissions',
        'login_sub' => 'Sign in to see your referrals and commissions.',
        'login_id' => 'Email or phone', 'password' => 'Password', 'login_btn' => 'Sign in',
        'login_err' => 'Wrong credentials, or your account is not active.',
        'logout' => 'Log out',
        'your_link' => 'Your referral link', 'your_link_sub' => 'Share this link. Anyone who requests a quote through it is counted as your referral.',
        'copy' => 'Copy link',
        'accruals' => 'Commissions', 'commission' => 'rate',
        'pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid',
        'customer' => 'Customer', 'base' => 'Deal value', 'amount' => 'Commission', 'status' => 'Status', 'date' => 'Date',
        'acc_pending' => 'Pending', 'acc_approved' => 'Approved', 'acc_paid' => 'Paid', 'acc_cancelled' => 'Cancelled',
        'referrals' => 'Your referrals', 'no_referrals' => 'No referrals yet — share your link to get started.',
        'stage' => 'Stage',
        'ls_open' => 'In progress', 'ls_converted' => 'Converted', 'ls_junk' => 'Discarded',
    ];
    if ($lang === 'it') {
        return [
            'title' => 'Area partner', 'subtitle' => 'Le tue segnalazioni e provvigioni',
            'login_sub' => 'Accedi per vedere le tue segnalazioni e provvigioni.',
            'login_id' => 'Email o telefono', 'password' => 'Password', 'login_btn' => 'Accedi',
            'login_err' => 'Credenziali errate o account non attivo.',
            'logout' => 'Esci',
            'your_link' => 'Il tuo link di segnalazione', 'your_link_sub' => 'Condividi questo link. Chi richiede un preventivo tramite esso viene conteggiato come tua segnalazione.',
            'copy' => 'Copia link',
            'accruals' => 'Provvigioni', 'commission' => 'aliquota',
            'pending' => 'In attesa', 'approved' => 'Approvate', 'paid' => 'Pagate',
            'customer' => 'Cliente', 'base' => 'Valore trattativa', 'amount' => 'Provvigione', 'status' => 'Stato', 'date' => 'Data',
            'acc_pending' => 'In attesa', 'acc_approved' => 'Approvata', 'acc_paid' => 'Pagata', 'acc_cancelled' => 'Annullata',
            'referrals' => 'Le tue segnalazioni', 'no_referrals' => 'Ancora nessuna segnalazione — condividi il tuo link per iniziare.',
            'stage' => 'Fase',
            'ls_open' => 'In corso', 'ls_converted' => 'Convertito', 'ls_junk' => 'Scartato',
        ];
    }
    return $en;
}
