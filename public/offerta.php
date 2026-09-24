<?php
declare(strict_types=1);

/**
 * "Richiedi un'offerta" — the link at the bottom of the survey opinion.
 *
 * The customer has just read what their system needs. This is the one button
 * that says yes, and it pages the verification group.
 *
 * No login: the token in the link is the credential, exactly like the signing
 * pages. It is tied to one survey, and only to a survey whose opinion has
 * actually been sent — there is nothing to quote for before that.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Inspect\Inspections;
use Glue\Reminder\Templates;

Bootstrap::init();

$lang = Templates::lang(Config::get('app.default_lang', 'it'));
$it   = $lang !== 'en';
$co   = (string)Config::get('app.company_name', 'CRM');
$h    = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$r     = Inspections::byOfferToken($token);
$sent  = false;
$already = false;

if ($r && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot, like the other public forms.
    if (trim((string)($_POST['website'] ?? '')) === '') {
        $res = Inspections::requestOffer($token, (string)($_POST['note'] ?? ''));
        $sent    = (bool)$res['ok'];
        $already = (bool)$res['already'];
    } else {
        $sent = true; // a bot: say yes, record nothing
    }
} elseif ($r && !empty($r['offer_requested_at'])) {
    $already = true;
}

http_response_code($r ? 200 : 404);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($it ? 'Richiedi un’offerta' : 'Request a quote') ?> — <?= $h($co) ?></title>
<style>
  :root{color-scheme:light dark;--bg:#f6f7f9;--card:#fff;--txt:#111827;--muted:#6b7280;
        --accent:#2563eb;--ok:#16a34a;--line:#e5e7eb}
  @media (prefers-color-scheme:dark){:root{--bg:#0f141c;--card:#161c28;--txt:#e7ecf4;--muted:#8b95a7;--line:#28303f}}
  body{margin:0;min-height:100dvh;display:flex;align-items:center;justify-content:center;background:var(--bg);
       color:var(--txt);font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:20px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:28px 24px;max-width:460px;width:100%}
  h1{font-size:19px;margin:0 0 8px}
  p{margin:0 0 14px;color:var(--muted);font-size:14px}
  .stars{font-size:22px;letter-spacing:3px;margin:0 0 6px;color:var(--txt)}
  .op{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:12px 14px;
      white-space:pre-wrap;font-size:14px;color:var(--txt);margin-bottom:16px}
  label{display:block;font-size:12px;color:var(--muted);margin:0 0 4px}
  textarea{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;border:1px solid var(--line);
           background:var(--bg);color:var(--txt);font:inherit;min-height:88px}
  button{width:100%;margin-top:16px;padding:12px;border:0;border-radius:8px;background:var(--accent);
         color:#fff;font-size:15px;cursor:pointer}
  .mark{font-size:40px;line-height:1;margin-bottom:10px}
  .hp{position:absolute;left:-9999px}
</style></head><body>
<div class="card">
<?php if (!$r): ?>
  <div class="mark">⚠️</div>
  <h1><?= $h($it ? 'Link non valido' : 'Invalid link') ?></h1>
  <p><?= $h($it
      ? 'Questo link non è più valido. Rispondi al messaggio che hai ricevuto e ti richiamiamo.'
      : 'This link is no longer valid. Reply to the message you received and we will call you back.') ?></p>
<?php elseif ($sent || $already): ?>
  <div class="mark">✅</div>
  <h1 style="color:var(--ok)"><?= $h($it ? 'Richiesta ricevuta' : 'Request received') ?></h1>
  <p><?= $h($already
      ? ($it ? 'Avevamo già ricevuto la tua richiesta: i nostri tecnici la stanno valutando e ti ricontattano a breve.'
             : 'We had already received your request: our team is working on it and will be in touch shortly.')
      : ($it ? 'Grazie. La tua richiesta è arrivata ai nostri tecnici: prepareranno l’offerta e ti ricontattano a breve.'
             : 'Thank you. Your request has reached our team: they will prepare the quote and be in touch shortly.')) ?></p>
  <div class="co" style="font-size:12px;color:var(--muted)"><?= $h($co) ?></div>
<?php else: ?>
  <h1><?= $h($it ? 'Vuoi un’offerta per sistemare l’impianto?' : 'Would you like a quote to put the system right?') ?></h1>
  <p><?= $h(sprintf($it ? 'Sopralluogo del %s presso %s.' : 'Survey of %s at %s.',
        !empty($r['inspected_at']) ? date('d/m/Y', strtotime((string)$r['inspected_at'])) : '—',
        (string)($r['customer_name'] ?? ''))) ?></p>
  <?php if (!empty($r['opinion_stars'])): ?>
    <div class="stars"><?= $h(Inspections::starBar((int)$r['opinion_stars'])) ?></div>
  <?php endif; ?>
  <?php if (!empty($r['opinion_text'])): ?>
    <div class="op"><?= $h($r['opinion_text']) ?></div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="t" value="<?= $h($token) ?>">
    <div class="hp"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <label for="note"><?= $h($it ? 'Vuoi aggiungere qualcosa? (facoltativo)' : 'Anything to add? (optional)') ?></label>
    <textarea id="note" name="note" placeholder="<?= $h($it
        ? 'Es. quali lavori vi interessano, quando preferite, un orario per essere richiamati…'
        : 'e.g. which work interests you, when suits you, a good time to be called…') ?>"></textarea>
    <button type="submit"><?= $h($it ? 'Richiedi l’offerta' : 'Request the quote') ?></button>
  </form>
<?php endif; ?>
</div>
</body></html>
