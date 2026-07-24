<?php
declare(strict_types=1);

/**
 * Public verification — the page a counterparty, an auditor or a court-appointed
 * expert opens to check a signature we issued.
 *
 * Two ways in, and the second is the one that matters: drop the signed PDF in
 * and it is checked against the certificate embedded in the file itself. That
 * path does not consult our database at all, so a verdict of "valid" does not
 * depend on trusting us — which is exactly the objection an in-house signing
 * system has to answer.
 *
 * Looking a reference up additionally reports what our records say, including
 * whether the operation log's hash chain is still intact.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Sign\Audit;
use Glue\Sign\Documents;
use Glue\Sign\Verify;

Bootstrap::init();

$h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$avail = ['en', 'it'];
$lang = in_array($_GET['lang'] ?? '', $avail, true)
    ? (string)$_GET['lang']
    : (in_array(Config::get('app.default_lang', 'it'), $avail, true) ? (string)Config::get('app.default_lang', 'it') : 'en');
$S = verify_strings($lang);
$t = fn(string $k): string => $S[$k] ?? $k;
$brand = (string)Config::get('mail.from_name', '') ?: (string)Config::get('app.company_name', 'CRM');

$report = null;   // full report from our records
$fileOnly = null; // cryptographic result for an uploaded file
$notFound = false;
$ref = trim((string)($_GET['c'] ?? $_POST['c'] ?? ''));

// ---- a file was dropped in: check it on its own terms first ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['pdf']['tmp_name'])
    && (int)$_FILES['pdf']['error'] === UPLOAD_ERR_OK && is_uploaded_file((string)$_FILES['pdf']['tmp_name'])) {

    $bytes = (string)file_get_contents((string)$_FILES['pdf']['tmp_name'], false, null, 0, 25 * 1024 * 1024);
    $fileOnly = Verify::signedPdf($bytes) + ['sha256' => hash('sha256', $bytes)];

    // If we happen to know this file, show our records alongside.
    $stmt = \Glue\Db::pdo()->prepare(
        'SELECT * FROM sign_documents WHERE signed_sha256 = ? OR orig_sha256 = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$fileOnly['sha256'], $fileOnly['sha256']]);
    $doc = $stmt->fetch();
    if ($doc) {
        $report = Verify::document($doc);
        $ref = (string)$doc['uid'];
    }
} elseif ($ref !== '') {
    $doc = Documents::byUid($ref);
    if ($doc) {
        $report = Verify::document($doc);
    } else {
        $notFound = true;
    }
}

$initial = mb_strtoupper(mb_substr($brand, 0, 1));
?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $h($t('page_title')) ?> — <?= $h($brand) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#5b6cff;--ink:#1c2533;--muted:#7b8494;--line:#e8ebf3;--bg:#f3f5fb;
  --ok:#188a4c;--bad:#c0394a;--shadow:0 1px 2px rgba(28,37,51,.04),0 8px 24px -12px rgba(28,37,51,.12);}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;-webkit-font-smoothing:antialiased}
.top{display:flex;justify-content:space-between;align-items:center;padding:14px 24px;background:#fff;border-bottom:1px solid var(--line)}
.brand{display:flex;align-items:center;gap:11px;font-size:16px}
.logo{width:36px;height:36px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px}
.lang{display:inline-flex;background:#eef0f6;border-radius:9px;padding:3px}
.lang a{padding:5px 11px;color:var(--muted);font-weight:700;font-size:12px;text-decoration:none;border-radius:7px}
.lang a.on{background:#fff;color:var(--accent)}
.wrap{max-width:680px;margin:0 auto;padding:26px 18px 60px}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px 24px;margin-bottom:14px;box-shadow:var(--shadow)}
h1{font-size:23px;margin-bottom:6px;letter-spacing:-.3px}
h2{font-size:15px;margin-bottom:12px}
.muted{color:var(--muted)}.small{font-size:13px}
.eyebrow{display:inline-block;font-size:11.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--accent);margin-bottom:7px}
.verdict{display:flex;align-items:center;gap:15px;padding:20px 22px;border-radius:16px;margin-bottom:14px;box-shadow:var(--shadow)}
.verdict.ok{background:#e2f7ea;border:1px solid #b5e6c8}
.verdict.bad{background:#fdeced;border:1px solid #f3c2c8}
.verdict .ic{width:50px;height:50px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:#fff}
.verdict.ok .ic{background:var(--ok)}.verdict.bad .ic{background:var(--bad)}
.verdict b{display:block;font-size:17px}
.verdict.ok b{color:#12683a}.verdict.bad b{color:#9d2d3c}
.verdict span{font-size:13.5px;color:#4a5568}
.checks{list-style:none;display:flex;flex-direction:column;gap:2px}
.checks li{display:flex;gap:11px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--line);font-size:14px}
.checks li:last-child{border-bottom:none}
.checks .mark{flex-shrink:0;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;margin-top:1px}
.checks .mark.y{background:var(--ok)}.checks .mark.n{background:var(--bad)}
.checks .txt b{display:block;font-size:13.5px}
.checks .txt span{color:var(--muted);font-size:13px}
.kv{display:grid;grid-template-columns:auto 1fr;gap:9px 18px;font-size:13.5px}
.kv dt{color:var(--muted);font-weight:600;white-space:nowrap}
.kv dd{font-weight:600;word-break:break-word}
code{font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace;font-size:11.5px;word-break:break-all;
  background:#f5f7fb;border:1px solid var(--line);border-radius:8px;padding:3px 7px;display:inline-block;line-height:1.6}
.trail{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:6px}
.trail th{text-align:left;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px;padding:7px 8px;border-bottom:1px solid var(--line)}
.trail td{padding:8px;border-bottom:1px solid #f1f3f8;vertical-align:top}
.trail tr:last-child td{border-bottom:none}
.trail .mono{font-family:ui-monospace,Menlo,Consolas,monospace;color:var(--muted);font-size:11px}
.scroll{overflow-x:auto}
.fld{display:block;margin:14px 0;font-size:13px;font-weight:600;color:#49536a}
.fld span{display:block;margin-bottom:7px}
input[type=text],input[type=file]{width:100%;padding:12px 14px;border:1.5px solid #dde2ec;border-radius:11px;font-size:15px;outline:none;background:#fbfcfe;font-family:inherit}
input[type=text]:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(91,108,255,.13)}
.btn{display:block;width:100%;margin-top:12px;padding:13px 20px;border:none;border-radius:11px;background:var(--accent);color:#fff;font-weight:700;font-size:15px;cursor:pointer;font-family:inherit}
.btn:hover{filter:brightness(.96)}
.or{text-align:center;color:var(--muted);font-size:12px;font-weight:700;letter-spacing:.6px;margin:18px 0 4px}
.warn{background:#fdf6e3;border:1px solid #f0dfae;color:#8a6400;padding:13px 16px;border-radius:12px;font-size:13.5px;margin-bottom:14px}
.foot{text-align:center;padding:22px 0 0;color:#a5acbb;font-size:12.5px}
@media (max-width:560px){.wrap{padding:18px 14px 46px}.card{padding:18px}.kv{grid-template-columns:1fr;gap:3px 0}.kv dd{margin-bottom:9px}}
</style>
</head><body>
<div class="top">
  <div class="brand"><span class="logo"><?= $h($initial) ?></span><b><?= $h($brand) ?></b></div>
  <div class="lang">
    <?php foreach (['it', 'en'] as $l): ?>
      <a class="<?= $lang === $l ? 'on' : '' ?>" href="?<?= $ref ? 'c=' . urlencode($ref) . '&amp;' : '' ?>lang=<?= $l ?>"><?= strtoupper($l) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="wrap">

<?php if ($fileOnly !== null): ?>
  <div class="verdict <?= $fileOnly['ok'] ? 'ok' : 'bad' ?>">
    <div class="ic"><?= $fileOnly['ok'] ? '✓' : '!' ?></div>
    <div>
      <b><?= $h($fileOnly['ok'] ? $t('file_ok') : $t('file_bad')) ?></b>
      <span><?= $h($fileOnly['detail']) ?></span>
    </div>
  </div>
  <div class="card">
    <span class="eyebrow"><?= $h($t('from_file')) ?></span>
    <h2><?= $h($t('file_h')) ?></h2>
    <dl class="kv">
      <dt><?= $h($t('f_sha')) ?></dt><dd><code><?= $h(strtoupper($fileOnly['sha256'])) ?></code></dd>
      <?php if (!empty($fileOnly['signer'])): ?>
        <dt><?= $h($t('f_cert')) ?></dt><dd><?= $h($fileOnly['signer']['subject']) ?></dd>
        <dt><?= $h($t('f_issuer')) ?></dt><dd><?= $h($fileOnly['signer']['issuer']) ?></dd>
        <dt><?= $h($t('f_serial')) ?></dt><dd><code><?= $h($fileOnly['signer']['serial']) ?></code></dd>
        <dt><?= $h($t('f_signed_at')) ?></dt><dd><?= $h($fileOnly['signed_at'] ?: '—') ?></dd>
        <dt><?= $h($t('f_coverage')) ?></dt>
        <dd><?= $h($fileOnly['covers_all'] ? $t('v_covers_all') : $t('v_covers_part')) ?></dd>
      <?php endif; ?>
    </dl>
    <?php if (!empty($fileOnly['signer']['self_signed'])): ?>
      <p class="warn" style="margin-top:14px"><?= $h($t('self_signed_note')) ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($notFound): ?>
  <div class="verdict bad">
    <div class="ic">?</div>
    <div><b><?= $h($t('nf_title')) ?></b><span><?= $h($t('nf_body')) ?></span></div>
  </div>
<?php endif; ?>

<?php if ($report !== null): $doc = $report['document']; $sig = $report['signature']; ?>
  <?php if ($fileOnly === null): ?>
    <?php if ($report['pending']): ?>
      <div class="verdict" style="background:#fdf6e3;border:1px solid #f0dfae">
        <div class="ic" style="background:#c9a227">…</div>
        <div><b style="color:#8a6400"><?= $h($t('rec_pending')) ?></b>
          <span><?= $h($t('rec_pending_sub')) ?></span></div>
      </div>
    <?php else: ?>
      <div class="verdict <?= $report['ok'] ? 'ok' : 'bad' ?>">
        <div class="ic"><?= $report['ok'] ? '✓' : '!' ?></div>
        <div>
          <b><?= $h($report['ok'] ? $t('rec_ok') : $t('rec_bad')) ?></b>
          <span><?= $h($report['ok'] ? $t('rec_ok_sub') : $t('rec_bad_sub')) ?></span>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="card">
    <span class="eyebrow"><?= $h($t('our_records')) ?></span>
    <h2><?= $h($doc['title']) ?></h2>
    <dl class="kv">
      <dt><?= $h($t('f_ref')) ?></dt><dd><code><?= $h(strtoupper((string)$doc['uid'])) ?></code></dd>
      <dt><?= $h($t('f_status')) ?></dt><dd><?= $h($t('st_' . $doc['status']) ?: $doc['status']) ?></dd>
      <dt><?= $h($t('f_file')) ?></dt><dd><?= $h($doc['orig_name']) ?></dd>
      <dt><?= $h($t('f_orig_sha')) ?></dt><dd><code><?= $h(strtoupper((string)$doc['orig_sha256'])) ?></code></dd>
      <?php if ($sig): ?>
        <dt><?= $h($t('f_signer')) ?></dt><dd><?= $h($sig['signer_name']) ?></dd>
        <dt><?= $h($t('f_identified')) ?></dt>
        <dd><?= $h($t('v_otp')) ?> <?= $h($sig['otp_sent_to']) ?></dd>
        <dt><?= $h($t('f_signed_at')) ?></dt>
        <dd><?= $h(date('d/m/Y H:i:s', strtotime((string)$sig['signed_at']))) ?></dd>
        <dt><?= $h($t('f_ip')) ?></dt><dd><?= $h($sig['ip'] ?: '—') ?></dd>
      <?php endif; ?>
      <?php if (!empty($doc['tsa_time'])): ?>
        <dt><?= $h($t('f_tsa')) ?></dt>
        <dd><?= $h(date('d/m/Y H:i:s', strtotime((string)$doc['tsa_time']))) ?> — <?= $h($doc['tsa_url']) ?></dd>
      <?php endif; ?>
    </dl>
  </div>

  <div class="card">
    <h2><?= $h($t('checks')) ?></h2>
    <ul class="checks">
      <?php foreach ($report['checks'] as $c): ?>
        <li>
          <span class="mark <?= $c['ok'] ? 'y' : 'n' ?>"><?= $c['ok'] ? '✓' : '!' ?></span>
          <span class="txt"><b><?= $h($t('chk_' . $c['key']) ?: $c['key']) ?></b><span><?= $h($c['detail']) ?></span></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php $trail = Audit::forDocument((int)$doc['id']); if ($trail): ?>
  <div class="card">
    <h2><?= $h($t('trail')) ?></h2>
    <p class="muted small"><?= $h($t('trail_help')) ?></p>
    <div class="scroll">
      <table class="trail">
        <thead><tr>
          <th>#</th><th><?= $h($t('c_when')) ?></th><th><?= $h($t('c_event')) ?></th>
          <th><?= $h($t('c_actor')) ?></th><th><?= $h($t('c_hash')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($trail as $r): ?>
          <tr>
            <td class="mono"><?= $h($r['seq']) ?></td>
            <td class="mono"><?= $h(substr((string)$r['occurred_at'], 0, 19)) ?></td>
            <td><?= $h($t('ev_' . $r['event']) ?: $r['event']) ?></td>
            <td><?= $h(trim((string)($r['actor_label'] ?? '')) ?: (string)$r['actor_type']) ?></td>
            <td class="mono"><?= $h(strtoupper(substr((string)$r['hash'], 0, 16))) ?>…</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<div class="card">
  <h2><?= $h($report === null && $fileOnly === null ? $t('lookup_h') : $t('lookup_again')) ?></h2>
  <p class="muted small"><?= $h($t('lookup_help')) ?></p>
  <form method="get">
    <label class="fld"><span><?= $h($t('f_ref')) ?></span>
      <input type="text" name="c" value="<?= $h($ref) ?>" placeholder="<?= $h($t('ref_ph')) ?>" maxlength="32"></label>
    <button class="btn"><?= $h($t('lookup_btn')) ?></button>
  </form>
  <p class="or"><?= $h($t('or')) ?></p>
  <form method="post" enctype="multipart/form-data">
    <label class="fld"><span><?= $h($t('f_upload')) ?></span>
      <input type="file" name="pdf" accept="application/pdf,.pdf" required></label>
    <button class="btn"><?= $h($t('upload_btn')) ?></button>
  </form>
  <p class="muted small" style="margin-top:12px"><?= $h($t('upload_help')) ?></p>
</div>

<p class="foot"><?= $h($brand) ?> · <?= $h($t('foot')) ?></p>
</div>
</body></html>
<?php

function verify_strings(string $lang): array
{
    $en = [
        'page_title' => 'Verify a signature',
        'lookup_h'   => 'Verify a signature',
        'lookup_again' => 'Check another document',
        'lookup_help' => 'Enter the reference printed on the certificate, or upload the signed PDF.',
        'ref_ph'     => '32-character reference',
        'lookup_btn' => 'Look it up',
        'or'         => 'OR',
        'f_upload'   => 'The signed PDF',
        'upload_btn' => 'Check this file',
        'upload_help' => 'Checking a file uses only what is inside it — the certificate it was signed with travels in the file, so this works even without our records.',
        'from_file'  => 'From the file itself',
        'file_h'     => 'Cryptographic check',
        'file_ok'    => 'The signature is valid',
        'file_bad'   => 'The signature could not be validated',
        'f_sha'      => 'File SHA-256', 'f_cert' => 'Signed with', 'f_issuer' => 'Issued by',
        'f_serial'   => 'Certificate serial', 'f_signed_at' => 'Signing time', 'f_coverage' => 'Coverage',
        'v_covers_all' => 'the signature covers the whole file',
        'v_covers_part' => 'part of the file is outside the signature — content was added after signing',
        'self_signed_note' => 'This signature was made with a certificate issued by the signing system itself. It proves the file has not changed since it was sealed, but it is not backed by an external certification authority.',
        'our_records' => 'Our records',
        'rec_ok'     => 'Everything checks out',
        'rec_ok_sub' => 'The document, the signature and the operation log are all intact.',
        'rec_bad'    => 'Something does not check out',
        'rec_bad_sub' => 'One or more checks failed — see below.',
        'rec_pending' => 'Not signed yet',
        'rec_pending_sub' => 'This document exists and its record is intact, but nobody has signed it.',
        'f_ref'      => 'Reference', 'f_status' => 'Status', 'f_file' => 'Original file',
        'f_orig_sha' => 'Original SHA-256', 'f_signer' => 'Signed by', 'f_identified' => 'Identified by',
        'f_ip'       => 'IP address', 'f_tsa' => 'Time stamp',
        'v_otp'      => 'one-time code sent to',
        'st_draft'   => 'Draft', 'st_sent' => 'Awaiting signature', 'st_viewed' => 'Opened by the signer',
        'st_signed'  => 'Signed', 'st_declined' => 'Declined', 'st_expired' => 'Expired', 'st_void' => 'Withdrawn',
        'checks'     => 'Checks',
        'chk_original_present' => 'The original file', 'chk_original_intact' => 'The original file',
        'chk_sealed' => 'Sealed document', 'chk_sealed_present' => 'Sealed document',
        'chk_sealed_intact' => 'The sealed PDF', 'chk_signature_valid' => 'Cryptographic signature',
        'chk_covers_whole_file' => 'Signature coverage', 'chk_log_chain' => 'Operation log',
        'chk_evidence_intact' => 'Signing evidence',
        'trail'      => 'Operation log',
        'trail_help' => 'Each entry carries the hash of the one before it. Changing, inserting or deleting an entry breaks the chain.',
        'c_when'     => 'When', 'c_event' => 'Event', 'c_actor' => 'Actor', 'c_hash' => 'Entry hash',
        'ev_document_created' => 'Document uploaded', 'ev_sent_to_signer' => 'Sent to the signer',
        'ev_opened_by_signer' => 'Opened by the signer', 'ev_downloaded_original' => 'Original downloaded',
        'ev_downloaded_signed_copy' => 'Signed copy downloaded',
        'ev_otp_issued' => 'One-time code sent', 'ev_otp_verified' => 'Code verified',
        'ev_otp_wrong' => 'Wrong code entered', 'ev_otp_expired' => 'Code expired',
        'ev_otp_throttled' => 'Code request throttled',
        'ev_signature_recorded' => 'Signature recorded', 'ev_document_sealed' => 'Document sealed',
        'ev_declined_by_signer' => 'Declined by the signer', 'ev_voided_by_staff' => 'Withdrawn',
        'ev_seal_failed' => 'Sealing failed',
        'nf_title'   => 'No document with that reference',
        'nf_body'    => 'Check the reference on the certificate — it is 32 characters long.',
        'foot'       => 'Signature verification',
    ];
    $it = [
        'page_title' => 'Verifica una firma',
        'lookup_h'   => 'Verifica una firma',
        'lookup_again' => 'Verifica un altro documento',
        'lookup_help' => 'Inserisci il riferimento riportato sul certificato, oppure carica il PDF firmato.',
        'ref_ph'     => 'riferimento di 32 caratteri',
        'lookup_btn' => 'Cerca',
        'or'         => 'OPPURE',
        'f_upload'   => 'Il PDF firmato',
        'upload_btn' => 'Verifica questo file',
        'upload_help' => 'La verifica di un file usa solo ciò che il file contiene — il certificato con cui è stato firmato viaggia dentro il file, quindi funziona anche senza i nostri archivi.',
        'from_file'  => 'Dal file stesso',
        'file_h'     => 'Verifica crittografica',
        'file_ok'    => 'La firma è valida',
        'file_bad'   => 'Non è stato possibile validare la firma',
        'f_sha'      => 'SHA-256 del file', 'f_cert' => 'Firmato con', 'f_issuer' => 'Emesso da',
        'f_serial'   => 'Serie del certificato', 'f_signed_at' => 'Ora della firma', 'f_coverage' => 'Copertura',
        'v_covers_all' => 'la firma copre l\'intero file',
        'v_covers_part' => 'parte del file è fuori dalla firma — è stato aggiunto contenuto dopo la firma',
        'self_signed_note' => 'Questa firma è stata apposta con un certificato emesso dal sistema di firma stesso. Dimostra che il file non è cambiato dopo il sigillo, ma non è garantita da un\'autorità di certificazione esterna.',
        'our_records' => 'I nostri archivi',
        'rec_ok'     => 'Tutti i controlli superati',
        'rec_ok_sub' => 'Il documento, la firma e il registro delle operazioni sono integri.',
        'rec_bad'    => 'Qualcosa non torna',
        'rec_bad_sub' => 'Uno o più controlli non sono stati superati — vedi sotto.',
        'rec_pending' => 'Non ancora firmato',
        'rec_pending_sub' => 'Questo documento esiste e la sua registrazione è integra, ma nessuno lo ha ancora firmato.',
        'f_ref'      => 'Riferimento', 'f_status' => 'Stato', 'f_file' => 'File originale',
        'f_orig_sha' => 'SHA-256 originale', 'f_signer' => 'Firmato da', 'f_identified' => 'Identificato con',
        'f_ip'       => 'Indirizzo IP', 'f_tsa' => 'Marca temporale',
        'v_otp'      => 'codice monouso inviato a',
        'st_draft'   => 'Bozza', 'st_sent' => 'In attesa di firma', 'st_viewed' => 'Aperto dal firmatario',
        'st_signed'  => 'Firmato', 'st_declined' => 'Rifiutato', 'st_expired' => 'Scaduto', 'st_void' => 'Ritirato',
        'checks'     => 'Controlli',
        'chk_original_present' => 'Il file originale', 'chk_original_intact' => 'Il file originale',
        'chk_sealed' => 'Documento sigillato', 'chk_sealed_present' => 'Documento sigillato',
        'chk_sealed_intact' => 'Il PDF sigillato', 'chk_signature_valid' => 'Firma crittografica',
        'chk_covers_whole_file' => 'Copertura della firma', 'chk_log_chain' => 'Registro delle operazioni',
        'chk_evidence_intact' => 'Prove della firma',
        'trail'      => 'Registro delle operazioni',
        'trail_help' => 'Ogni voce contiene l\'hash della precedente. Modificare, inserire o cancellare una voce spezza la catena.',
        'c_when'     => 'Quando', 'c_event' => 'Evento', 'c_actor' => 'Attore', 'c_hash' => 'Hash della voce',
        'ev_document_created' => 'Documento caricato', 'ev_sent_to_signer' => 'Inviato al firmatario',
        'ev_opened_by_signer' => 'Aperto dal firmatario', 'ev_downloaded_original' => 'Originale scaricato',
        'ev_downloaded_signed_copy' => 'Copia firmata scaricata',
        'ev_otp_issued' => 'Codice monouso inviato', 'ev_otp_verified' => 'Codice verificato',
        'ev_otp_wrong' => 'Codice errato inserito', 'ev_otp_expired' => 'Codice scaduto',
        'ev_otp_throttled' => 'Richiesta codice limitata',
        'ev_signature_recorded' => 'Firma registrata', 'ev_document_sealed' => 'Documento sigillato',
        'ev_declined_by_signer' => 'Rifiutato dal firmatario', 'ev_voided_by_staff' => 'Ritirato',
        'ev_seal_failed' => 'Sigillo non riuscito',
        'nf_title'   => 'Nessun documento con quel riferimento',
        'nf_body'    => 'Controlla il riferimento sul certificato — è lungo 32 caratteri.',
        'foot'       => 'Verifica della firma',
    ];
    return $lang === 'en' ? $en : $it;
}
