<?php
declare(strict_types=1);

/**
 * Customer portal — the customer-facing area (separate from the staff dashboard).
 * A customer reaches it via the magic link their agent sends, then can set a
 * password for permanent access. They see their estimate (deal) and order status,
 * and sign the contract with a one-time code (OTP). Phase 2 of the CRM.
 *
 * Self-contained: its own session, its own minimal bilingual copy and styling.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\Deals;
use Glue\Crm\Tickets;
use Glue\Db;
use Glue\Portal\Account;
use Glue\Portal\Otp;

Bootstrap::init();

session_name('crm_portal');
session_set_cookie_params(2592000, '/', '', false, true);
session_start();

$h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ---- language (?lang=, cookie, default) ----
$avail = ['en', 'it'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $avail, true)) {
    setcookie('crm_portal_lang', $_GET['lang'], time() + 31536000, '/');
    $_COOKIE['crm_portal_lang'] = $_GET['lang'];
}
$lang = in_array($_COOKIE['crm_portal_lang'] ?? '', $avail, true)
    ? $_COOKIE['crm_portal_lang']
    : (in_array(Config::get('app.default_lang', 'it'), $avail, true) ? (string)Config::get('app.default_lang', 'it') : 'en');

$S = portal_strings($lang);
$t = fn(string $k): string => $S[$k] ?? $k;
$brand = (string)Config::get('mail.from_name', '') ?: (string)Config::get('app.company_name', 'CRM');
$money = fn($n, $cur) => ($cur ?: (string)Config::get('crm.currency', 'EUR')) . ' ' . number_format((float)$n, 2);

// ---- magic-link login ----
if (isset($_GET['token'])) {
    $c = Account::findByToken((string)$_GET['token']);
    if ($c) {
        $_SESSION['portal_cid'] = (int)$c['id'];
        Account::touchLogin((int)$c['id']);
    }
    header('Location: portal.php');
    exit;
}
if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: portal.php');
    exit;
}

$cid = (int)($_SESSION['portal_cid'] ?? 0);
$me  = $cid ? Account::find($cid) : null;
if (!$me) {
    $cid = 0;
    unset($_SESSION['portal_cid']);
}

$flash = null;
$flashType = 'ok';
$signFor = 0; // deal id we're currently entering a code for

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'login' && !$cid) {
        $c = Account::login((string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($c) {
            $_SESSION['portal_cid'] = (int)$c['id'];
            Account::touchLogin((int)$c['id']);
            header('Location: portal.php');
            exit;
        }
        $flash = $t('login_err');
        $flashType = 'err';
    }

    if ($cid && $do === 'set_password') {
        if (Account::setPassword($cid, (string)($_POST['password'] ?? ''))) {
            $flash = $t('pw_saved');
        } else {
            $flash = $t('pw_short');
            $flashType = 'err';
        }
    }

    if ($cid && $do === 'ticket_open') {
        if (trim((string)($_POST['body'] ?? '')) !== '') {
            Tickets::open($cid, (string)($_POST['subject'] ?? ''), (string)$_POST['body']);
            $flash = $t('tk_opened');
        }
    }
    if ($cid && $do === 'ticket_reply') {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        if (owns_ticket($cid, $tid) && trim((string)($_POST['body'] ?? '')) !== '') {
            Tickets::reply($tid, 'customer', $cid, (string)($me['name'] ?? ''), (string)$_POST['body']);
            $flash = $t('tk_sent');
        } else {
            $flash = $t('err_generic');
            $flashType = 'err';
        }
    }

    // signing — only ever on a deal that belongs to this customer
    if ($cid && in_array($do, ['sign_start', 'sign_resend', 'sign_verify'], true)) {
        $dealId = (int)($_POST['deal_id'] ?? 0);
        $deal = owns_deal($cid, $dealId);
        if (!$deal) {
            $flash = $t('err_generic');
            $flashType = 'err';
        } elseif ($do === 'sign_verify') {
            $res = Otp::verify($cid, $dealId, (string)($_POST['code'] ?? ''), 'sign');
            if ($res === 'ok') {
                Deals::markSigned($dealId, (string)($me['name'] ?? ''), client_ip());
                $flash = $t('sign_done');
            } else {
                $flash = $t('otp_' . $res);
                $flashType = 'err';
                $signFor = $dealId;
            }
        } else { // sign_start | sign_resend
            Otp::issue($cid, $dealId, 'sign');
            $flash = $t('otp_sent');
            $signFor = $dealId;
        }
    }
}

// ---- data for the logged-in customer ----
$deals = $appts = $tickets = [];
if ($cid) {
    $st = Db::pdo()->prepare('SELECT * FROM deals WHERE contact_id = ? ORDER BY id DESC');
    $st->execute([$cid]);
    $deals = $st->fetchAll();

    $st = Db::pdo()->prepare('SELECT * FROM appointments WHERE contact_id = ? ORDER BY (starts_at IS NULL), starts_at DESC, id DESC');
    $st->execute([$cid]);
    $appts = $st->fetchAll();

    $tickets = Tickets::forContact($cid);
}
$quoteStage = (string)Config::get('crm.deal_quote_stage', 'QUOTE');

?><!DOCTYPE html><html lang="<?= $h($lang) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($brand) ?> — <?= $h($t('portal')) ?></title>
<?php portal_css(); ?></head>
<body>
<header class="top">
  <div class="brand"><span class="logo"><?= $h(strtoupper(substr($brand, 0, 1)) ?: 'C') ?></span><strong><?= $h($brand) ?></strong><span class="brand-sub"><?= $h($t('portal')) ?></span></div>
  <div class="top-r">
    <span class="lang">
      <a class="<?= $lang === 'en' ? 'on' : '' ?>" href="?lang=en">EN</a>
      <a class="<?= $lang === 'it' ? 'on' : '' ?>" href="?lang=it">IT</a>
    </span>
    <?php if ($cid): ?><a class="btn ghost sm" href="?action=logout"><?= $h($t('logout')) ?></a><?php endif; ?>
  </div>
</header>
<?php if ($cid): ?>
<div class="hero">
  <div class="hero-in">
    <div class="avatar"><?= $h(strtoupper(substr((string)($me['name'] ?? ''), 0, 1)) ?: '☺') ?></div>
    <div>
      <h1><?= $h($t('hello')) ?>, <?= $h($me['name'] ?: '') ?> 👋</h1>
      <p><?= $h($t('home_sub')) ?></p>
    </div>
  </div>
</div>
<?php endif; ?>

<main class="wrap">
<?php if ($flash): ?><div class="flash <?= $flashType === 'err' ? 'err' : '' ?>"><?= $h($flash) ?></div><?php endif; ?>

<?php if (!$cid): ?>
  <!-- ---------- login ---------- -->
  <div class="card login">
    <div class="login-logo"><?= $h(strtoupper(substr($brand, 0, 1)) ?: 'C') ?></div>
    <h1><?= $h($t('welcome')) ?></h1>
    <p class="muted"><?= $h($t('login_sub')) ?></p>
    <form method="post">
      <input type="hidden" name="do" value="login">
      <label><?= $h($t('login_id')) ?><input name="login" autofocus></label>
      <label><?= $h($t('password')) ?><input type="password" name="password"></label>
      <button class="btn wide"><?= $h($t('login_btn')) ?></button>
    </form>
    <div class="login-hint"><span>✉️</span><?= $h($t('login_hint')) ?></div>
  </div>
<?php else: ?>
  <!-- ---------- customer home ---------- -->
  <h2><span class="sec-ic">📦</span><?= $h($t('your_orders')) ?></h2>
  <?php if (!$deals): ?><div class="empty"><?= $h($t('no_orders')) ?></div><?php endif; ?>
  <?php foreach ($deals as $d):
      $signed   = !empty($d['signed_at']) || $d['status'] === 'won';
      $lost     = $d['status'] === 'lost';
      $signable = !$signed && !$lost && $d['stage_code'] === $quoteStage;
      [$stLabel, $stClass] = order_status($t, $d, $quoteStage);
  ?>
    <div class="card order">
      <div class="order-h">
        <div>
          <b><?= $h($d['title']) ?></b>
          <div class="muted small">#<?= $h($d['id']) ?> · <?= $h($t('updated')) ?> <?= $h(date('d/m/Y', strtotime((string)$d['updated_at']))) ?></div>
        </div>
        <span class="status <?= $stClass ?>"><?= $h($stLabel) ?></span>
      </div>
      <div class="order-b">
        <div class="amt"><span class="muted small"><?= $h($t('estimate')) ?></span><br><b><?= $h($money($d['amount'], $d['currency'])) ?></b></div>
        <?php if (!empty($d['sign_due_date']) && !$signed): ?>
          <div><span class="muted small"><?= $h($t('sign_by')) ?></span><br><b><?= $h(date('d/m/Y', strtotime((string)$d['sign_due_date']))) ?></b></div>
        <?php endif; ?>
        <?php if ($signed && !empty($d['signed_at'])): ?>
          <div><span class="muted small"><?= $h($t('signed_on')) ?></span><br><b><?= $h(date('d/m/Y H:i', strtotime((string)$d['signed_at']))) ?></b></div>
        <?php endif; ?>
      </div>

      <?php if ($signable): ?>
        <?php if ($signFor === (int)$d['id']): ?>
          <form method="post" class="sign-box">
            <input type="hidden" name="do" value="sign_verify"><input type="hidden" name="deal_id" value="<?= $h($d['id']) ?>">
            <p><?= $h($t('enter_code')) ?></p>
            <input class="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required autofocus>
            <button class="btn"><?= $h($t('confirm_sign')) ?></button>
            <button class="btn ghost" name="do" value="sign_resend"><?= $h($t('resend')) ?></button>
          </form>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="do" value="sign_start"><input type="hidden" name="deal_id" value="<?= $h($d['id']) ?>">
            <button class="btn"><?= $h($t('sign_now')) ?></button>
          </form>
          <p class="muted small"><?= $h($t('sign_help')) ?></p>
        <?php endif; ?>
      <?php elseif ($signed): ?>
        <div class="ok-line">✓ <?= $h($t('order_confirmed')) ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($appts): ?>
    <h2><span class="sec-ic">📅</span><?= $h($t('your_appts')) ?></h2>
    <?php foreach ($appts as $a): $when = $a['starts_at'] ?: $a['preferred_at']; ?>
      <div class="card row-line">
        <div><b><?= $h($when ? date('D d M Y, H:i', strtotime((string)$when)) : $t('to_be_scheduled')) ?></b>
          <div class="muted small"><?= $h($a['title'] ?: $t('appointment')) ?><?= $a['location'] ? ' · ' . $h($a['location']) : '' ?></div></div>
        <span class="status <?= $a['status'] === 'confirmed' ? 'green' : 'amber' ?>"><?= $h($t('appt_' . $a['status']) !== 'appt_' . $a['status'] ? $t('appt_' . $a['status']) : $a['status']) ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2><span class="sec-ic">💬</span><?= $h($t('support')) ?></h2>
  <div class="card">
    <details>
      <summary class="newreq"><?= $h($t('tk_new')) ?></summary>
      <form method="post" style="margin-top:12px">
        <input type="hidden" name="do" value="ticket_open">
        <label><?= $h($t('tk_subject')) ?><input name="subject" maxlength="190" required></label>
        <label><?= $h($t('tk_message')) ?><textarea name="body" rows="3" required></textarea></label>
        <button class="btn"><?= $h($t('tk_send')) ?></button>
      </form>
    </details>
  </div>
  <?php foreach ($tickets as $tk): $thread = \Glue\Crm\Tickets::thread((int)$tk['id']); ?>
    <div class="card">
      <div class="order-h">
        <b><?= $h($tk['subject']) ?></b>
        <span class="status <?= $tk['status'] === 'closed' ? 'grey' : ($tk['last_sender'] === 'customer' ? 'amber' : 'blue') ?>"><?= $h($t('tkst_' . $tk['status'])) ?></span>
      </div>
      <div class="chat">
        <?php foreach ($thread as $m): $mine = $m['sender_type'] === 'customer'; ?>
          <div class="msg <?= $mine ? 'me' : 'them' ?>">
            <div><?= nl2br($h($m['body'])) ?></div>
            <div class="msg-m"><?= $h($mine ? $t('tk_you') : ($m['sender_name'] ?: $brand)) ?> · <?= $h(date('d/m H:i', strtotime((string)$m['created_at']))) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($tk['status'] !== 'closed'): ?>
        <form method="post" class="reply">
          <input type="hidden" name="do" value="ticket_reply"><input type="hidden" name="ticket_id" value="<?= $h($tk['id']) ?>">
          <input name="body" placeholder="<?= $h($t('tk_reply_ph')) ?>" required>
          <button class="btn"><?= $h($t('tk_send')) ?></button>
        </form>
      <?php else: ?>
        <p class="muted small"><?= $h($t('tk_closed_note')) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <h2><span class="sec-ic">🔒</span><?= $h($t('account')) ?></h2>
  <div class="card">
    <form method="post" class="pw">
      <input type="hidden" name="do" value="set_password">
      <label><?= $h(empty($me['password_hash']) ? $t('set_pw') : $t('change_pw')) ?>
        <input type="password" name="password" placeholder="••••••" minlength="6"></label>
      <button class="btn ghost"><?= $h($t('save')) ?></button>
    </form>
    <p class="muted small"><?= $h($t('pw_help')) ?></p>
  </div>
<?php endif; ?>
</main>
<footer class="foot muted small"><?= $h($brand) ?></footer>
</body></html>
<?php

// ============================ helpers ============================

/** The deal row if it belongs to this customer, else null. */
function owns_deal(int $cid, int $dealId): ?array
{
    if ($dealId <= 0) {
        return null;
    }
    $st = Db::pdo()->prepare('SELECT * FROM deals WHERE id = ? AND contact_id = ?');
    $st->execute([$dealId, $cid]);
    return $st->fetch() ?: null;
}

