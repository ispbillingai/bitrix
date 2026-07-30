<?php
declare(strict_types=1);

/**
 * Where SmallPay sends the customer when the cashier page closes — the
 * redirectUrl handed over when the position was created.
 *
 * This page is a courtesy, not a source of truth. Landing here means the
 * customer finished at the cashier, not that the money moved: the authority on
 * that is SmallPay, and it tells us over the §3.4 callback. So the page asks
 * SmallPay directly (a §3.2 read) before saying anything, and if that read is
 * slow or fails it says "we are checking" rather than guessing. Nobody is told
 * a payment succeeded on the strength of a URL they could have typed.
 *
 * The reference in ?ref= is our paymentId. Knowing it reveals only the status
 * and description of one contract, and the page deliberately shows no amounts,
 * no card details and no customer data beyond the first name.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Pay\Contracts;
use Glue\Pay\SmallPay;
use Glue\Reminder\Templates;

Bootstrap::init();

$company = (string)Config::get('app.company_name', '') ?: 'CRM';
$ref = trim((string)($_GET['ref'] ?? ''));
$contract = $ref !== '' ? Contracts::findByReference($ref) : null;

// Refresh from SmallPay so the page reflects the position, not our last guess.
// Best effort on purpose: a customer who has just paid should never see a stack
// trace because SmallPay was briefly slow.
if ($contract !== null && SmallPay::enabled()) {
    try {
        $contract = Contracts::sync((int)$contract['id']);
    } catch (Throwable) {
        // keep the stored row; the state below falls through to "checking"
    }
}

$lang = Templates::lang($_GET['lang'] ?? ($contract['lang'] ?? null));
$it = $lang === 'it';

// Four things a customer can be told, and only one of them is "you have paid".
$state = 'checking';
if ($contract !== null) {
    $state = match ((string)$contract['status']) {
        'active', 'completed'      => 'done',
        'failed'                   => 'refused',
        'past_due'                 => 'refused',
        'cancelled'                => 'cancelled',
        default                    => 'checking',
    };
}

$copy = [
    'done' => [
        'title' => $it ? 'Pagamento completato' : 'Payment complete',
        'body'  => $it
            ? 'Grazie. Il pagamento è andato a buon fine e il contratto è attivo. Riceverai una conferma via email o WhatsApp.'
            : 'Thank you. Your payment went through and the contract is active. You will get a confirmation by email or WhatsApp.',
    ],
    'checking' => [
        'title' => $it ? 'Stiamo verificando il pagamento' : 'We are checking your payment',
        'body'  => $it
            ? 'Abbiamo ricevuto la tua richiesta. La conferma dalla banca può richiedere qualche minuto: ti avviseremo appena arriva. Non serve ripetere il pagamento.'
            : 'We have your request. Confirmation from the bank can take a few minutes and we will let you know as soon as it arrives. There is no need to pay again.',
    ],
    'refused' => [
        'title' => $it ? 'Pagamento non riuscito' : 'Payment did not go through',
        'body'  => $it
            ? 'La banca non ha autorizzato il pagamento. Nessun importo è stato addebitato. Ti ricontattiamo noi con un nuovo link, oppure puoi rispondere a questo messaggio.'
            : 'Your bank did not authorise the payment and nothing was charged. We will send you a fresh link, or you can simply reply to our message.',
    ],
    'cancelled' => [
        'title' => $it ? 'Contratto annullato' : 'Contract cancelled',
        'body'  => $it
            ? 'Questo contratto è stato annullato e non verrà addebitato nulla.'
            : 'This contract has been cancelled and nothing further will be charged.',
    ],
][$state];

$firstName = '';
if ($contract !== null) {
    $firstName = trim(explode(' ', trim((string)($contract['customer_name'] ?? '')))[0] ?? '');
}
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $h($copy['title']) ?> — <?= $h($company) ?></title>
<style>
:root{--bg:#0e131c;--surface:#161c28;--line:#28303f;--txt:#e7ecf4;--muted:#8b95a7;
  --accent:#5b6cff;--green:#3fb868;--amber:#d9a441;--red:#e05561;}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;color:var(--txt);background:var(--bg);font-size:14px;
  line-height:1.5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:34px 30px 28px;
  max-width:470px;width:100%;text-align:center}
.mark{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  margin:0 auto 20px;border:2px solid currentColor}
.mark svg{width:26px;height:26px;fill:none;stroke:currentColor;stroke-width:2.5;
  stroke-linecap:round;stroke-linejoin:round}
.done{color:var(--green)} .checking{color:var(--amber)} .refused{color:var(--red)} .cancelled{color:var(--muted)}
h1{font-size:20px;letter-spacing:-.01em;margin-bottom:10px}
p{color:var(--muted);line-height:1.65}
.what{margin-top:22px;padding-top:18px;border-top:1px solid var(--line);color:var(--muted);font-size:13px}
.what b{color:var(--txt);font-weight:600}
.foot{margin-top:20px;color:var(--muted);font-size:12px}
</style></head>
<body>
  <div class="card">
    <div class="mark <?= $h($state) ?>">
      <?php if ($state === 'done'): ?>
        <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
      <?php elseif ($state === 'refused'): ?>
        <svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
      <?php elseif ($state === 'cancelled'): ?>
        <svg viewBox="0 0 24 24"><path d="M5 12h14"/></svg>
      <?php else: ?>
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
      <?php endif; ?>
    </div>
    <h1><?= $h($firstName !== '' ? $copy['title'] . ', ' . $firstName : $copy['title']) ?></h1>
    <p><?= $h($copy['body']) ?></p>
    <?php if ($contract !== null): ?>
      <div class="what">
        <b><?= $h($contract['description']) ?></b><br>
        <?= $h(Contracts::cadenceText($contract, $lang)) ?>
      </div>
    <?php endif; ?>
    <div class="foot"><?= $h($company) ?></div>
  </div>
</body></html>
