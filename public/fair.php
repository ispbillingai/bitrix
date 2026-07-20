<?php
declare(strict_types=1);

/**
 * Public TRADE-FAIR (fiera) request form — the same form as request.php, with the
 * fair as a reference: which fair, and in which city. Leads land in the CRM with
 * source 'fiera' plus fair_name/fair_city, so a fair's leads export in the same
 * file as every other lead.
 *
 * Share a per-fair link so the visitor never has to type it:
 *   fair.php?fair=MCE%20Milano&city=Milano
 * Both stay editable in case the page is opened bare.
 *
 * Every open is counted (form_views, key 'fair') so the office can compare the
 * link's reach with the leads it produced. Bilingual (EN/IT), honeypot anti-spam.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\FormViews;
use Glue\Crm\Leads;
use Glue\Event\Log;

Bootstrap::init();

$avail = ['en', 'it'];
$lang = in_array($_GET['lang'] ?? '', $avail, true)
    ? $_GET['lang']
    : (in_array((string)Config::get('app.default_lang', 'it'), $avail, true) ? (string)Config::get('app.default_lang', 'it') : 'it');

$T = [
    'it' => [
        'title' => 'Fiera — Richiedi informazioni', 'intro' => 'Compila il modulo e un nostro consulente ti contatterà al più presto.',
        'fair' => 'Nome della fiera', 'fair_ph' => 'es. Host Milano',
        'city' => 'Città della fiera', 'city_ph' => 'es. Milano',
        'first' => 'Nome', 'last' => 'Cognome', 'email' => 'Email', 'phone' => 'Telefono',
        'country' => 'Prefisso internazionale', 'phone_ph' => 'es. 339 1234567',
        'company' => 'Azienda', 'vat' => 'Partita IVA (facoltativa)', 'vat_ph' => 'es. 01234567890',
        'message' => 'Messaggio', 'message_ph' => 'Come possiamo aiutarti?',
        'preferred' => 'Quando preferisci essere contattato? (facoltativo)',
        'consent' => 'Ho letto e accetto l’informativa sulla privacy.',
        'send' => 'Invia richiesta', 'ok_title' => 'Richiesta inviata!',
        'ok_body' => 'Grazie! Abbiamo ricevuto la tua richiesta e ti contatteremo a breve.',
        'again' => 'Invia un’altra richiesta',
        'err_required' => 'Inserisci il tuo nome e almeno email o telefono.',
        'err_consent' => 'È necessario accettare l’informativa sulla privacy.',
    ],
    'en' => [
        'title' => 'Trade fair — Request information', 'intro' => 'Fill in the form and one of our consultants will contact you shortly.',
        'fair' => 'Fair name', 'fair_ph' => 'e.g. Host Milano',
        'city' => 'Fair city', 'city_ph' => 'e.g. Milan',
        'first' => 'First name', 'last' => 'Last name', 'email' => 'Email', 'phone' => 'Phone',
        'country' => 'Country code', 'phone_ph' => 'e.g. 339 1234567',
        'company' => 'Company', 'vat' => 'VAT number (optional)', 'vat_ph' => 'e.g. 01234567890',
        'message' => 'Message', 'message_ph' => 'How can we help you?',
        'preferred' => 'When would you prefer to be contacted? (optional)',
        'consent' => 'I have read and accept the privacy policy.',
        'send' => 'Send request', 'ok_title' => 'Request sent!',
        'ok_body' => 'Thank you! We have received your request and will contact you shortly.',
        'again' => 'Send another request',
        'err_required' => 'Please enter your name and at least an email or phone.',
        'err_consent' => 'You must accept the privacy policy.',
    ],
][$lang];

// Same country list as request.php — customers rarely type +39 themselves.
$countries = [
    '39'  => ['🇮🇹', 'Italia', 'Italy'],
    '44'  => ['🇬🇧', 'Regno Unito', 'United Kingdom'],
    '33'  => ['🇫🇷', 'Francia', 'France'],
    '49'  => ['🇩🇪', 'Germania', 'Germany'],
    '34'  => ['🇪🇸', 'Spagna', 'Spain'],
    '41'  => ['🇨🇭', 'Svizzera', 'Switzerland'],
    '43'  => ['🇦🇹', 'Austria', 'Austria'],
    '32'  => ['🇧🇪', 'Belgio', 'Belgium'],
    '31'  => ['🇳🇱', 'Paesi Bassi', 'Netherlands'],
    '351' => ['🇵🇹', 'Portogallo', 'Portugal'],
    '30'  => ['🇬🇷', 'Grecia', 'Greece'],
    '40'  => ['🇷🇴', 'Romania', 'Romania'],
    '48'  => ['🇵🇱', 'Polonia', 'Poland'],
    '355' => ['🇦🇱', 'Albania', 'Albania'],
    '1'   => ['🇺🇸', 'USA / Canada', 'USA / Canada'],
    '971' => ['🇦🇪', 'Emirati Arabi', 'UAE'],
    '212' => ['🇲🇦', 'Marocco', 'Morocco'],
    '254' => ['🇰🇪', 'Kenya', 'Kenya'],
];

$h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$company = (string)Config::get('app.company_name', (string)Config::get('mail.from_name', 'Company'));

// Fair reference: prefilled from the shared link, still editable.
$fairName = trim((string)($_POST['fair_name'] ?? ($_GET['fair'] ?? '')));
$fairCity = trim((string)($_POST['fair_city'] ?? ($_GET['city'] ?? '')));

$done = false;
$error = null;
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot: real users never fill 'website'.
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $done = true; // silently swallow bots
    } else {
        $first = trim((string)($_POST['first_name'] ?? ''));
        $last  = trim((string)($_POST['last_name'] ?? ''));
        $name  = trim("$first $last");
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $old = $_POST;

        // Prepend the chosen country prefix unless already international.
        $cc = (string)($_POST['phone_cc'] ?? '39');
        $cc = isset($countries[$cc]) ? $cc : '39';
        if ($phone !== '') {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            if (str_starts_with($phone, '+')) {
                $phone = $digits === '' ? '' : '+' . $digits;
            } elseif (str_starts_with($digits, '00')) {
                $phone = '+' . substr($digits, 2);
            } else {
                $digits = ltrim($digits, '0'); // drop a single trunk zero
                $phone = $digits === '' ? '' : '+' . $cc . $digits;
            }
        }

        if ($name === '' || ($email === '' && $phone === '')) {
            $error = $T['err_required'];
        } elseif (empty($_POST['consent'])) {
            $error = $T['err_consent'];
        } else {
            try {
                // Partner referral (?ref=CODE), same as the standard public form.
                $refCode = trim((string)($_POST['ref'] ?? ($_GET['ref'] ?? '')));
                $partner = $refCode !== '' ? \Glue\Partner\Partners::byRefCode($refCode) : null;

                // 90-day VAT exclusivity for partner entries (same rule as request.php).
                $vat = \Glue\Crm\VatLock::normalize((string)($_POST['vat_number'] ?? ''));
                $vatBlocked = false;
                $vc = ['ok' => true, 'fresh' => false];
                if ($vat !== '' && $partner) {
                    $vc = \Glue\Crm\VatLock::claim($vat, 'partner', (int)$partner['id']);
                    if (!$vc['ok']) {
                        $vatBlocked = true;
                        \Glue\Crm\VatLock::notifyTaken('partner', (int)$partner['id'], $vat, (string)$vc['available_at']);
                        if (!empty($vc['lead_id'])) {
                            \Glue\Crm\Activities::add('lead', (int)$vc['lead_id'], 'system',
                                "Blocked duplicate entry of VAT $vat via partner {$partner['name']} (locked until "
                                . date('d/m/Y', strtotime((string)$vc['available_at'])) . ')');
                        }
                        Log::write('fair_form', 'vat_blocked', 'lead', (int)($vc['lead_id'] ?? 0),
                            ['vat' => $vat, 'partner_id' => (int)$partner['id']]);
                    }
                }

                if (!$vatBlocked) {
                    $leadId = Leads::create([
                        'name'      => $name,
                        'email'     => $email,
                        'phone'     => $phone,
                        'company'   => trim((string)($_POST['company'] ?? '')),
                        'comments'  => trim((string)($_POST['message'] ?? '')),
                        'source'    => 'fiera',
                        'lang'      => $lang,
                        'vat_number' => $vat,
                        'fair_name' => $fairName,
                        'fair_city' => $fairCity,
                    ]);

                    if ($partner) {
                        \Glue\Partner\Partners::attributeLead($leadId, (int)$partner['id']);
                        if ($vat !== '' && !empty($vc['fresh'])) {
                            \Glue\Crm\VatLock::attachLead($vat, $leadId);
                            \Glue\Crm\VatLock::notifyThanks('partner', (int)$partner['id'], $vat, $name);
                        }
                    }
                }

                $preferred = trim((string)($_POST['preferred_at'] ?? ''));
                if ($preferred !== '' && !$vatBlocked) {
                    \Glue\Crm\Appointments::request([
                        'name' => $name, 'email' => $email, 'phone' => $phone,
                        'preferred_at' => $preferred, 'lead_id' => $leadId, 'lang' => $lang,
                        'title' => 'Appointment request (fair)',
                    ]);
                }
                $done = true;
            } catch (Throwable $e) {
                Log::write('fair_form', 'error', null, null, ['error' => $e->getMessage()]);
                $error = 'Unexpected error. Please try again.';
            }
        }
    }
} else {
    // Count one view of the public fair form (best-effort, GET renders only).
    FormViews::record('fair', $fairName);
}

$logoLetter = strtoupper(substr($company, 0, 1)) ?: 'C';
?>
<!DOCTYPE html>
<html lang="<?= $h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $h($T['title']) ?> — <?= $h($company) ?></title>
<style>
:root{--bg:#0e131c;--surface:#161c28;--surface2:#1c2533;--line:#28303f;--line2:#39435a;
  --txt:#e7ecf4;--muted:#8b95a7;--accent:#5b6cff;--green:#3fb868;--green-bg:rgba(63,184,104,.13);
  --red:#e5616e;--red-bg:rgba(229,97,110,.13);--radius:12px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',system-ui,sans-serif;color:var(--txt);background:var(--bg);font-size:14px;line-height:1.5;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px 16px;}
.wrap{width:100%;max-width:560px;}
.card{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:30px 30px 26px;}
.head{display:flex;align-items:center;gap:13px;margin-bottom:6px;}
.logo{width:46px;height:46px;border-radius:11px;background:var(--accent);display:flex;align-items:center;
  justify-content:center;font-weight:800;color:#fff;font-size:20px;flex:0 0 auto;}
.head h1{font-size:21px;letter-spacing:-.01em;} .head .co{color:var(--muted);font-size:13px;}
.intro{color:var(--muted);margin:14px 0 22px;line-height:1.6;}
.fairbox{background:var(--surface2);border:1px solid var(--line);border-radius:10px;padding:14px 14px 2px;margin-bottom:18px;}
.langsw{position:absolute;top:18px;right:20px;display:inline-flex;background:var(--surface2);
  border:1px solid var(--line);border-radius:8px;padding:2px;}
.langsw a{padding:4px 9px;border-radius:6px;color:var(--muted);font-weight:600;font-size:12px;text-decoration:none;}
.langsw a.on{background:var(--accent);color:#fff;}
.fld{display:block;margin-bottom:15px;} .fld span{display:block;margin-bottom:7px;color:var(--muted);font-size:13px;font-weight:500;}
.row{display:flex;gap:13px;} .row .fld{flex:1;}
input,textarea{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:9px;background:var(--bg);
  color:var(--txt);font-size:14px;outline:none;font-family:inherit;transition:border-color .12s;}
input:focus,textarea:focus,select:focus{border-color:var(--accent);}
.phonewrap{display:flex;gap:8px;}
.phonewrap select{flex:0 0 auto;max-width:150px;padding:11px 9px;border:1px solid var(--line);border-radius:9px;
  background:var(--bg);color:var(--txt);font-size:14px;outline:none;font-family:inherit;transition:border-color .12s;}
.phonewrap input{flex:1;min-width:0;}
textarea{resize:vertical;min-height:84px;}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;}
.consent{display:flex;gap:10px;align-items:flex-start;margin:6px 0 20px;color:var(--muted);font-size:13px;line-height:1.5;}
.consent input{width:auto;margin-top:2px;}
.btn{width:100%;padding:12px 18px;border:none;border-radius:9px;background:var(--accent);color:#fff;font-weight:600;
  cursor:pointer;font-size:15px;transition:filter .12s;} .btn:hover{filter:brightness(1.08);}
.err{background:var(--red-bg);border:1px solid var(--red);color:var(--red);padding:11px 14px;border-radius:9px;margin-bottom:18px;font-size:13px;}
.ok{text-align:center;padding:14px 0 4px;}
.ok .check{width:62px;height:62px;border-radius:50%;background:var(--green-bg);color:var(--green);
  display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:30px;}
.ok h2{font-size:20px;margin-bottom:8px;} .ok p{color:var(--muted);margin-bottom:20px;line-height:1.6;}
.ghost{display:inline-block;padding:10px 18px;border:1px solid var(--line);border-radius:9px;color:var(--txt);
  text-decoration:none;background:var(--surface2);font-weight:600;font-size:14px;}
.foot{text-align:center;color:var(--muted);font-size:12px;margin-top:18px;}
@media(max-width:480px){
  body{padding:16px 12px;}
  .card{padding:22px 18px 20px;border-radius:14px;}
  .row{flex-direction:column;gap:0;}
  .head h1{font-size:19px;}
  .langsw{top:14px;right:14px;}
}
</style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="wrap" style="position:relative">
  <span class="langsw">
    <a class="<?= $lang === 'en' ? 'on' : '' ?>" href="?lang=en&fair=<?= $h(urlencode($fairName)) ?>&city=<?= $h(urlencode($fairCity)) ?>">EN</a>
    <a class="<?= $lang === 'it' ? 'on' : '' ?>" href="?lang=it&fair=<?= $h(urlencode($fairName)) ?>&city=<?= $h(urlencode($fairCity)) ?>">IT</a>
  </span>
  <div class="card">
    <?php if ($done): ?>
      <div class="ok">
        <div class="check">✓</div>
        <h2><?= $h($T['ok_title']) ?></h2>
        <p><?= $h($T['ok_body']) ?></p>
        <a class="ghost" href="?lang=<?= $h($lang) ?>&fair=<?= $h(urlencode($fairName)) ?>&city=<?= $h(urlencode($fairCity)) ?>"><?= $h($T['again']) ?></a>
      </div>
    <?php else: ?>
      <div class="head">
        <div class="logo"><?= $h($logoLetter) ?></div>
        <div><h1><?= $h($T['title']) ?></h1><div class="co"><?= $h($company) ?></div></div>
      </div>
      <p class="intro"><?= $h($T['intro']) ?></p>
      <?php if ($error): ?><div class="err"><?= $h($error) ?></div><?php endif; ?>
      <form method="post">
        <div class="hp"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <?php $ref = trim((string)($_POST['ref'] ?? ($_GET['ref'] ?? ''))); if ($ref !== ''): ?>
          <input type="hidden" name="ref" value="<?= $h($ref) ?>">
        <?php endif; ?>
        <div class="fairbox">
          <div class="row">
            <label class="fld"><span><?= $h($T['fair']) ?></span><input name="fair_name" value="<?= $h($fairName) ?>" placeholder="<?= $h($T['fair_ph']) ?>"></label>
            <label class="fld"><span><?= $h($T['city']) ?></span><input name="fair_city" value="<?= $h($fairCity) ?>" placeholder="<?= $h($T['city_ph']) ?>"></label>
          </div>
        </div>
        <div class="row">
          <label class="fld"><span><?= $h($T['first']) ?></span><input name="first_name" value="<?= $h($old['first_name'] ?? '') ?>" required></label>
          <label class="fld"><span><?= $h($T['last']) ?></span><input name="last_name" value="<?= $h($old['last_name'] ?? '') ?>"></label>
        </div>
        <div class="row">
          <label class="fld"><span><?= $h($T['email']) ?></span><input type="email" name="email" value="<?= $h($old['email'] ?? '') ?>"></label>
          <div class="fld"><span><?= $h($T['phone']) ?></span>
            <div class="phonewrap">
              <select name="phone_cc" aria-label="<?= $h($T['country']) ?>">
                <?php $selCc = (string)($old['phone_cc'] ?? '39'); foreach ($countries as $dial => $c): ?>
                  <option value="<?= $h($dial) ?>" <?= $dial === $selCc ? 'selected' : '' ?>>
                    <?= $h($c[0]) ?> +<?= $h($dial) ?> <?= $h($lang === 'it' ? $c[1] : $c[2]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <input name="phone" type="tel" value="<?= $h($old['phone'] ?? '') ?>" placeholder="<?= $h($T['phone_ph']) ?>">
            </div>
          </div>
        </div>
        <div class="row">
          <label class="fld"><span><?= $h($T['company']) ?></span><input name="company" value="<?= $h($old['company'] ?? '') ?>"></label>
          <label class="fld"><span><?= $h($T['vat']) ?></span><input name="vat_number" value="<?= $h($old['vat_number'] ?? '') ?>" placeholder="<?= $h($T['vat_ph']) ?>"></label>
        </div>
        <label class="fld"><span><?= $h($T['message']) ?></span><textarea name="message" placeholder="<?= $h($T['message_ph']) ?>"><?= $h($old['message'] ?? '') ?></textarea></label>
        <label class="fld"><span><?= $h($T['preferred']) ?></span><input type="datetime-local" name="preferred_at" value="<?= $h($old['preferred_at'] ?? '') ?>"></label>
        <label class="consent"><input type="checkbox" name="consent" value="1"><span><?= $h($T['consent']) ?></span></label>
        <button class="btn" type="submit"><?= $h($T['send']) ?></button>
      </form>
    <?php endif; ?>
  </div>
  <div class="foot"><?= $h($company) ?></div>
</div>
</body>
</html>
