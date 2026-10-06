<?php
declare(strict_types=1);

/**
 * Installation photos on one public page — either a single product's, or every
 * product of a whole price list, grouped by product.
 *
 *   ?t=<token>   one product   (migration 074)
 *   ?l=<token>   a whole list  (migration 075: "mi fai un link galleria anche
 *                              per l'intero listino")
 *
 * No login: the token in the link is the credential, like the signing and
 * survey pages. It opens exactly those installation photos — never a price,
 * never a customer's name, never the documents on a product's sheet. The office
 * can mint a new token (the old link dies) or take the link away entirely.
 *
 * Each photo carries the words the office put on it (migration 078) and, when
 * there is more than one on the page, they become filter chips: in a whole
 * list's gallery that is the difference between "the ones in a tabaccheria"
 * and scrolling past forty shops.
 *
 * The same page serves the images (?p=<media id>), so the photos stay outside
 * the web root and leave only for someone holding the token.
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

$listToken = trim((string)($_GET['l'] ?? ''));
$list      = $listToken !== '' ? PriceLists::byLinkToken('gallery', $listToken) : null;
$article   = $listToken === '' ? ArticleMedia::byGalleryToken((string)($_GET['t'] ?? '')) : null;

if (!$list && !$article) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    exit('<!DOCTYPE html><meta charset="utf-8"><title>404</title>'
        . '<p style="font:15px system-ui;padding:24px">'
        . ($it ? 'Questo link non è più valido.' : 'This link is no longer valid.') . '</p>');
}

// ---- what this link shows: one product, or each product of the list ----
$groups = [];   // [['title' => string, 'code' => string, 'photos' => rows], …]
if ($list) {
    $items = PriceLists::allItems((int)$list['id']);
    $byArticle = ArticleMedia::installsFor(array_column($items, 'id'));
    foreach ($items as $i) {
        if (!empty($byArticle[(int)$i['id']])) {
            $groups[] = ['title' => trim((string)$i['description']) ?: (string)$i['code'],
                         'code'  => (string)$i['code'],
                         'photos' => $byArticle[(int)$i['id']]];
        }
    }
    $base  = '?l=' . $listToken;
    $title = (string)$list['name'];
} else {
    $photos = ArticleMedia::installs((int)$article['id']);
    if ($photos) {
        $groups[] = ['title' => '', 'code' => (string)$article['code'], 'photos' => $photos];
    }
    $base  = '?t=' . (string)$article['gallery_token'];
    $title = trim((string)($article['description'] ?? '')) ?: (string)$article['code'];
}

$all = [];
foreach ($groups as $g) {
    foreach ($g['photos'] as $p) {
        $all[(int)$p['id']] = $p;
    }
}

// ---- the words on the photos, and how many carry each (078) ----
$tagsOf   = [];   // media id  => string[]
$tagCount = [];   // lowercase => ['label' => as written, 'n' => photos]
foreach ($all as $mid => $p) {
    $tagsOf[$mid] = ArticleMedia::tagsOf($p);
    foreach ($tagsOf[$mid] as $tg) {
        $k = mb_strtolower($tg);
        $tagCount[$k] ??= ['label' => $tg, 'n' => 0];
        $tagCount[$k]['n']++;
    }
}
// The busiest word first: it is the one most likely to be what you are after.
uasort($tagCount, static fn(array $x, array $y): int
    => ($y['n'] <=> $x['n']) ?: strcmp($x['label'], $y['label']));

// ---- one photo, for someone holding the token ----
if (isset($_GET['p'])) {
    $wanted = (int)$_GET['p'];
    if (isset($all[$wanted])) {
        header('X-Robots-Tag: noindex, nofollow');
        ArticleMedia::stream($all[$wanted], ($_GET['s'] ?? '') === 't');
    }
    http_response_code(404);
    exit('Not found');
}

$n = count($all);
if ($list) {
    $sub = sprintf($it ? '%d foto di installazioni · %d prodotti' : '%d installation photos · %d products',
        $n, count($groups));
} else {
    $sub = ($it ? 'Codice ' : 'Code ') . (string)$article['code'] . ' · '
         . ($n === 1 ? ($it ? '1 foto di installazione' : '1 installation photo')
                     : sprintf($it ? '%d foto di installazioni' : '%d installation photos', $n));
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $h($title) ?> — <?= $h($it ? 'installazioni' : 'installations') ?></title>
<style>
  :root{color-scheme:light dark;--bg:#f6f7f9;--card:#fff;--txt:#111827;--muted:#6b7280;--line:#e5e7eb}
  @media (prefers-color-scheme:dark){:root{--bg:#0f141c;--card:#161c28;--txt:#e7ecf4;--muted:#8b95a7;--line:#28303f}}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);
       font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
  header{background:var(--card);border-bottom:1px solid var(--line);padding:18px 16px}
  .wrap{max-width:1080px;margin:0 auto;padding:0 16px}
  header .wrap{padding:0}
  .co{color:var(--muted);font-size:12.5px;text-transform:uppercase;letter-spacing:.08em}
  h1{margin:4px 0 2px;font-size:21px;line-height:1.25}
  .code{color:var(--muted);font-size:13px}
  /* type selectors lose to .wrap, so the page margins are set on the elements themselves */
  main.wrap{padding:18px 16px 30px}
  footer.wrap{padding:0 16px 30px}
  .chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:4px 0 2px}
  .chips .lbl{color:var(--muted);font-size:12.5px}
  .chip{border:1px solid var(--line);background:var(--card);color:var(--txt);border-radius:999px;
        padding:5px 11px;font:inherit;font-size:13px;cursor:pointer}
  .chip b{font-weight:400;font-size:12px;margin-left:5px;opacity:.6}
  .chip[aria-pressed="true"]{background:var(--txt);color:var(--bg);border-color:var(--txt)}
  h2{font-size:16px;margin:22px 0 4px}
  h2 span{color:var(--muted);font-weight:400;font-size:13px}
  .grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin-top:10px}
  .grid button{padding:0;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--card);
               cursor:zoom-in;aspect-ratio:1;display:block;width:100%}
  .shot{margin:0;display:flex;flex-direction:column;gap:5px}
  .shot[hidden],.group[hidden]{display:none}
  figcaption{color:var(--muted);font-size:12px;line-height:1.3}

  .grid img{width:100%;height:100%;object-fit:cover;display:block}
  .none{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:28px;text-align:center;color:var(--muted)}
  footer{color:var(--muted);font-size:12.5px}
  #lb{position:fixed;inset:0;background:rgba(0,0,0,.93);display:none;align-items:center;justify-content:center;z-index:50}
  #lb.on{display:flex}
  #lb img{max-width:100%;max-height:100%;object-fit:contain}
  #lb .x,#lb .nav{position:absolute;background:rgba(255,255,255,.12);color:#fff;border:0;cursor:pointer;
                  font-size:26px;line-height:1;border-radius:999px;width:46px;height:46px}
  #lb .x{top:14px;right:14px}
  #lb .nav{top:50%;transform:translateY(-50%)}
  #lb .prev{left:12px} #lb .next{right:12px}
  #lb .cap{position:absolute;bottom:16px;left:0;right:0;text-align:center;color:#fff;opacity:.85;font-size:13px;padding:0 60px}
  @media (max-width:560px){ .grid{grid-template-columns:repeat(auto-fill,minmax(110px,1fr))} h1{font-size:18px} }