/** True if the ticket belongs to this customer. */
function owns_ticket(int $cid, int $ticketId): bool
{
    if ($ticketId <= 0) {
        return false;
    }
    $st = Db::pdo()->prepare('SELECT 1 FROM tickets WHERE id = ? AND contact_id = ?');
    $st->execute([$ticketId, $cid]);
    return (bool)$st->fetchColumn();
}

/** Customer-friendly order status: [label, css-class]. */
function order_status(callable $t, array $d, string $quoteStage): array
{
    if (!empty($d['signed_at']) || $d['status'] === 'won') {
        return [$t('st_signed'), 'green'];
    }
    if ($d['status'] === 'lost') {
        return [$t('st_closed'), 'grey'];
    }
    if ($d['stage_code'] === $quoteStage) {
        return [$t('st_awaiting'), 'amber'];
    }
    return [$t('st_progress'), 'blue'];
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function portal_strings(string $lang): array
{
    $en = [
        'portal' => 'Customer area', 'welcome' => 'Welcome', 'logout' => 'Log out',
        'login_sub' => 'Sign in with your email or phone and password.',
        'login_id' => 'Email or phone', 'password' => 'Password', 'login_btn' => 'Sign in',
        'login_err' => 'Wrong credentials, or you have not set a password yet.',
        'login_hint' => 'First time here? Open the link we sent you by WhatsApp or email.',
        'hello' => 'Hello', 'home_sub' => 'Here are your estimates and orders.',
        'your_orders' => 'Your orders', 'no_orders' => 'No orders yet.',
        'updated' => 'updated', 'estimate' => 'Estimate', 'sign_by' => 'Sign by', 'signed_on' => 'Signed on',
        'st_signed' => 'Signed', 'st_closed' => 'Closed', 'st_awaiting' => 'Awaiting your signature', 'st_progress' => 'In progress',
        'sign_now' => 'Sign the contract', 'sign_help' => 'We will send you a one-time code to confirm.',
        'enter_code' => 'Enter the code we just sent you:', 'confirm_sign' => 'Confirm & sign', 'resend' => 'Resend code',
        'otp_sent' => 'We sent you a code by WhatsApp and email.',
        'otp_ok' => 'Confirmed.', 'otp_invalid' => 'Wrong code, try again.', 'otp_expired' => 'The code expired — request a new one.',
        'otp_locked' => 'Too many attempts — request a new code.',
        'sign_done' => 'Thank you! Your contract is signed and your order is confirmed.',
        'order_confirmed' => 'Order confirmed', 'err_generic' => 'Something went wrong.',
        'your_appts' => 'Your appointments', 'to_be_scheduled' => 'To be scheduled', 'appointment' => 'Appointment',
        'appt_requested' => 'Requested', 'appt_confirmed' => 'Confirmed', 'appt_done' => 'Done',
        'appt_cancelled' => 'Cancelled', 'appt_no_show' => 'Missed',
        'account' => 'Your account', 'set_pw' => 'Set a password', 'change_pw' => 'Change password',
        'pw_help' => 'Set a password to sign in any time with your email or phone.',
        'pw_saved' => 'Password saved.', 'pw_short' => 'Password must be at least 6 characters.', 'save' => 'Save',
        'support' => 'Support', 'tk_new' => '+ New request', 'tk_subject' => 'Subject', 'tk_message' => 'Message',
        'tk_send' => 'Send', 'tk_reply_ph' => 'Write a reply…', 'tk_you' => 'You',
        'tk_opened' => 'Your request has been sent. We will get back to you.', 'tk_sent' => 'Message sent.',
        'tk_closed_note' => 'This request is closed. Open a new one if you still need help.',
        'tkst_open' => 'Open', 'tkst_pending' => 'Replied', 'tkst_closed' => 'Closed',
    ];
    $it = [
        'portal' => 'Area clienti', 'welcome' => 'Benvenuto', 'logout' => 'Esci',
        'login_sub' => 'Accedi con la tua email o telefono e la password.',
        'login_id' => 'Email o telefono', 'password' => 'Password', 'login_btn' => 'Accedi',
        'login_err' => 'Credenziali errate, oppure non hai ancora impostato una password.',
        'login_hint' => 'Prima volta qui? Apri il link che ti abbiamo inviato via WhatsApp o email.',
        'hello' => 'Ciao', 'home_sub' => 'Ecco i tuoi preventivi e ordini.',
        'your_orders' => 'I tuoi ordini', 'no_orders' => 'Ancora nessun ordine.',
        'updated' => 'aggiornato', 'estimate' => 'Preventivo', 'sign_by' => 'Firma entro', 'signed_on' => 'Firmato il',
        'st_signed' => 'Firmato', 'st_closed' => 'Chiuso', 'st_awaiting' => 'In attesa della tua firma', 'st_progress' => 'In lavorazione',
        'sign_now' => 'Firma il contratto', 'sign_help' => 'Ti invieremo un codice monouso per confermare.',
        'enter_code' => 'Inserisci il codice che ti abbiamo appena inviato:', 'confirm_sign' => 'Conferma e firma', 'resend' => 'Invia di nuovo',
        'otp_sent' => 'Ti abbiamo inviato un codice via WhatsApp ed email.',
        'otp_ok' => 'Confermato.', 'otp_invalid' => 'Codice errato, riprova.', 'otp_expired' => 'Codice scaduto — richiedine uno nuovo.',
        'otp_locked' => 'Troppi tentativi — richiedi un nuovo codice.',
        'sign_done' => 'Grazie! Il contratto è firmato e il tuo ordine è confermato.',
        'order_confirmed' => 'Ordine confermato', 'err_generic' => 'Si è verificato un errore.',
        'your_appts' => 'I tuoi appuntamenti', 'to_be_scheduled' => 'Da programmare', 'appointment' => 'Appuntamento',
        'appt_requested' => 'Richiesto', 'appt_confirmed' => 'Confermato', 'appt_done' => 'Completato',
        'appt_cancelled' => 'Annullato', 'appt_no_show' => 'Mancato',
        'account' => 'Il tuo account', 'set_pw' => 'Imposta una password', 'change_pw' => 'Cambia password',
        'pw_help' => 'Imposta una password per accedere quando vuoi con email o telefono.',
        'pw_saved' => 'Password salvata.', 'pw_short' => 'La password deve avere almeno 6 caratteri.', 'save' => 'Salva',
        'support' => 'Assistenza', 'tk_new' => '+ Nuova richiesta', 'tk_subject' => 'Oggetto', 'tk_message' => 'Messaggio',
        'tk_send' => 'Invia', 'tk_reply_ph' => 'Scrivi una risposta…', 'tk_you' => 'Tu',
        'tk_opened' => 'La tua richiesta è stata inviata. Ti risponderemo a breve.', 'tk_sent' => 'Messaggio inviato.',
        'tk_closed_note' => 'Questa richiesta è chiusa. Aprine una nuova se hai ancora bisogno.',
        'tkst_open' => 'Aperta', 'tkst_pending' => 'Risposta', 'tkst_closed' => 'Chiusa',
    ];
    return $lang === 'it' ? $it : $en;
}

function portal_css(): void { ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --accent:#5b6cff; --accent2:#7c5bff; --ink:#1c2533; --muted:#7b8494;
  --line:#e8ebf3; --card:#fff; --bg:#f3f5fb;
  --shadow:0 1px 2px rgba(28,37,51,.04),0 8px 24px -12px rgba(28,37,51,.12);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;-webkit-font-smoothing:antialiased}

/* ---- header ---- */
.top{display:flex;justify-content:space-between;align-items:center;padding:14px 24px;background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:10}
.brand{display:flex;align-items:center;gap:11px;font-size:16px}
.brand-sub{color:var(--muted);font-size:12.5px;font-weight:500;border-left:1px solid var(--line);padding-left:11px}
.logo{width:36px;height:36px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px;box-shadow:0 4px 12px -4px rgba(91,108,255,.5)}
.top-r{display:flex;align-items:center;gap:12px}
.lang{display:inline-flex;background:#eef0f6;border-radius:9px;padding:3px}
.lang a{padding:5px 11px;color:var(--muted);font-weight:700;font-size:12px;text-decoration:none;border-radius:7px;transition:.15s}
.lang a.on{background:#fff;color:var(--accent);box-shadow:0 1px 3px rgba(28,37,51,.12)}

/* ---- hero ---- */
.hero{background:var(--accent);color:#fff;padding:34px 24px 56px}
.hero-in{max-width:760px;margin:0 auto;display:flex;align-items:center;gap:18px}
.hero h1{font-size:25px;font-weight:800;letter-spacing:-.3px}
.hero p{opacity:.85;font-size:14.5px;margin-top:2px}
.avatar{width:54px;height:54px;border-radius:50%;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.45);display:flex;align-items:center;justify-content:center;font-size:23px;font-weight:800;flex-shrink:0}

/* ---- layout ---- */
.wrap{max-width:760px;margin:0 auto;padding:24px 18px 60px}
.hero + .wrap{margin-top:-30px}
h1{font-size:24px;margin-bottom:4px}
h2{font-size:16px;margin:30px 0 12px;display:flex;align-items:center;gap:9px;letter-spacing:-.2px}
h2:first-child{margin-top:0}
.sec-ic{width:30px;height:30px;border-radius:9px;background:#fff;border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;font-size:15px;box-shadow:var(--shadow)}
.muted{color:var(--muted)}.small{font-size:13px}

/* ---- cards ---- */
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px 22px;margin-bottom:14px;box-shadow:var(--shadow)}

/* ---- forms ---- */
label{display:block;margin:13px 0;font-size:13px;font-weight:600;color:#49536a}
input,textarea{width:100%;margin-top:7px;padding:12px 14px;border:1.5px solid #dde2ec;border-radius:11px;font-size:15px;outline:none;background:#fbfcfe;font-family:inherit;transition:.15s}
input:focus,textarea:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(91,108,255,.13)}
.btn{display:inline-block;margin-top:6px;padding:12px 20px;border:none;border-radius:11px;background:var(--accent);color:#fff;font-weight:700;font-size:14.5px;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px -5px rgba(91,108,255,.55);transition:.15s}
.btn:hover{transform:translateY(-1px);box-shadow:0 7px 18px -5px rgba(91,108,255,.6)}
.btn:active{transform:none}
.btn.ghost{background:#eef0f6;color:#2a3344;margin-left:8px;box-shadow:none}
.btn.ghost:hover{background:#e4e7f0;transform:none}
.btn.sm{padding:8px 14px;font-size:13px;margin:0}
.btn.wide{width:100%;margin-top:14px}

/* ---- login ---- */
.login{max-width:410px;margin:7vh auto 0;text-align:center;padding:34px 30px}
.login form{text-align:left}
.login-logo{width:58px;height:58px;border-radius:16px;margin:0 auto 16px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:26px;box-shadow:0 8px 20px -6px rgba(91,108,255,.55)}
.login h1{font-size:22px}
.login-hint{display:flex;gap:9px;align-items:flex-start;text-align:left;margin-top:18px;padding:12px 14px;background:#f6f8ff;border:1px solid #e3e8ff;border-radius:11px;font-size:13px;color:#5a6477}

/* ---- orders ---- */
.order-h{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.order-b{display:flex;gap:34px;margin:16px 0;flex-wrap:wrap}
.amt b{font-size:22px;letter-spacing:-.4px}
.status{padding:5px 13px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;letter-spacing:.2px}
.status.green{background:#e2f7ea;color:#188a4c}.status.amber{background:#fdf1d7;color:#a87908}
.status.blue{background:#e8ecff;color:#4453d6}.status.grey{background:#eef0f4;color:#7b8494}
.sign-box{background:#f5f7ff;border:1.5px dashed #b9c2f5;border-radius:13px;padding:18px;margin-top:10px;text-align:center}
.sign-box p{font-weight:600;font-size:14px;margin-bottom:10px}
.code{letter-spacing:9px;text-align:center;font-size:24px;font-weight:800;max-width:220px;margin:0 auto 6px;display:block}
.ok-line{display:inline-flex;align-items:center;gap:7px;color:#188a4c;font-weight:700;margin-top:10px;background:#e2f7ea;padding:8px 14px;border-radius:10px;font-size:14px}
.row-line{display:flex;justify-content:space-between;align-items:center;gap:12px}
.pw{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}.pw label{flex:1;min-width:200px;margin:0}

/* ---- flash / empty ---- */
.flash{background:#e2f7ea;border:1px solid #b5e6c8;color:#1a854a;padding:13px 17px;border-radius:12px;margin-bottom:18px;font-weight:600;box-shadow:var(--shadow)}
.flash.err{background:#fdeced;border-color:#f3c2c8;color:#c0394a}
.empty{color:var(--muted);text-align:center;padding:34px;background:#fff;border:1px dashed #d8dde8;border-radius:16px}
.foot{text-align:center;padding:26px;color:#a5acbb;font-size:12.5px}

/* ---- tickets / chat ---- */
.newreq{cursor:pointer;font-weight:700;color:var(--accent);font-size:14px;list-style:none}
.newreq::-webkit-details-marker{display:none}
.newreq::before{content:'';display:none}
.chat{display:flex;flex-direction:column;gap:9px;margin:14px 0;max-height:340px;overflow-y:auto;padding-right:4px}
.msg{max-width:80%;padding:10px 14px;border-radius:15px;font-size:14px;line-height:1.5}
.msg .msg-m{font-size:11px;color:#8a93a6;margin-top:5px}
.msg.me{align-self:flex-end;background:#e9ecff;border-bottom-right-radius:4px}
.msg.them{align-self:flex-start;background:#f1f3f8;border-bottom-left-radius:4px}
.reply{display:flex;gap:9px;margin-top:8px}.reply input{margin:0}.reply .btn{margin:0;flex-shrink:0}

@media (max-width:560px){
  .top{padding:12px 16px}
  .brand-sub{display:none}
  .hero{padding:26px 18px 50px}
  .hero h1{font-size:21px}
  .order-b{gap:22px}
  .msg{max-width:90%}
}
</style>
<?php }
