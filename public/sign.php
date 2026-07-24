<?php
declare(strict_types=1);

/**
 * The signing page the customer opens from the link we send them.
 *
 * Deliberately its own page and not part of the portal: signing must work for
 * someone who has no account, has never logged in, and is reading this on a
 * phone. The link identifies the document; the one-time code identifies the
 * person. Everything they do here is written to the append-only log first.
 *
 * Self-contained: own session, own bilingual copy, own styling.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Sign\Documents;
use Glue\Sign\Signer;

Bootstrap::init();

session_name('crm_sign');
session_set_cookie_params(0, '/', '', false, true);
session_start();

$h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ---- the document, from the link token ----
$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$doc   = $token !== '' ? Documents::byToken($token) : null;

$avail = ['en', 'it'];
$lang = $doc && in_array((string)$doc['lang'], $avail, true)
    ? (string)$doc['lang']
    : (in_array(Config::get('app.default_lang', 'it'), $avail, true) ? (string)Config::get('app.default_lang', 'it') : 'en');
if (isset($_GET['lang']) && in_array($_GET['lang'], $avail, true)) {
    $lang = (string)$_GET['lang'];
}
$S = sign_strings($lang);
$t = fn(string $k): string => $S[$k] ?? $k;
$brand = (string)Config::get('mail.from_name', '') ?: (string)Config::get('app.company_name', 'CRM');

if (!$doc) {
    render_page($t, $h, $lang, $brand, null, null, null, 'gone', null);
    exit;
}

$docId   = (int)$doc['id'];
$blocked = Documents::blockedReason($doc);

// ---- downloads (the original before signing, the sealed PDF after) ----
if (isset($_GET['dl'])) {
    $which = (string)$_GET['dl'];
    if ($which === 'original') {
        $path = Documents::originalPath($doc);
        if ($path !== null) {
            Documents::markDownloaded($docId, 'original');
            Documents::stream($path, (string)$doc['orig_name']);
        }
    } elseif ($which === 'signed' && $doc['status'] === 'signed') {
        $path = Documents::signedPath($doc);
        if ($path !== null) {
            Documents::markDownloaded($docId, 'signed_copy');
            Documents::stream($path, 'firmato-' . $doc['uid'] . '.pdf');
        }
    }
    http_response_code(404);
    exit('Not found');
}

Documents::markViewed($docId);

$flash = null;
$flashType = 'err';
$step = 'review';                       // review | code | done | declined
if ($doc['status'] === 'signed') {
    $step = 'done';
} elseif ($doc['status'] === 'declined') {
    $step = 'declined';
}
if (!empty($_SESSION['sign_flash'])) {
    [$flash, $flashType] = $_SESSION['sign_flash'];
    unset($_SESSION['sign_flash']);
}
if (!empty($_SESSION['sign_step_' . $docId]) && $step === 'review') {
    $step = (string)$_SESSION['sign_step_' . $docId];
}

/** Flash + redirect, so a refresh can never repost a signing attempt. */
$prg = static function (?string $msg, string $type, int $id, ?string $step, string $token): never {
    if ($msg !== null) {
        $_SESSION['sign_flash'] = [$msg, $type];
    }
    if ($step !== null) {
        $_SESSION['sign_step_' . $id] = $step;
    }
    header('Location: sign.php?t=' . urlencode($token));
    exit;
};

