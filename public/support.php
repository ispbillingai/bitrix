<?php
declare(strict_types=1);

/**
 * Public assistance-request form — crm.../support.php, the client's spec:
 * VAT number as the FIRST field (it is how we find the customer in the
 * registry), the customer's details, and the number to call back on — which
 * must be reachable on WhatsApp, +39 unless they say otherwise.
 *
 * Two steps when the customer has no support cover: the form, then the choice —
 * activate the Helpdesk contract (EUR 9.90/month, paid at once through
 * SmallPay, priority handling) or continue without, in which case the request
 * still goes through but is handled during business hours. Covered customers
 * skip the choice entirely.
 *
 * No login: the VAT + typed details identify the requester, exactly like the
 * paper world this replaces. Honeypot against bots, like request.php.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\Contacts;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Portal\AssistRequests;

Bootstrap::init();

$avail = ['en', 'it'];
$lang = in_array($_GET['lang'] ?? '', $avail, true)
    ? $_GET['lang']
    : (in_array((string)Config::get('app.default_lang', 'it'), $avail, true) ? (string)Config::get('app.default_lang', 'it') : 'it');

$T = [
    'it' => [
        'title' => 'Richiedi assistenza', 'intro' => 'Compila il modulo: la richiesta arriva subito ai nostri tecnici.',
        'vat' => 'Partita IVA', 'vat_ph' => 'es. 01234567890',
        'name' => 'Nome e cognome / Ragione sociale',
        'country' => 'Prefisso', 'phone' => 'Telefono per il ricontatto', 'phone_ph' => 'es. 339 1234567',
        'phone_h' => 'Il numero deve essere raggiungibile su WhatsApp.',
        'subject' => 'Oggetto', 'subject_ph' => 'es. La cassa non accetta banconote',
        'message' => 'Descrivi il problema', 'message_ph' => 'Cosa succede? Da quando?',
        'consent' => 'Ho letto e accetto l’informativa sulla privacy.',
        'send' => 'Invia richiesta',
        'err_required' => 'Compila Partita IVA, nome, telefono e descrizione del problema.',
        'err_consent' => 'È necessario accettare l’informativa sulla privacy.',
        'choice_t' => 'Un’ultima cosa',
        'choice_none' => 'Per questa Partita IVA non risulta un contratto di assistenza attivo.',
        'choice_offer' => 'Con il contratto {desc} ({price}/mese) la tua richiesta viene gestita in via prioritaria. Si attiva ora, pagando con carta in modo sicuro tramite SmallPay, e puoi disdirlo quando vuoi.',
        'choice_activate' => 'Attiva {desc} — {price}/mese e invia con priorità',
        'choice_skip' => 'Prosegui senza contratto',
        'choice_skip_h' => 'La richiesta sarà comunque gestita, in orario lavorativo.',
        'ok_t' => 'Richiesta inviata!',
        'ok_priority' => 'Grazie! La tua richiesta è arrivata ai nostri tecnici: uno di loro la prenderà in carico e ti ricontatterà a breve.',
        'ok_hours' => 'Grazie! La tua richiesta è stata registrata e sarà gestita in orario lavorativo. Un tecnico ti ricontatterà.',
        'ok_pay_failed' => 'Non è stato possibile avviare il pagamento del contratto: la richiesta è stata comunque registrata (gestione in orario lavorativo) e ti contatteremo per l’attivazione.',
        'ok_pay' => 'Ora completa il pagamento del contratto: appena confermato, la richiesta parte con priorità.',
        'pay_btn' => 'Vai al pagamento',
        'again' => 'Invia un’altra richiesta',
    ],
    'en' => [
        'title' => 'Request assistance', 'intro' => 'Fill in the form — your request reaches our technicians right away.',
        'vat' => 'VAT number', 'vat_ph' => 'e.g. 01234567890',
        'name' => 'Full name / Company name',
        'country' => 'Code', 'phone' => 'Callback phone number', 'phone_ph' => 'e.g. 339 1234567',
        'phone_h' => 'The number must be reachable on WhatsApp.',
        'subject' => 'Subject', 'subject_ph' => 'e.g. The machine refuses banknotes',
        'message' => 'Describe the problem', 'message_ph' => 'What happens? Since when?',
        'consent' => 'I have read and accept the privacy policy.',
        'send' => 'Send request',
        'err_required' => 'Please fill in VAT number, name, phone and a description of the problem.',
        'err_consent' => 'You must accept the privacy policy.',
        'choice_t' => 'One last thing',
        'choice_none' => 'This VAT number has no active support contract.',
        'choice_offer' => 'With the {desc} contract ({price}/month) your request is handled with priority. It activates now — you pay securely by card through SmallPay — and you can cancel any time.',
        'choice_activate' => 'Activate {desc} — {price}/month and send with priority',
        'choice_skip' => 'Continue without a contract',
        'choice_skip_h' => 'Your request will still be handled, during business hours.',
        'ok_t' => 'Request sent!',
        'ok_priority' => 'Thank you! Your request has reached our technicians: one of them will take charge and contact you shortly.',
        'ok_hours' => 'Thank you! Your request has been recorded and will be handled during business hours. A technician will contact you.',
        'ok_pay_failed' => 'The contract payment could not be started: your request was recorded anyway (business-hours handling) and we will contact you about the activation.',
        'ok_pay' => 'Now complete the contract payment — as soon as it is confirmed, your request goes out with priority.',
        'pay_btn' => 'Go to payment',
        'again' => 'Send another request',
    ],
][$lang];

// Same country list as request.php: customers rarely type +39 themselves.
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
    '40'  => ['🇷🇴', 'Romania', 'Romania'],
    '48'  => ['🇵🇱', 'Polonia', 'Poland'],
    '355' => ['🇦🇱', 'Albania', 'Albania'],
    '1'   => ['🇺🇸', 'USA / Canada', 'USA / Canada'],
];

$h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$company = (string)Config::get('app.company_name', (string)Config::get('mail.from_name', 'Company'));
$money = fn(int $cents): string => 'EUR ' . number_format($cents / 100, 2, ',', '.');

/** The registry row for a VAT, preferring a flagged customer when several share it. */
function support_contact_by_vat(string $vat): ?array
{
    if ($vat === '') {
        return null;
    }
    $stmt = Db::pdo()->prepare(
        'SELECT * FROM contacts WHERE vat_number = ? ORDER BY is_customer DESC, id ASC LIMIT 1'
    );
    $stmt->execute([$vat]);
    return $stmt->fetch() ?: null;
}