</style></head><body>
<header><div class="wrap">
  <div class="co"><?= $h($co) ?></div>
  <h1><?= $h($title) ?></h1>
  <div class="code"><?= $h($sub) ?></div>
</div></header>

<main class="wrap">
<?php if (!$all): ?>
  <div class="none"><?= $h($it ? 'Non ci sono ancora foto di installazioni.' : 'No installation photos yet.') ?></div>
<?php else: ?>
  <?php if (count($tagCount) > 1): ?>
    <div class="chips">
      <span class="lbl"><?= $h($it ? 'Filtra:' : 'Filter:') ?></span>
      <button type="button" class="chip" data-tag="" aria-pressed="true"><?= $h($it ? 'Tutte' : 'All') ?> <b><?= $n ?></b></button>
      <?php foreach ($tagCount as $tk => $tc): ?>
        <button type="button" class="chip" data-tag="<?= $h($tk) ?>" aria-pressed="false"><?= $h($tc['label']) ?> <b><?= (int)$tc['n'] ?></b></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php $i = 0; foreach ($groups as $g): ?>
    <section class="group">
      <?php if ($list): ?>
        <h2><?= $h($g['title']) ?> <span>· <?= $h($g['code']) ?> · <?= count($g['photos']) ?></span></h2>
      <?php endif; ?>
      <div class="grid">
        <?php foreach ($g['photos'] as $p): $pt = $tagsOf[(int)$p['id']] ?? []; ?>
          <figure class="shot" data-tags="<?= $h($pt ? '|' . mb_strtolower(implode('|', $pt)) . '|' : '') ?>">
            <button type="button" data-i="<?= $i++ ?>" aria-label="<?= $h($p['name']) ?>">
              <img src="<?= $h($base) ?>&amp;p=<?= (int)$p['id'] ?>&amp;s=t" alt="" loading="lazy">
            </button>
            <?php if ($pt): ?><figcaption><?= $h(implode(' · ', $pt)) ?></figcaption><?php endif; ?>
          </figure>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