// ---- actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $blocked === '') {
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'request_code') {
        $consent = !empty($_POST['consent']);
        $typed   = trim((string)($_POST['typed_name'] ?? ''));
        if (!$consent) {
            $prg($t('need_consent'), 'err', $docId, null, $token);
        }
        if (mb_strlen($typed) < 3) {
            $prg($t('need_name'), 'err', $docId, null, $token);
        }
        $_SESSION['sign_consent_' . $docId] = ['consent' => true, 'name' => $typed];

        $res = Documents::issueCode($doc);
        if (!$res['ok']) {
            $prg($t('otp_throttled'), 'err', $docId, null, $token);
        }
        $prg(str_replace('{to}', $res['sent_to'], $t('otp_sent')), 'ok', $docId, 'code', $token);
    }

    if ($do === 'resend_code') {
        $res = Documents::issueCode($doc);
        $prg($res['ok'] ? str_replace('{to}', $res['sent_to'], $t('otp_sent')) : $t('otp_throttled'),
            $res['ok'] ? 'ok' : 'err', $docId, 'code', $token);
    }

    if ($do === 'verify_code') {
        $saved = $_SESSION['sign_consent_' . $docId] ?? null;
        if (!is_array($saved)) {
            $prg($t('need_consent'), 'err', $docId, 'review', $token);
        }
        $res = Documents::signWithCode($doc, (string)($_POST['code'] ?? ''),
            true, (string)$saved['name']);

        if ($res['status'] === 'ok') {
            unset($_SESSION['sign_consent_' . $docId], $_SESSION['sign_step_' . $docId]);
            $prg($t('signed_ok'), 'ok', $docId, null, $token);
        }
        $prg($t('otp_' . $res['status']), 'err', $docId, 'code', $token);
    }

    if ($do === 'decline') {
        Documents::decline($doc, (string)($_POST['reason'] ?? ''));
        unset($_SESSION['sign_step_' . $docId]);
        $prg($t('declined_ok'), 'ok', $docId, null, $token);
    }
}

if ($blocked !== '' && $step !== 'done' && $step !== 'declined') {
    $step = 'gone';
}

render_page($t, $h, $lang, $brand, $doc, $flash, $flashType, $step, $token);


// ============================ view ============================

