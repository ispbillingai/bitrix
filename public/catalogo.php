<?php
declare(strict_types=1);

/**
 * A price list as a public catalogue — photo, code, description and PRICE —
 * for whoever holds the link (migration 075).
 *
 * The office asked for two separate links on a list: this one and the
 * installation gallery (galleria.php?l=…), because they are two different
 * decisions. A gallery can go to anybody; this carries prices, so it is minted
 * deliberately, can be re-minted to kill an address that travelled too far, and
 * can be taken away. A list the office has unpublished answers nothing here
 * either — see PriceLists::byLinkToken().
 *
 * No login: the token IS the credential. It shows this list and nothing else —
 * no stock figures, no cost, no margin, no customer. The page serves its own
 * images, so the photos stay outside the web root.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\ArticleMedia;
use Glue\Crm\PriceLists;
use Glue\Reminder\Templates;

Bootstrap::init();

$lang = Templates::lang(Config::get('app.default_lang', 'it'));
$it   = $lang !== 'en';
$co   = (string)Config::get('app.company_name', 'CRM');
$h    = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$eur  = static fn(float $n): string => '€ ' . number_format($n, 2, ',', '.');

$list = PriceLists::byLinkToken('catalog', (string)($_GET['t'] ?? ''));
if (!$list) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    exit('<!DOCTYPE html><meta charset="utf-8"><title>404</title>'
        . '<p style="font:15px system-ui;padding:24px">'
        . ($it ? 'Questo link non è più valido.' : 'This link is no longer valid.') . '</p>');
}

$token = (string)$list['catalog_token'];
$items = PriceLists::allItems((int)$list['id']);
$ids   = array_column($items, 'id');
$covers = ArticleMedia::byIds(array_filter(array_column($items, 'cover_id')));
$shots  = ArticleMedia::installCounts($ids);

// ---- one photo: only the covers this page actually shows ----
if (isset($_GET['p'])) {
    $wanted = (int)$_GET['p'];
    if (isset($covers[$wanted])) {
        header('X-Robots-Tag: noindex, nofollow');
        ArticleMedia::stream($covers[$wanted], ($_GET['s'] ?? '') === 't');
    }
    http_response_code(404);
    exit('Not found');
}

// Grouped the way the catalogue and the PDF read: by category, uncategorised last.
$byCat = [];
foreach ($items as $r) {
    $byCat[trim((string)$r['category'])][] = $r;
}
$vatIn = (int)$list['vat_included'] === 1;

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $h($list['name']) ?> — <?= $h($co) ?></title>
<style>
  :root{color-scheme:light dark;--bg:#f6f7f9;--card:#fff;--txt:#111827;--muted:#6b7280;
        --accent:#2563eb;--green:#16a34a;--amber:#b45309;--line:#e5e7eb}
  @media (prefers-color-scheme:dark){:root{--bg:#0f141c;--card:#161c28;--txt:#e7ecf4;--muted:#8b95a7;
        --accent:#7c93ff;--green:#4ade80;--amber:#fbbf24;--line:#28303f}}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);
       font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
  header{background:var(--card);border-bottom:1px solid var(--line);padding:18px 16px}
  .wrap{max-width:1080px;margin:0 auto;padding:0 16px}
  header .wrap{padding:0}
  .co{color:var(--muted);font-size:12.5px;text-transform:uppercase;letter-spacing:.08em}
  h1{margin:4px 0 2px;font-size:21px;line-height:1.25}
  .sub{color:var(--muted);font-size:13px}
  main{padding:10px 0 40px}
  h2{font-size:15px;margin:26px 0 0;padding-bottom:6px;border-bottom:1px solid var(--line);
     text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  .grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));margin-top:14px}
  .it{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px;display:flex;
      flex-direction:column;gap:8px}
  .ph{aspect-ratio:4/3;border-radius:9px;overflow:hidden;background:#fff;display:flex;align-items:center;justify-content:center}
  .ph img{width:100%;height:100%;object-fit:contain}
  .ph.empty{color:var(--muted);font-size:12px;background:transparent;border:1px dashed var(--line)}
  .cd{color:var(--muted);font-size:11.5px;letter-spacing:.04em}
  .nm{font-weight:600;line-height:1.35;flex:1}
  .pr{font-size:17px;font-weight:700}
  .pr span{font-size:11.5px;font-weight:400;color:var(--muted)}
  .pr.req{font-size:14px;font-weight:600;color:var(--muted)}
  .av{font-size:11.5px}
  .av.in_stock{color:var(--green)} .av.on_order{color:var(--amber)} .av.none{color:var(--muted)}
  .gal{font-size:12.5px}
  .gal a{color:var(--accent);text-decoration:none}
  .gal a:hover{text-decoration:underline}
  footer{color:var(--muted);font-size:12.5px;padding:0 0 30px;line-height:1.7}
  @media (max-width:560px){.grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}h1{font-size:18px}}
</style></head><body>
<header><div class="wrap">
  <div class="co"><?= $h($co) ?></div>
  <h1><?= $h($list['name']) ?></h1>
  <div class="sub"><?= $h(sprintf($it ? '%d prodotti' : '%d products', count($items))) ?>
    · <?= $h(PriceLists::versionLabel($list, $lang)) ?>
    · <?= $h($it ? ($vatIn ? 'prezzi IVA inclusa' : 'prezzi IVA esclusa')
                 : ($vatIn ? 'prices include VAT' : 'prices exclude VAT')) ?></div>
  <?php if (trim((string)($list['description'] ?? '')) !== ''): ?>
    <p class="sub" style="margin:8px 0 0;white-space:pre-line"><?= $h($list['description']) ?></p>
  <?php endif; ?>
</div></header>

<main class="wrap">
<?php if (!$items): ?>
  <p class="sub" style="margin-top:20px"><?= $h($it ? 'Questo listino non ha ancora prodotti.' : 'This list has no products yet.') ?></p>
<?php endif; ?>
<?php foreach ($byCat as $cat => $rows): ?>
  <?php if ($cat !== '' || count($byCat) > 1): ?>
    <h2><?= $h($cat !== '' ? $cat : ($it ? 'Altri prodotti' : 'Other products')) ?></h2>
  <?php endif; ?>
  <div class="grid">
    <?php foreach ($rows as $r):
        $price = PriceLists::shownPrice($r, $list);
        $cover = (int)($r['cover_id'] ?? 0);
        $av    = PriceLists::availability($r);
        $nShot = (int)($shots[(int)$r['id']] ?? 0);
        $gTok  = trim((string)($r['gallery_token'] ?? ''));
    ?>
      <div class="it">
        <div class="ph<?= $cover ? '' : ' empty' ?>">
          <?php if ($cover): ?>
            <img src="?t=<?= $h($token) ?>&amp;p=<?= $cover ?>&amp;s=t" alt="<?= $h($r['description']) ?>" loading="lazy">
          <?php else: ?><span><?= $h($it ? 'nessuna foto' : 'no photo') ?></span><?php endif; ?>
        </div>
        <div class="cd"><?= $h($r['code']) ?></div>
        <div class="nm"><?= $h($r['description'] ?: $r['code']) ?></div>
        <?php if ($price > 0): ?>
          <div class="pr"><?= $h($eur($price)) ?>
            <span><?= $h($it ? ($vatIn ? 'IVA inclusa' : '+ IVA') : ($vatIn ? 'VAT incl.' : '+ VAT')) ?></span></div>
        <?php else: ?>
          <div class="pr req"><?= $h($it ? 'Prezzo su richiesta' : 'Price on request') ?></div>
        <?php endif; ?>
        <div class="av <?= $h($av) ?>"><?= $h($it
            ? ['in_stock' => 'Disponibile', 'on_order' => 'In arrivo', 'none' => 'Su ordinazione'][$av]
            : ['in_stock' => 'In stock', 'on_order' => 'On order', 'none' => 'To order'][$av]) ?></div>
        <?php if ($nShot > 0 && $gTok !== ''): ?>
          <div class="gal"><a href="galleria.php?t=<?= $h($gTok) ?>" target="_blank" rel="noopener">
            <?= $h(sprintf($it ? 'Foto installazioni (%d)' : 'Installation photos (%d)', $nShot)) ?></a></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
</main>

<footer class="wrap">
  <?= $h($co) ?><br>
  <?= $h($it
      ? 'Prezzi indicativi, salvo venduto e salvo errori. Per un preventivo contattaci.'
      : 'Prices are indicative, subject to availability and to error. Contact us for a quotation.') ?>
</footer>
</body></html>