$step   = 'form';   // form | choice | pay | done
$error  = null;
$old    = [];
$okKey  = 'ok_priority';
$payUrl = '';
$offer  = AssistRequests::offer();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim((string)($_POST['website'] ?? '')) !== '') { // honeypot
        $step = 'done';
        $okKey = 'ok_priority';
    } else {
        $old   = $_POST;
        $vat   = \Glue\Crm\VatLock::normalize((string)($_POST['vat_number'] ?? ''));
        $name  = trim((string)($_POST['name'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body  = trim((string)($_POST['message'] ?? ''));
        // Prefix logic identical to request.php: +/00 respected, else the
        // chosen country code (Italy by default) is prepended.
        $phoneRaw = trim((string)($_POST['phone'] ?? ''));
        $cc = (string)($_POST['phone_cc'] ?? '39');
        $cc = isset($countries[$cc]) ? $cc : '39';
        $phone = '';
        if ($phoneRaw !== '') {
            $phone = str_starts_with($phoneRaw, '+') || str_starts_with(preg_replace('/\D+/', '', $phoneRaw) ?? '', '00')
                ? Notifier::normalizePhone($phoneRaw)
                : Notifier::normalizePhone('+' . $cc . ltrim(preg_replace('/\D+/', '', $phoneRaw) ?? '', '0'));
        }

        if ($vat === '' || $name === '' || $phone === '' || $body === '') {
            $error = $T['err_required'];
        } elseif (empty($_POST['consent'])) {
            $error = $T['err_consent'];
        } else {
            try {
                $contact = support_contact_by_vat($vat);
                $cid = $contact ? (int)$contact['id'] : 0;
                if ($cid === 0) {
                    // Unknown VAT: file the request anyway on a fresh contact —
                    // staff sorts identity out, the customer is not turned away.
                    $cid = Contacts::findOrCreate(['name' => $name, 'phone' => $phone, 'lang' => $lang]);
                    Db::pdo()->prepare(
                        'UPDATE contacts SET vat_number = COALESCE(vat_number, ?) WHERE id = ?'
                    )->execute([$vat, $cid]);
                }
                $covered = AssistRequests::cover($cid)['covered'];
                $choice  = (string)($_POST['support_choice'] ?? '');

                if (!$covered && $offer !== null && $choice === '') {
                    // Step 2: the decision. The typed data rides along hidden.
                    $step = 'choice';
                } else {
                    $res = AssistRequests::submit($cid, $subject, $body, null, [
                        'choice' => $choice === 'activate' ? 'activate' : 'skip',
                        'phone'  => $phone, 'vat' => $vat, 'source' => 'public',
                    ]);
                    if ($res['status'] === 'awaiting_payment') {
                        $payUrl = (string)($res['pay_url'] ?? '');
                        $step = $payUrl !== '' ? 'pay' : 'done';
                        $okKey = $payUrl !== '' ? 'ok_pay' : 'ok_pay_failed';
                    } else {
                        $step = 'done';
                        $okKey = !empty($res['pay_failed']) ? 'ok_pay_failed'
                            : ($covered ? 'ok_priority' : ($choice === 'activate' ? 'ok_priority' : 'ok_hours'));
                    }
                }
            } catch (Throwable $e) {
                Log::write('support_form', 'error', null, null, ['error' => $e->getMessage()]);
                $error = 'Unexpected error. Please try again.';
            }
        }
    }
}

$logoLetter = strtoupper(substr($company, 0, 1)) ?: 'C';
$price = $offer !== null ? $money($offer['amount_cents']) : '';
$desc  = $offer !== null ? $offer['description'] : '';
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
  --amber:#e0a52e;--red:#e5616e;--red-bg:rgba(229,97,110,.13);--radius:12px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',system-ui,sans-serif;color:var(--txt);background:var(--bg);font-size:14px;line-height:1.5;
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px 16px;}
.wrap{width:100%;max-width:560px;}
.card{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:30px 30px 26px;}
.head{display:flex;align-items:center;gap:13px;margin-bottom:6px;}
.logo{width:46px;height:46px;border-radius:11px;background:var(--accent);display:flex;align-items:center;
  justify-content:center;font-weight:800;font-size:20px;color:#fff;}
h1{font-size:19px;margin:0;}
.sub{color:var(--muted);margin:2px 0 20px;}
label{display:block;margin-bottom:13px;font-weight:600;font-size:13px;}
input,select,textarea{width:100%;margin-top:5px;padding:10px 12px;border-radius:10px;border:1px solid var(--line2);
  background:var(--surface2);color:var(--txt);font:inherit;}
textarea{resize:vertical;min-height:90px;}
small{display:block;color:var(--muted);font-weight:400;margin-top:4px;}
.row2{display:grid;grid-template-columns:150px 1fr;gap:10px;}
.btn{display:block;width:100%;padding:12px;border:0;border-radius:10px;background:var(--accent);color:#fff;
  font-weight:700;font-size:15px;cursor:pointer;text-align:center;text-decoration:none;}
.btn.ghost{background:var(--surface2);border:1px solid var(--line2);color:var(--txt);font-weight:600;}
.btn+.btn{margin-top:10px;}
.err{background:var(--red-bg);color:var(--red);border:1px solid var(--red);border-radius:10px;padding:10px 13px;margin-bottom:14px;}
.ok-ic{width:58px;height:58px;border-radius:50%;background:var(--green-bg);color:var(--green);
  display:flex;align-items:center;justify-content:center;font-size:26px;margin:6px auto 14px;}
.center{text-align:center;}
.notice{background:var(--surface2);border:1px solid var(--amber);border-radius:10px;padding:12px 14px;margin-bottom:16px;}
.notice b{color:var(--amber);}
.lang{float:right;font-size:12px;}
.lang a{color:var(--muted);text-decoration:none;font-weight:700;margin-left:8px;}
.lang a.on{color:var(--accent);}
.consent{display:flex;gap:9px;align-items:flex-start;font-weight:400;}
.consent input{width:auto;margin-top:3px;}
</style>
</head>
<body>
<div class="wrap"><div class="card">
  <span class="lang">
    <a class="<?= $lang === 'it' ? 'on' : '' ?>" href="?lang=it">IT</a>
    <a class="<?= $lang === 'en' ? 'on' : '' ?>" href="?lang=en">EN</a>
  </span>
  <div class="head"><div class="logo"><?= $h($logoLetter) ?></div>
    <div><h1><?= $h($T['title']) ?></h1></div></div>

<?php if ($step === 'done' || $step === 'pay'): ?>
  <div class="center">
    <div class="ok-ic">✓</div>
    <h2 style="margin-bottom:8px"><?= $h($T['ok_t']) ?></h2>
    <p class="sub" style="margin-bottom:18px"><?= $h($T[$okKey]) ?></p>
    <?php if ($step === 'pay'): ?>
      <a class="btn" href="<?= $h($payUrl) ?>"><?= $h($T['pay_btn']) ?></a>
    <?php endif; ?>
    <a class="btn ghost" href="support.php?lang=<?= $h($lang) ?>"><?= $h($T['again']) ?></a>
  </div>

<?php elseif ($step === 'choice'): ?>
  <p class="sub"><?= $h($T['choice_t']) ?></p>
  <div class="notice">
    <b><?= $h($T['choice_none']) ?></b>
    <p style="margin-top:6px"><?= $h(str_replace(['{desc}', '{price}'], [$desc, $price], $T['choice_offer'])) ?></p>
    <?php if ($offer !== null && trim((string)$offer['features']) !== ''): ?>
      <p style="margin-top:8px;padding-top:8px;border-top:1px solid var(--line2)">🎧 <?= $h($offer['features']) ?></p>
    <?php endif; ?>
  </div>
  <form method="post">
    <?php foreach (['vat_number', 'name', 'phone', 'phone_cc', 'subject', 'message'] as $f): ?>
      <input type="hidden" name="<?= $h($f) ?>" value="<?= $h($old[$f] ?? '') ?>">
    <?php endforeach; ?>
    <input type="hidden" name="consent" value="1">
    <button class="btn" name="support_choice" value="activate">
      <?= $h(str_replace(['{desc}', '{price}'], [$desc, $price], $T['choice_activate'])) ?></button>
    <button class="btn ghost" name="support_choice" value="skip"><?= $h($T['choice_skip']) ?></button>
    <small class="center" style="margin-top:8px"><?= $h($T['choice_skip_h']) ?></small>
  </form>

<?php else: ?>
  <p class="sub"><?= $h($T['intro']) ?></p>
  <?php if ($error): ?><div class="err"><?= $h($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
    <label><?= $h($T['vat']) ?>
      <input name="vat_number" required placeholder="<?= $h($T['vat_ph']) ?>" value="<?= $h($old['vat_number'] ?? '') ?>"></label>
    <label><?= $h($T['name']) ?>
      <input name="name" required value="<?= $h($old['name'] ?? '') ?>"></label>
    <div class="row2">
      <label><?= $h($T['country']) ?>
        <select name="phone_cc">
          <?php foreach ($countries as $dial => [$flag, $it, $en]): ?>
            <option value="<?= $h($dial) ?>" <?= ($old['phone_cc'] ?? '39') === (string)$dial ? 'selected' : '' ?>>
              <?= $h($flag) ?> +<?= $h($dial) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label><?= $h($T['phone']) ?>
        <input name="phone" required inputmode="tel" placeholder="<?= $h($T['phone_ph']) ?>" value="<?= $h($old['phone'] ?? '') ?>">
        <small><?= $h($T['phone_h']) ?></small></label>
    </div>
    <label><?= $h($T['subject']) ?>
      <input name="subject" maxlength="190" placeholder="<?= $h($T['subject_ph']) ?>" value="<?= $h($old['subject'] ?? '') ?>"></label>
    <label><?= $h($T['message']) ?>
      <textarea name="message" required placeholder="<?= $h($T['message_ph']) ?>"><?= $h($old['message'] ?? '') ?></textarea></label>
    <label class="consent"><input type="checkbox" name="consent" value="1" <?= !empty($old['consent']) ? 'checked' : '' ?>>
      <span><?= $h($T['consent']) ?></span></label>
    <button class="btn"><?= $h($T['send']) ?></button>
  </form>
<?php endif; ?>
</div></div>
</body></html>