function render_page(callable $t, callable $h, string $lang, string $brand, ?array $doc,
                     ?string $flash, ?string $flashType, string $step, ?string $token): void
{
    $initial = mb_strtoupper(mb_substr($brand, 0, 1));
    ?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $h($t('page_title')) ?> — <?= $h($brand) ?></title>
<?php sign_styles(); ?>
</head><body>
<div class="top">
  <div class="brand"><span class="logo"><?= $h($initial) ?></span><b><?= $h($brand) ?></b></div>
  <div class="lang">
    <?php foreach (['it', 'en'] as $l): ?>
      <a class="<?= $lang === $l ? 'on' : '' ?>" href="?<?= $token ? 't=' . urlencode($token) . '&amp;' : '' ?>lang=<?= $l ?>"><?= strtoupper($l) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="wrap">
<?php if ($flash): ?><div class="flash <?= $h($flashType) ?>"><?= $h($flash) ?></div><?php endif; ?>

<?php if ($doc === null || $step === 'gone'): ?>
  <div class="card center">
    <div class="big-ic grey">🔒</div>
    <h1><?= $h($t('gone_title')) ?></h1>
    <p class="muted"><?= $h($t('gone_body')) ?></p>
  </div>

<?php elseif ($step === 'done'): ?>
  <div class="card center">
    <div class="big-ic green">✓</div>
    <h1><?= $h($t('done_title')) ?></h1>
    <p class="muted"><?= $h(str_replace('{title}', (string)$doc['title'], $t('done_body'))) ?></p>
    <p class="stamp"><?= $h($t('signed_on')) ?>
      <b><?= $h(date('d/m/Y H:i', strtotime((string)$doc['signed_at']))) ?></b></p>
    <a class="btn wide" href="?t=<?= urlencode((string)$token) ?>&amp;dl=signed"><?= $h($t('dl_signed')) ?></a>
    <a class="link" href="<?= $h(Signer::verifyUrl((string)$doc['uid'])) ?>"><?= $h($t('verify_link')) ?></a>
  </div>
  <?php sign_receipt($t, $h, $doc); ?>

<?php elseif ($step === 'declined'): ?>
  <div class="card center">
    <div class="big-ic amber">✕</div>
    <h1><?= $h($t('declined_title')) ?></h1>
    <p class="muted"><?= $h($t('declined_body')) ?></p>
  </div>

<?php elseif ($step === 'code'): ?>
  <div class="card">
    <h1><?= $h($t('code_title')) ?></h1>
    <p class="muted"><?= $h($t('code_body')) ?></p>
    <form method="post" class="codeform">
      <input type="hidden" name="t" value="<?= $h($token) ?>">
      <input type="hidden" name="do" value="verify_code">
      <input class="code" name="code" inputmode="numeric" autocomplete="one-time-code"
             pattern="[0-9]*" maxlength="6" required autofocus placeholder="000000">
      <button class="btn wide"><?= $h($t('confirm_sign')) ?></button>
    </form>
    <form method="post" class="inline">
      <input type="hidden" name="t" value="<?= $h($token) ?>">
      <button class="link-btn" name="do" value="resend_code"><?= $h($t('resend')) ?></button>
    </form>
  </div>

<?php else: ?>
  <div class="card">
    <span class="eyebrow"><?= $h($t('eyebrow')) ?></span>
    <h1><?= $h($doc['title']) ?></h1>
    <p class="muted"><?= $h(str_replace('{brand}', '', $t('review_body'))) ?></p>

    <a class="filerow" href="?t=<?= urlencode((string)$token) ?>&amp;dl=original">
      <span class="file-ic">PDF</span>
      <span class="file-t">
        <b><?= $h($doc['orig_name']) ?></b>
        <span class="muted small"><?= $h(number_format((float)$doc['orig_bytes'] / 1024, 0, ',', '.')) ?> KB · <?= $h($t('dl_original')) ?></span>
      </span>
      <span class="chev">↓</span>
    </a>

    <details class="fp">
      <summary><?= $h($t('fingerprint')) ?></summary>
      <code><?= $h(strtoupper((string)$doc['orig_sha256'])) ?></code>
      <p class="muted small"><?= $h($t('fingerprint_help')) ?></p>
    </details>

    <form method="post" class="signform">
      <input type="hidden" name="t" value="<?= $h($token) ?>">
      <input type="hidden" name="do" value="request_code">
      <label class="fld"><span><?= $h($t('f_typed_name')) ?></span>
        <input name="typed_name" required minlength="3" value="<?= $h($doc['signer_name']) ?>">
      </label>
      <label class="consent">
        <input type="checkbox" name="consent" value="1" required>
        <span><?= $h($t('consent_text')) ?></span>
      </label>
      <button class="btn wide"><?= $h($t('send_code')) ?></button>
      <p class="muted small center"><?= $h(str_replace('{to}',
          \Glue\Sign\Documents::mask((string)($doc['signer_phone'] ?: $doc['signer_email'])), $t('send_code_help'))) ?></p>
    </form>
  </div>

  <details class="decline">
    <summary><?= $h($t('decline_open')) ?></summary>
    <form method="post" class="card">
      <input type="hidden" name="t" value="<?= $h($token) ?>">
      <input type="hidden" name="do" value="decline">
      <label class="fld"><span><?= $h($t('decline_reason')) ?></span>
        <input name="reason" maxlength="200" placeholder="<?= $h($t('decline_ph')) ?>"></label>
      <button class="btn ghost"><?= $h($t('decline_confirm')) ?></button>
    </form>
  </details>
<?php endif; ?>

  <p class="foot"><?= $h($brand) ?> · <?= $h($t('foot')) ?></p>
</div>
</body></html>
<?php
}

/** After signing: the receipt block, so the evidence is visible, not just filed. */
function sign_receipt(callable $t, callable $h, array $doc): void
{
    $sig = \Glue\Sign\Documents::signature((int)$doc['id']);
    if (!$sig) {
        return;
    }
    ?>
  <div class="card">
    <span class="eyebrow"><?= $h($t('receipt')) ?></span>
    <dl class="kv">
      <dt><?= $h($t('r_signer')) ?></dt><dd><?= $h($sig['signer_name']) ?></dd>
      <dt><?= $h($t('r_method')) ?></dt><dd><?= $h($t('r_method_v')) ?> <?= $h($sig['otp_sent_to']) ?></dd>
      <dt><?= $h($t('r_when')) ?></dt><dd><?= $h(date('d/m/Y H:i:s', strtotime((string)$sig['signed_at']))) ?></dd>
      <dt><?= $h($t('r_ip')) ?></dt><dd><?= $h($sig['ip'] ?: '—') ?></dd>
      <dt><?= $h($t('r_ref')) ?></dt><dd><code><?= $h(strtoupper((string)$doc['uid'])) ?></code></dd>
    </dl>
  </div>
<?php
}

