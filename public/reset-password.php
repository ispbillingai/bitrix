<?php
declare(strict_types=1);

/**
 * Set a new password from the link in the WhatsApp / email.
 *
 * No session: the token in the URL is the credential, and the person cannot log
 * in — that is the whole reason they are here. It is single-use, expires in an
 * hour, and spending it cancels every other link outstanding for the account.
 *
 * The page never names the account beyond its username, and an expired or
 * already-spent link is indistinguishable from an invented one.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\PasswordReset;
use Glue\Reminder\Templates;

Bootstrap::init();

$lang = Templates::lang(Config::get('app.default_lang', 'it'));
$it   = $lang !== 'en';
$co   = (string)Config::get('app.company_name', 'CRM');
$h    = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$row   = PasswordReset::resolve($token);
$err   = '';
$done  = false;

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = PasswordReset::complete($token, (string)($_POST['password'] ?? ''), (string)($_POST['confirm'] ?? ''));
    if ($reason === '') {
        $done = true;
    } else {
        $err = [
            'mismatch'  => $it ? 'Le due password non coincidono.' : 'The two passwords do not match.',
            'too_short' => $it
                ? 'La password deve avere almeno ' . PasswordReset::MIN_LEN . ' caratteri.'
                : 'The password must be at least ' . PasswordReset::MIN_LEN . ' characters.',
            'bad_token' => $it ? 'Questo link non è più valido.' : 'This link is no longer valid.',
        ][$reason] ?? ($it ? 'Non è stato possibile impostare la password.' : 'The password could not be set.');
        if ($reason === 'bad_token') {
            $row = null;
        }
    }
}

$loginUrl = rtrim(Config::appBaseUrl(), '/') . '/dashboard.php';
http_response_code($row || $done ? 200 : 404);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer'); // the token is in the URL — don't leak it onward
?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($it ? 'Nuova password' : 'New password') ?> — <?= $h($co) ?></title>
<style>
  :root{color-scheme:light dark;--bg:#f6f7f9;--card:#fff;--txt:#111827;--muted:#6b7280;
        --accent:#2563eb;--ok:#16a34a;--bad:#dc2626;--line:#e5e7eb}
  @media (prefers-color-scheme:dark){:root{--bg:#0f141c;--card:#161c28;--txt:#e7ecf4;--muted:#8b95a7;--line:#28303f}}
  body{margin:0;min-height:100dvh;display:flex;align-items:center;justify-content:center;background:var(--bg);
       color:var(--txt);font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:20px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:30px 26px;max-width:420px;width:100%}
  h1{font-size:19px;margin:0 0 6px}
  p{margin:0 0 14px;color:var(--muted);font-size:14px}
  label{display:block;font-size:12px;color:var(--muted);margin:12px 0 4px}
  input[type=password]{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;
       border:1px solid var(--line);background:var(--bg);color:var(--txt);font-size:15px}
  button{width:100%;margin-top:18px;padding:11px;border:0;border-radius:8px;background:var(--accent);
       color:#fff;font-size:15px;cursor:pointer}
  .err{color:var(--bad);font-size:13px;margin:10px 0 0}
  .ok{color:var(--ok)}
  .mark{font-size:40px;line-height:1;margin-bottom:10px}
  a{color:var(--accent)}
</style></head><body>
<div class="card">
<?php if ($done): ?>
  <div class="mark">✅</div>
  <h1 class="ok"><?= $h($it ? 'Password aggiornata' : 'Password updated') ?></h1>
  <p><?= $h($it
      ? 'Puoi accedere subito con la nuova password. Il link che hai usato non è più valido.'
      : 'You can sign in now with the new password. The link you used no longer works.') ?></p>
  <p><a href="<?= $h($loginUrl) ?>"><?= $h($it ? 'Vai al login' : 'Go to the login page') ?></a></p>
<?php elseif (!$row): ?>
  <div class="mark">⚠️</div>
  <h1><?= $h($it ? 'Link non valido' : 'Invalid link') ?></h1>
  <p><?= $h($it
      ? 'Questo link è scaduto, è già stato usato, oppure non è corretto. Torna al login e richiedine uno nuovo.'
      : 'This link has expired, has already been used, or is not correct. Go back to the login page and ask for a new one.') ?></p>
  <p><a href="<?= $h($loginUrl) ?>"><?= $h($it ? 'Torna al login' : 'Back to the login page') ?></a></p>
<?php else: ?>
  <h1><?= $h($it ? 'Scegli una nuova password' : 'Choose a new password') ?></h1>
  <p><?= $h(($it ? 'Account: ' : 'Account: ') . $row['username']) ?></p>
  <form method="post" autocomplete="off">
    <input type="hidden" name="t" value="<?= $h($token) ?>">
    <label for="p1"><?= $h($it ? 'Nuova password' : 'New password') ?>
      (<?= (int)PasswordReset::MIN_LEN ?>+)</label>
    <input id="p1" type="password" name="password" required minlength="<?= (int)PasswordReset::MIN_LEN ?>" autofocus>
    <label for="p2"><?= $h($it ? 'Ripeti la password' : 'Repeat the password') ?></label>
    <input id="p2" type="password" name="confirm" required minlength="<?= (int)PasswordReset::MIN_LEN ?>">
    <?php if ($err !== ''): ?><p class="err"><?= $h($err) ?></p><?php endif; ?>
    <button type="submit"><?= $h($it ? 'Imposta la password' : 'Set the password') ?></button>
  </form>
<?php endif; ?>
</div>
</body></html>
