<?php
declare(strict_types=1);

/**
 * "Tomorrow's schedule is planned" — the link in the 17:00 WhatsApp and email.
 *
 * No login: the technician is reading this on a phone, in the evening, and a
 * password screen between them and one tap is how a confirmation stops being
 * given. The token in the link is the credential — 48 hex characters, one
 * person, one day, and it confirms that day and nothing else.
 *
 * Clicking again is harmless: the first click stamps confirmed_at and the rest
 * just show it, which is what a person does when they are not sure it worked.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\DayPlanner;
use Glue\Reminder\Templates;

Bootstrap::init();

$row  = DayPlanner::confirm((string)($_GET['t'] ?? ''));
$lang = Templates::lang(Config::get('app.default_lang', 'it'));
$co   = (string)Config::get('app.company_name', 'CRM');

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$ok    = $row !== null;
$when  = $ok ? $h(date('d/m/Y', (int)strtotime((string)$row['plan_date']))) : '';
$who   = $ok ? $h((string)$row['name']) : '';   // a staff name, but it still goes into HTML
$title = $ok
    ? ($lang === 'it' ? 'Pianificazione confermata' : 'Schedule confirmed')
    : ($lang === 'it' ? 'Link non valido' : 'Invalid link');
$body = $ok
    ? ($lang === 'it'
        ? "Grazie {$who}: la tua pianificazione per il <b>{$when}</b> risulta completata. Non riceverai altri solleciti."
        : "Thanks {$who} — your schedule for <b>{$when}</b> is marked as planned. You will not be chased again.")
    : ($lang === 'it'
        ? 'Questo link non è valido o è scaduto. Apri il CRM e conferma la pianificazione dal calendario.'
        : 'This link is not valid any more. Open the CRM and confirm your schedule from the calendar.');

http_response_code($ok ? 200 : 404);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title) ?> — <?= $h($co) ?></title>
<style>
  :root{color-scheme:light dark;--bg:#f6f7f9;--card:#fff;--txt:#111827;--muted:#6b7280;--ok:#16a34a;--bad:#dc2626;--line:#e5e7eb}
  @media (prefers-color-scheme:dark){:root{--bg:#0f141c;--card:#161c28;--txt:#e7ecf4;--muted:#8b95a7;--line:#28303f}}
  body{margin:0;min-height:100dvh;display:flex;align-items:center;justify-content:center;
       background:var(--bg);color:var(--txt);font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:20px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:30px 26px;
        max-width:440px;width:100%;text-align:center}
  .mark{font-size:44px;line-height:1;margin-bottom:12px}
  h1{font-size:19px;margin:0 0 10px}
  p{margin:0;color:var(--muted)}
  .co{margin-top:20px;font-size:12px;color:var(--muted)}
</style></head><body>
  <div class="card">
    <div class="mark"><?= $ok ? '✅' : '⚠️' ?></div>
    <h1 style="color:<?= $ok ? 'var(--ok)' : 'var(--bad)' ?>"><?= $h($title) ?></h1>
    <p><?= $body /* built above from trusted values only */ ?></p>
    <div class="co"><?= $h($co) ?></div>
  </div>
</body></html>
