<?php
declare(strict_types=1);

/**
 * The lender's link — "a button to forward the application to the lender,
 * generating a link that can be sent to one or more financing institutions.
 * The documents are identical for all lenders, with the exception of the
 * privacy document, which varies for each one."
 *
 * One read-only page per lender: the customer's file, every document of the
 * application, and that lender's privacy form only. Another lender's privacy
 * form is never shown here. Opens are counted, and the office can revoke the
 * link at any time.
 */
require __DIR__ . '/../src/Bootstrap.php';
require_once __DIR__ . '/../views/_ui.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Finance\Docs;

Bootstrap::init();

$h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$avail = ['it', 'en'];
$lang = in_array($_GET['lang'] ?? '', $avail, true)
    ? (string)$_GET['lang']
    : (in_array(Config::get('app.default_lang', 'it'), $avail, true) ? (string)Config::get('app.default_lang', 'it') : 'it');
$S = lender_strings($lang);
$t = fn(string $k): string => $S[$k] ?? $k;
$brand = (string)Config::get('app.company_name', '') ?: 'CRM';

$token = trim((string)($_GET['t'] ?? ''));
$share = $token !== '' ? Docs::shareByToken($token) : null;
$app   = $share ? Docs::app((int)$share['app_id']) : null;
if (!$share || !$app) {
    http_response_code(404);
    ?><!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $h($brand) ?></title><?php css(); ?></head>
    <body class="center"><div class="login"><div class="logo"><?= $h(strtoupper(substr($brand, 0, 1))) ?></div>
      <h1><?= $h($t('gone_h')) ?></h1><p class="muted"><?= $h($t('gone_p')) ?></p></div></body></html>
    <?php
    exit;
}
$files = Docs::dossier($share);

// ---- one document, or the lot ----
if (isset($_GET['dl'])) {
    $id = (int)$_GET['dl'];
    foreach ($files as $f) {
        if ((int)$f['id'] === $id) {
            Docs::touchShare((int)$share['id']);
            Docs::stream($f);
        }
    }
    http_response_code(404);
    exit('Not found');
}
if (isset($_GET['zip'])) {
    $zip = Docs::zip($share);
    if ($zip !== null) {
        Docs::touchShare((int)$share['id']);
        $name = preg_replace('/[^\w\-]+/u', '_', (string)$app['customer_name']) . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string)filesize($zip));
        readfile($zip);
        @unlink($zip);
        exit;
    }
    http_response_code(404);
    exit('Not found');
}
Docs::touchShare((int)$share['id']);

$who = trim((string)$app['customer_name']) ?: ('#' . (int)$app['lead_id']);
?><!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($brand) ?> — <?= $h($t('title')) ?></title><?php css(); ?></head>
<body>
<div class="shell" style="grid-template-columns:1fr">
  <main>
    <header class="topbar">
      <div class="crumb"><?= $h($t('title')) ?></div>
      <div class="actions">
        <span class="langsw">
          <a class="<?= $lang === 'it' ? 'on' : '' ?>" href="?t=<?= $h($token) ?>&lang=it">IT</a>
          <a class="<?= $lang === 'en' ? 'on' : '' ?>" href="?t=<?= $h($token) ?>&lang=en">EN</a>
        </span>
        <span class="muted small who"><?= $h($brand) ?></span>
      </div>
    </header>
    <div class="content">

<h2><?= $h($who) ?><?= !empty($app['company']) ? ' · ' . $h($app['company']) : '' ?></h2>
<p class="lead"><?= $h(sprintf($t('sub'), (string)$share['lender_name'], $brand)) ?></p>

