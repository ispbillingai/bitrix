<?php
declare(strict_types=1);

/**
 * The upload link of a financing application — "the agent can upload the list
 * partially and complete it later using the same incremental link".
 *
 * One page, no login: the link itself is the key. Every required document has
 * its own row and its own upload button; what is already in shows underneath,
 * so the seller (or the customer, if the seller forwards the link) sees at a
 * glance what is still missing and adds it whenever it arrives.
 *
 * The same page is what the seller opens from the lead, so there is one place
 * where the folder is filled, not two.
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
$S = pratica_strings($lang);
$t = fn(string $k): string => $S[$k] ?? $k;
$brand = (string)Config::get('app.company_name', '') ?: 'CRM';

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$app   = $token !== '' ? Docs::appByToken($token) : null;
if (!$app) {
    http_response_code(404);
    ?><!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $h($brand) ?></title><?php css(); ?></head>
    <body class="center"><div class="login"><div class="logo"><?= $h(strtoupper(substr($brand, 0, 1))) ?></div>
      <h1><?= $h($t('gone_h')) ?></h1><p class="muted"><?= $h($t('gone_p')) ?></p></div></body></html>
    <?php
    exit;
}
$appId  = (int)$app['id'];
$closed = $app['status'] === 'closed';

// ---- a file arrives (or one is dropped) ----
$notice = null;
if (!$closed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'upload') {
        $slot   = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['slot'] ?? ''))) ?: 'altro';
        $lender = (int)($_POST['lender_id'] ?? 0);
        $res = Docs::storeMany([
            'lead_id' => (int)$app['lead_id'], 'contact_id' => $app['contact_id'] ?? null, 'app_id' => $appId,
            'slot_code' => $slot, 'lender_id' => $lender ?: null, 'source' => 'link',
            'user_id' => null, 'user_name' => trim((string)($_POST['who'] ?? '')) ?: $t('by_link'),
        ], $_FILES['files'] ?? null);
        $notice = $res['count'] > 0
            ? ['ok' => true, 'msg' => sprintf($t('ok_uploaded'), $res['count'])]
            : ['ok' => false, 'msg' => $t('err_upload') . ($res['errors'] ? ' ' . implode('; ', array_slice($res['errors'], 0, 3)) : '')];
    } elseif ($do === 'drop') {
        $f = Docs::file((int)($_POST['file_id'] ?? 0));
        if ($f && (int)$f['app_id'] === $appId) {
            Docs::delete((int)$f['id'], null);
            $notice = ['ok' => true, 'msg' => $t('ok_removed')];
        }
    }
    $_SESSION['pratica_notice'] = $notice;
    header('Location: pratica.php?t=' . rawurlencode($token) . '&lang=' . $lang);
    exit;
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('crm_pratica');
    session_start();
}
if (!empty($_SESSION['pratica_notice'])) {
    $notice = $_SESSION['pratica_notice'];
    unset($_SESSION['pratica_notice']);
}

// ---- a file goes out ----
if (isset($_GET['dl'])) {
    $f = Docs::file((int)$_GET['dl']);
    if ($f && (int)$f['app_id'] === $appId) {
        Docs::stream($f);
    }
    http_response_code(404);
    exit('Not found');
}

$rows  = Docs::checklist($appId);
$prog  = Docs::progress($appId);
$who   = trim((string)$app['customer_name']) ?: ('#' . (int)$app['lead_id']);
$pct   = $prog['required'] > 0 ? (int)round(100 * $prog['done'] / $prog['required']) : 100;
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

<?php if ($notice): ?><div class="flash <?= empty($notice['ok']) ? 'flash-err' : '' ?>"><?= $h($notice['msg']) ?></div><?php endif; ?>

<h2><?= $h($who) ?><?= !empty($app['company']) ? ' · ' . $h($app['company']) : '' ?></h2>
<p class="lead"><?= $h($t('sub')) ?></p>

<div class="card" style="margin-bottom:16px">
  <div class="lb"><span class="nm"><?= $h($t('progress')) ?></span>
    <span class="sc"><?= (int)$prog['done'] ?>/<?= (int)$prog['required'] ?></span></div>
  <div style="height:8px;border-radius:6px;background:var(--surface2);overflow:hidden">
    <div style="height:8px;width:<?= $pct ?>%;background:var(--<?= $pct >= 100 ? 'green' : 'accent' ?>)"></div>
  </div>
  <?php if (!empty($app['amount'])): ?>
    <p class="muted small" style="margin:10px 0 0"><?= $h($t('amount')) ?>: <strong>€ <?= number_format((float)$app['amount'], 2, ',', '.') ?></strong>
      <?= !empty($app['purpose']) ? ' · ' . $h($app['purpose']) : '' ?></p>
  <?php endif; ?>
  <?php if ($closed): ?><p class="muted small" style="margin:10px 0 0"><?= $h($t('closed')) ?></p><?php endif; ?>
</div>

<?php foreach ($rows as $row): $key = $row['code'] . ':' . (int)$row['lender_id']; ?>
  <div class="cm-card" style="margin-bottom:8px">
    <div style="padding:14px 16px">
      <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap">
        <b><?= $h($row['label']) ?></b>
        <?php if ($row['lender']): ?><span class="pill"><?= $h($row['lender']) ?></span><?php endif; ?>
        <?php if ($row['required']): ?><span class="cm-st <?= $row['files'] ? 'cm-st-paid' : 'cm-st-sent' ?>"><?= $h($row['files'] ? $t('done') : $t('missing')) ?></span>
        <?php else: ?><span class="muted small"><?= $h($t('optional')) ?></span><?php endif; ?>
      </div>
      <?php foreach ($row['files'] as $f): ?>
        <div class="lb">
          <span class="nm" style="min-width:0"><a href="?t=<?= $h($token) ?>&dl=<?= (int)$f['id'] ?>">📎 <?= $h($f['name']) ?></a>
            <div class="muted small"><?= $h(\Glue\Finance\Docs::size((int)$f['size_bytes'])) ?> · <?= $h(substr((string)$f['created_at'], 0, 16)) ?><?= $f['uploader_name'] ? ' · ' . $h($f['uploader_name']) : '' ?></div></span>
          <?php if (!$closed): ?>
          <form method="post" class="inline" onsubmit="return confirm('<?= $h($t('drop_confirm')) ?>')">
            <input type="hidden" name="t" value="<?= $h($token) ?>"><input type="hidden" name="do" value="drop">
            <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
            <button class="btn tiny ghost" style="color:var(--red)"><?= $h($t('drop')) ?></button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$closed): ?>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px">
          <input type="hidden" name="t" value="<?= $h($token) ?>"><input type="hidden" name="do" value="upload">
          <input type="hidden" name="slot" value="<?= $h($row['code']) ?>">
          <input type="hidden" name="lender_id" value="<?= (int)$row['lender_id'] ?>">
          <input type="file" name="files[]" multiple required style="flex:1 1 220px;min-width:0">
          <button class="btn tiny"><?= $h($t('upload')) ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<p class="muted small"><?= $h($t('foot')) ?></p>
</div></main></div>
</body></html>
<?php

/** Bilingual copy for the upload page. */
function pratica_strings(string $lang): array
{
    $it = [
        'title'        => 'Documenti della pratica',
        'sub'          => 'Carica i documenti uno alla volta, quando li hai. Il link resta valido: puoi tornare più tardi e completare la lista.',
        'progress'     => 'Documenti obbligatori caricati',
        'amount'       => 'Importo richiesto',
        'done'         => 'caricato',
        'missing'      => 'manca',
        'optional'     => 'facoltativo',
        'upload'       => 'Carica',
        'drop'         => 'Elimina',
        'drop_confirm' => 'Eliminare questo file?',
        'ok_uploaded'  => '%d file caricati. Grazie.',
        'ok_removed'   => 'File eliminato.',
        'err_upload'   => 'Non è stato possibile caricare il file. Formati ammessi: PDF, foto, Word, Excel. Massimo 20 MB per file.',
        'closed'       => 'La pratica è chiusa: i documenti non si possono più modificare.',
        'by_link'      => 'Caricato dal link',
        'foot'         => 'I documenti restano riservati e sono visibili solo a noi e alle finanziarie a cui invieremo la pratica.',
        'gone_h'       => 'Link non valido',
        'gone_p'       => 'Questo link non è più attivo. Chiedi al tuo referente di inviartene uno nuovo.',
    ];
    if ($lang !== 'en') {
        return $it;
    }
    return [
        'title'        => 'Application documents',
        'sub'          => 'Upload the documents one at a time, as you get them. The link stays valid: come back later and finish the list.',
        'progress'     => 'Required documents uploaded',
        'amount'       => 'Amount requested',
        'done'         => 'uploaded',
        'missing'      => 'missing',
        'optional'     => 'optional',
        'upload'       => 'Upload',
        'drop'         => 'Delete',
        'drop_confirm' => 'Delete this file?',
        'ok_uploaded'  => '%d files uploaded. Thank you.',
        'ok_removed'   => 'File deleted.',
        'err_upload'   => 'The file could not be uploaded. Allowed: PDF, photos, Word, Excel. 20 MB per file.',
        'closed'       => 'This application is closed: its documents can no longer be changed.',
        'by_link'      => 'Uploaded from the link',
        'foot'         => 'The documents stay private: only we and the lenders we send the application to can see them.',
        'gone_h'       => 'Link not valid',
        'gone_p'       => 'This link is no longer active. Ask your contact to send you a new one.',
    ];
}