<?php endif; ?>
</main>

<footer class="wrap"><?= $h($co) ?></footer>

<div id="lb" role="dialog" aria-modal="true">
  <button class="x" aria-label="<?= $h($it ? 'Chiudi' : 'Close') ?>">&times;</button>
  <button class="nav prev" aria-label="<?= $h($it ? 'Precedente' : 'Previous') ?>">&lsaquo;</button>
  <img id="lbimg" alt="">
  <button class="nav next" aria-label="<?= $h($it ? 'Successiva' : 'Next') ?>">&rsaquo;</button>
  <div class="cap" id="lbn"></div>
</div>

<script>
(function () {
  // Every photo on the page, in the order it is shown, with the product it
  // belongs to: in a whole-list gallery the caption is the only thing saying
  // which product you are looking at.
  var shots = <?php
      $flat = [];
      foreach ($groups as $g) {
          foreach ($g['photos'] as $p) {
              $flat[] = ['u' => $base . '&p=' . (int)$p['id'],
                         'c' => $list ? $g['title'] . ' · ' . $g['code'] : '',
                         't' => implode(' · ', $tagsOf[(int)$p['id']] ?? [])];
          }
      }
      echo json_encode($flat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ?>;
  if (!shots.length) { return; }
  var lb = document.getElementById('lb'), img = document.getElementById('lbimg'),
      cap = document.getElementById('lbn'), at = 0;

  var figs  = Array.prototype.slice.call(document.querySelectorAll('.shot'));
  var chips = Array.prototype.slice.call(document.querySelectorAll('.chip'));
  // Which photos are on screen right now, in order. A filter narrows it, and
  // the arrows then walk that selection instead of the whole page.
  var vis = shots.map(function (_, k) { return k; });

  function show(i) {
    at = (i + shots.length) % shots.length;
    var s = shots[at], bits = [], k = vis.indexOf(at);
    if (s.c) { bits.push(s.c); }
    if (s.t) { bits.push(s.t); }
    img.src = s.u;
    cap.textContent = (bits.length ? bits.join(' · ') + ' — ' : '')
                    + ((k < 0 ? 0 : k) + 1) + ' / ' + vis.length;
    lb.classList.add('on');
    document.body.style.overflow = 'hidden';
  }
  function step(d) {
    var k = vis.indexOf(at);
    show(vis[((k < 0 ? 0 : k) + d + vis.length) % vis.length]);
  }

  function close() { lb.classList.remove('on'); img.src = ''; document.body.style.overflow = ''; }

  document.querySelectorAll('.grid button').forEach(function (b) {
    b.addEventListener('click', function () { show(parseInt(b.dataset.i, 10) || 0); });
  });

  function filter(tag) {
    vis = [];
    figs.forEach(function (fg) {
      var on = !tag || (fg.getAttribute('data-tags') || '').indexOf('|' + tag + '|') >= 0;
      fg.hidden = !on;
      if (on) { vis.push(parseInt(fg.querySelector('button').getAttribute('data-i'), 10) || 0); }
    });
    // A product whose photos are all filtered out loses its heading too.
    Array.prototype.forEach.call(document.querySelectorAll('.group'), function (sec) {
      sec.hidden = !sec.querySelector('.shot:not([hidden])');
    });
    chips.forEach(function (c) {
      c.setAttribute('aria-pressed', c.getAttribute('data-tag') === tag ? 'true' : 'false');
    });
  }
  chips.forEach(function (c) {
    c.addEventListener('click', function () { filter(c.getAttribute('data-tag')); });
  });
  lb.querySelector('.x').addEventListener('click', close);
  lb.querySelector('.prev').addEventListener('click', function (e) { e.stopPropagation(); step(-1); });
  lb.querySelector('.next').addEventListener('click', function (e) { e.stopPropagation(); step(1); });
  lb.addEventListener('click', function (e) { if (e.target === lb || e.target === img) { close(); } });
  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('on')) { return; }
    if (e.key === 'Escape') { close(); }
    if (e.key === 'ArrowLeft') { step(-1); }
    if (e.key === 'ArrowRight') { step(1); }
  });
  // a thumb swipe on a phone, where there is no keyboard and the arrows are small
  var x0 = null;
  lb.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, {passive: true});
  lb.addEventListener('touchend', function (e) {
    if (x0 === null) { return; }
    var dx = e.changedTouches[0].clientX - x0;
    if (Math.abs(dx) > 50) { step(dx < 0 ? 1 : -1); }
    x0 = null;
  }, {passive: true});
})();
</script>
</body></html>