<div class="card" style="margin-bottom:16px">
  <dl class="cm-kv">
    <dt><?= $h($t('customer')) ?></dt><dd><?= $h($who) ?></dd>
    <?php if (!empty($app['company'])): ?><dt><?= $h($t('company')) ?></dt><dd><?= $h($app['company']) ?></dd><?php endif; ?>
    <?php if (!empty($app['vat_number'])): ?><dt><?= $h($t('vat')) ?></dt><dd><?= $h($app['vat_number']) ?></dd><?php endif; ?>
    <?php if (!empty($app['customer_phone'])): ?><dt><?= $h($t('phone')) ?></dt><dd><?= $h($app['customer_phone']) ?></dd><?php endif; ?>
    <?php if (!empty($app['customer_email'])): ?><dt><?= $h($t('email')) ?></dt><dd><?= $h($app['customer_email']) ?></dd><?php endif; ?>
    <?php if (!empty($app['amount'])): ?><dt><?= $h($t('amount')) ?></dt><dd><strong>€ <?= number_format((float)$app['amount'], 2, ',', '.') ?></strong>
      <?= !empty($app['purpose']) ? ' · ' . $h($app['purpose']) : '' ?></dd><?php endif; ?>
    <?php if (!empty($app['notes'])): ?><dt><?= $h($t('notes')) ?></dt><dd><?= nl2br($h($app['notes'])) ?></dd><?php endif; ?>
  </dl>
  <?php // Offered only where the server can actually build one (ext-zip).
        if (count($files) > 1 && class_exists(ZipArchive::class)): ?>
    <a class="btn" href="?t=<?= $h($token) ?>&zip=1">⬇ <?= $h($t('zip')) ?></a>
  <?php endif; ?>
</div>

<h3><?= $h($t('documents')) ?> · <?= count($files) ?></h3>
<?php if (!$files): ?>
  <div class="card"><div class="empty"><?= $h($t('none')) ?></div></div>
<?php else: ?>
  <div class="card">
    <?php foreach ($files as $f): $slot = (string)($f['slot_code'] ?? ''); ?>
      <div class="lb">
        <span class="nm" style="min-width:0">
          <a href="?t=<?= $h($token) ?>&dl=<?= (int)$f['id'] ?>">📎 <?= $h($f['name']) ?></a>
          <div class="muted small">
            <?= $h($slot !== '' ? Docs::typeLabel($slot) : $t('general')) ?>
            <?= (int)$f['lender_id'] > 0 ? ' · ' . $h((string)$share['lender_name']) : '' ?>
            · <?= $h(Docs::size((int)$f['size_bytes'])) ?> · <?= $h(substr((string)$f['created_at'], 0, 10)) ?>
          </div>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<p class="muted small" style="margin-top:16px"><?= $h(sprintf($t('foot'), $brand)) ?></p>
</div></main></div>
</body></html>
<?php

/** Bilingual copy for the lender page. */
function lender_strings(string $lang): array
{
    $it = [
        'title'     => 'Pratica di finanziamento',
        'sub'       => 'Documentazione per %s, trasmessa da %s.',
        'customer'  => 'Cliente', 'company' => 'Azienda', 'vat' => 'Partita IVA',
        'phone'     => 'Telefono', 'email' => 'Email', 'amount' => 'Importo richiesto', 'notes' => 'Note',
        'documents' => 'Documenti', 'general' => 'Documento del cliente',
        'zip'       => 'Scarica tutto (ZIP)',
        'none'      => 'Nessun documento in questa pratica.',
        'foot'      => 'Documentazione riservata, trasmessa da %s per la valutazione di questa pratica. Il link può essere disattivato in qualsiasi momento.',
        'gone_h'    => 'Link non valido',
        'gone_p'    => 'Questo link non è più attivo. Contatta il nostro ufficio per riceverne uno nuovo.',
    ];
    if ($lang !== 'en') {
        return $it;
    }
    return [
        'title'     => 'Financing application',
        'sub'       => 'Paperwork for %s, sent by %s.',
        'customer'  => 'Customer', 'company' => 'Company', 'vat' => 'VAT number',
        'phone'     => 'Phone', 'email' => 'Email', 'amount' => 'Amount requested', 'notes' => 'Notes',
        'documents' => 'Documents', 'general' => 'Customer document',
        'zip'       => 'Download all (ZIP)',
        'none'      => 'No documents in this application.',
        'foot'      => 'Confidential paperwork, sent by %s for the assessment of this application. The link can be switched off at any time.',
        'gone_h'    => 'Link not valid',
        'gone_p'    => 'This link is no longer active. Contact our office for a new one.',
    ];
}