function sign_strings(string $lang): array
{
    $en = [
        'page_title'  => 'Sign the document',
        'eyebrow'     => 'Ready to sign',
        'review_body' => 'Please read the document below. When you are ready, we will send you a one-time code to confirm that it is you.',
        'dl_original' => 'tap to open',
        'fingerprint' => 'Document fingerprint (SHA-256)',
        'fingerprint_help' => 'This value identifies the exact file you are signing. It is printed on your certificate, so you can always check the two match.',
        'f_typed_name' => 'Your full name',
        'consent_text' => 'I have read the document and I am signing it electronically.',
        'send_code'   => 'Send me the code',
        'send_code_help' => 'We will send it to {to}',
        'code_title'  => 'Enter your code',
        'code_body'   => 'We sent you a 6-digit code. It is valid for 10 minutes.',
        'confirm_sign' => 'Confirm and sign',
        'resend'      => 'Send a new code',
        'done_title'  => 'Signed',
        'done_body'   => 'Thank you. "{title}" has been signed and sealed. Your signed copy is below — keep it, it contains the original document inside it.',
        'signed_on'   => 'Signed on',
        'dl_signed'   => 'Download the signed document',
        'verify_link' => 'Verify this signature',
        'receipt'     => 'Signature receipt',
        'r_signer'    => 'Signer', 'r_method' => 'Identified by', 'r_method_v' => 'one-time code sent to',
        'r_when'      => 'Signed at', 'r_ip' => 'IP address', 'r_ref' => 'Reference',
        'declined_title' => 'Declined',
        'declined_body'  => 'You declined to sign this document. We have let the sender know.',
        'declined_ok' => 'Recorded — thank you for letting us know.',
        'decline_open' => 'I do not want to sign this',
        'decline_reason' => 'Reason (optional)',
        'decline_ph'  => 'e.g. the amount is wrong',
        'decline_confirm' => 'Decline to sign',
        'gone_title'  => 'This link is no longer active',
        'gone_body'   => 'It may have expired, already been used, or been withdrawn. Please ask your contact to send it again.',
        'otp_sent'    => 'Code sent to {to}.',
        'otp_invalid' => 'That code is not right — please try again.',
        'otp_expired' => 'That code has expired. Request a new one.',
        'otp_locked'  => 'Too many attempts. Request a new code.',
        'otp_none'    => 'Request a code first.',
        'otp_blocked' => 'This document can no longer be signed.',
        'otp_no_consent' => 'Please confirm you have read the document.',
        'otp_error'   => 'Something went wrong while sealing the document. Nothing was signed — please try again.',
        'otp_throttled' => 'Too many codes requested. Please wait a little and try again.',
        'need_consent' => 'Please tick the box to confirm you have read the document.',
        'need_name'   => 'Please enter your full name.',
        'signed_ok'   => 'Signed — thank you.',
        'foot'        => 'Electronic signature',
    ];
    $it = [
        'page_title'  => 'Firma il documento',
        'eyebrow'     => 'Pronto per la firma',
        'review_body' => 'Leggi il documento qui sotto. Quando sei pronto ti inviamo un codice monouso per confermare che sei tu.',
        'dl_original' => 'tocca per aprire',
        'fingerprint' => 'Impronta del documento (SHA-256)',
        'fingerprint_help' => 'Questo valore identifica il file esatto che stai firmando. È riportato sul tuo certificato, così puoi sempre verificare che coincidano.',
        'f_typed_name' => 'Il tuo nome e cognome',
        'consent_text' => 'Ho letto il documento e lo firmo elettronicamente.',
        'send_code'   => 'Inviami il codice',
        'send_code_help' => 'Lo invieremo a {to}',
        'code_title'  => 'Inserisci il codice',
        'code_body'   => 'Ti abbiamo inviato un codice di 6 cifre. È valido per 10 minuti.',
        'confirm_sign' => 'Conferma e firma',
        'resend'      => 'Invia un nuovo codice',
        'done_title'  => 'Firmato',
        'done_body'   => 'Grazie. "{title}" è stato firmato e sigillato. La tua copia firmata è qui sotto — conservala, contiene al suo interno il documento originale.',
        'signed_on'   => 'Firmato il',
        'dl_signed'   => 'Scarica il documento firmato',
        'verify_link' => 'Verifica questa firma',
        'receipt'     => 'Ricevuta di firma',
        'r_signer'    => 'Firmatario', 'r_method' => 'Identificato con', 'r_method_v' => 'codice monouso inviato a',
        'r_when'      => 'Firmato il', 'r_ip' => 'Indirizzo IP', 'r_ref' => 'Riferimento',
        'declined_title' => 'Rifiutato',
        'declined_body'  => 'Hai rifiutato di firmare questo documento. Abbiamo avvisato il mittente.',
        'declined_ok' => 'Registrato — grazie per avercelo comunicato.',
        'decline_open' => 'Non voglio firmare',
        'decline_reason' => 'Motivo (facoltativo)',
        'decline_ph'  => 'es. l\'importo non è corretto',
        'decline_confirm' => 'Rifiuta la firma',
        'gone_title'  => 'Questo link non è più attivo',
        'gone_body'   => 'Potrebbe essere scaduto, già utilizzato o ritirato. Chiedi al tuo referente di inviartelo di nuovo.',
        'otp_sent'    => 'Codice inviato a {to}.',
        'otp_invalid' => 'Codice errato — riprova.',
        'otp_expired' => 'Il codice è scaduto. Richiedine uno nuovo.',
        'otp_locked'  => 'Troppi tentativi. Richiedi un nuovo codice.',
        'otp_none'    => 'Richiedi prima un codice.',
        'otp_blocked' => 'Questo documento non può più essere firmato.',
        'otp_no_consent' => 'Conferma di aver letto il documento.',
        'otp_error'   => 'Qualcosa è andato storto durante il sigillo. Non è stato firmato nulla — riprova.',
        'otp_throttled' => 'Troppi codici richiesti. Attendi qualche minuto e riprova.',
        'need_consent' => 'Spunta la casella per confermare di aver letto il documento.',
        'need_name'   => 'Inserisci il tuo nome e cognome.',
        'signed_ok'   => 'Firmato — grazie.',
        'foot'        => 'Firma elettronica',
    ];
    return $lang === 'en' ? $en : $it;
}

function sign_styles(): void { ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#5b6cff;--ink:#1c2533;--muted:#7b8494;--line:#e8ebf3;--bg:#f3f5fb;
  --shadow:0 1px 2px rgba(28,37,51,.04),0 8px 24px -12px rgba(28,37,51,.12);}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;-webkit-font-smoothing:antialiased}
.top{display:flex;justify-content:space-between;align-items:center;padding:14px 24px;background:#fff;border-bottom:1px solid var(--line)}
.brand{display:flex;align-items:center;gap:11px;font-size:16px}
.logo{width:36px;height:36px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px}
.lang{display:inline-flex;background:#eef0f6;border-radius:9px;padding:3px}
.lang a{padding:5px 11px;color:var(--muted);font-weight:700;font-size:12px;text-decoration:none;border-radius:7px}
.lang a.on{background:#fff;color:var(--accent)}
.wrap{max-width:560px;margin:0 auto;padding:26px 18px 60px}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px 24px 26px;margin-bottom:14px;box-shadow:var(--shadow)}
.card.center{text-align:center}
h1{font-size:22px;line-height:1.3;margin-bottom:6px;letter-spacing:-.3px}
.muted{color:var(--muted)}.small{font-size:13px}.center{text-align:center}
.eyebrow{display:inline-block;font-size:11.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--accent);margin-bottom:7px}
.big-ic{width:62px;height:62px;border-radius:50%;margin:4px auto 14px;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800}
.big-ic.green{background:#e2f7ea;color:#188a4c}.big-ic.amber{background:#fdf1d7;color:#a87908}
.big-ic.grey{background:#eef0f4;color:#7b8494}
.stamp{margin-top:10px;font-size:14px}
/* the document row */
.filerow{display:flex;align-items:center;gap:13px;margin:18px 0 12px;padding:14px 16px;border:1.5px solid #dde2ec;border-radius:13px;text-decoration:none;color:var(--ink);background:#fbfcfe}
.filerow:hover{border-color:var(--accent);background:#f7f8ff}
.file-ic{flex-shrink:0;width:42px;height:42px;border-radius:10px;background:#e8ecff;color:#4453d6;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px}
.file-t{min-width:0;flex:1;display:flex;flex-direction:column}
.file-t b{font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chev{color:var(--accent);font-weight:800;font-size:18px}
/* fingerprint */
.fp{margin-bottom:6px}
.fp summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--muted);list-style:none}
.fp summary::-webkit-details-marker{display:none}
.fp summary::before{content:'▸ ';color:var(--accent)}
.fp[open] summary::before{content:'▾ '}
.fp code,.kv code{display:block;margin:9px 0 6px;padding:11px 13px;background:#f5f7fb;border:1px solid var(--line);border-radius:10px;
  font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace;font-size:11.5px;word-break:break-all;line-height:1.7}
/* forms */
.fld{display:block;margin:16px 0 12px;font-size:13px;font-weight:600;color:#49536a}
.fld span{display:block;margin-bottom:7px}
input{width:100%;padding:12px 14px;border:1.5px solid #dde2ec;border-radius:11px;font-size:15px;outline:none;background:#fbfcfe;font-family:inherit}
input:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(91,108,255,.13)}
.consent{display:flex;gap:11px;align-items:flex-start;margin:14px 0 4px;padding:14px;background:#f6f8ff;border:1px solid #e3e8ff;border-radius:12px;font-size:13.5px;font-weight:500;cursor:pointer}
.consent input{width:19px;height:19px;flex-shrink:0;margin-top:1px;accent-color:var(--accent)}
.btn{display:inline-block;padding:13px 20px;border:none;border-radius:11px;background:var(--accent);color:#fff;font-weight:700;font-size:15px;cursor:pointer;font-family:inherit;text-decoration:none;text-align:center}
.btn:hover{filter:brightness(.96)}
.btn.wide{display:block;width:100%;margin-top:14px}
.btn.ghost{background:#eef0f6;color:#2a3344}
.code{letter-spacing:11px;text-align:center;font-size:26px;font-weight:800;padding:14px;max-width:250px;margin:18px auto 0;display:block}
.codeform{margin-bottom:6px}
.inline{text-align:center;margin-top:12px}
.link-btn{background:none;border:none;color:var(--accent);font-weight:700;font-size:13.5px;cursor:pointer;font-family:inherit;text-decoration:underline}
.link{display:inline-block;margin-top:14px;color:var(--accent);font-weight:700;font-size:13.5px;text-decoration:none}
.link:hover{text-decoration:underline}
/* receipt */
.kv{display:grid;grid-template-columns:auto 1fr;gap:9px 18px;margin-top:14px;font-size:13.5px}
.kv dt{color:var(--muted);font-weight:600}
.kv dd{font-weight:600;word-break:break-word}
.kv code{margin:0;padding:5px 9px;display:inline-block;font-size:11.5px}
/* decline */
.decline summary{cursor:pointer;text-align:center;font-size:13px;color:var(--muted);font-weight:600;list-style:none;padding:6px}
.decline summary::-webkit-details-marker{display:none}
.decline .card{margin-top:10px}
.flash{background:#e2f7ea;border:1px solid #b5e6c8;color:#1a854a;padding:13px 17px;border-radius:12px;margin-bottom:16px;font-weight:600}
.flash.err{background:#fdeced;border-color:#f3c2c8;color:#c0394a}
.foot{text-align:center;padding:22px 0 0;color:#a5acbb;font-size:12.5px}
@media (max-width:560px){.wrap{padding:18px 14px 46px}.card{padding:20px 18px 22px}h1{font-size:20px}}
</style>
<?php }
