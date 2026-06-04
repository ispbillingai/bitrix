<?php
declare(strict_types=1);

/**
 * Bitrix24 Glue control panel — sidebar + header layout, EN/IT, with Setup
 * (DB-backed settings), connection tests, queues/logs and a translated
 * Instructions page. Single self-contained file in the house dashboard style.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Auth;
use Glue\Bootstrap;
use Glue\Bitrix\Client;
use Glue\Campaign\Sender;
use Glue\Config;
use Glue\Db;
use Glue\Notify\Notifier;
use Glue\Notify\TextMeBot;
use Glue\Reminder\Scheduler;
use Glue\Settings;

Bootstrap::init();
Auth::ensureSeed(); // create default admin/admin on first run

session_set_cookie_params(31536000, '/', '', false, true);
session_start();

// ---- language ----
$avail = ['en', 'it'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $avail, true)) {
    setcookie('glue_ui_lang', $_GET['lang'], time() + 31536000, '/');
    $_COOKIE['glue_ui_lang'] = $_GET['lang'];
}
$lang = in_array($_COOKIE['glue_ui_lang'] ?? '', $avail, true)
    ? $_COOKIE['glue_ui_lang']
    : (in_array(Config::get('app.default_lang', 'it'), $avail, true) ? Config::get('app.default_lang', 'it') : 'en');
$UI = require dirname(__DIR__) . '/lang/ui.' . $lang . '.php';
$t = fn(string $k): string => $UI[$k] ?? $k;
$h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ---- auth ----
if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: ?');
    exit;
}
$flash = null;
$flashType = 'ok';
if (!isset($_SESSION['glue_auth'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $username = trim((string)($_POST['username'] ?? ''));
        $user = Auth::verify($username, (string)$_POST['password']);
        // Master fallback (config.php) so an operator is never locked out.
        $masterPw = (string)Config::get('dashboard.password', '');
        if (!$user && $masterPw !== '' && hash_equals($masterPw, (string)$_POST['password'])) {
            $user = ['id' => 0, 'username' => ($username ?: 'admin'), 'role' => 'admin'];
        }
        if ($user) {
            $_SESSION['glue_auth'] = true;
            $_SESSION['glue_user'] = $user;
            header('Location: ?');
            exit;
        }
        $loginErr = $t('login_err');
    }
    render_login($t, $h, $lang, $loginErr ?? null);
    exit;
}

$pdo = Db::pdo();
$tab = $_GET['tab'] ?? 'overview';

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    try {
        if ($do === 'save_settings') {
            $allowed = [
                'bitrix.base_url', 'bitrix.outbound_secret', 'bitrix.lead_status_new',
                'bitrix.deal_stage_quote', 'bitrix.deal_stage_signed',
                'app.intake_secret', 'app.default_lang', 'app.timezone',
                'textmebot.api_key', 'mail.from_name', 'mail.from_email',
                'mail.smtp.host', 'mail.smtp.port', 'mail.smtp.user', 'mail.smtp.pass', 'mail.smtp.secure',
                'logistics.email', 'logistics.phone',
            ];
            $pairs = [];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $_POST)) {
                    $pairs[$k] = trim((string)$_POST[$k]);
                }
            }
            Settings::setMany($pairs);
            $flash = $t('saved');
        } elseif ($do === 'cancel_reminder') {
            $pdo->prepare("UPDATE reminders SET status='cancelled' WHERE id=? AND status='pending'")
                ->execute([(int)$_POST['id']]);
            $flash = $t('rem_cancelled');
        } elseif ($do === 'run_scheduler') {
            $r = (new Scheduler())->runDue();
            (new Sender())->runBatch();
            $flash = $t('ov_ran') . ' ' . json_encode($r);
        } elseif ($do === 'create_campaign') {
            $recips = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$_POST['recipients'])));
            (new Sender())->create(
                trim((string)$_POST['name']) ?: 'Campaign',
                (string)$_POST['channel'],
                (string)$_POST['body'],
                $_POST['subject'] ?? null,
                array_values($recips),
                $lang
            );
            $flash = $t('camp_created');
            $tab = 'campaigns';
        } elseif ($do === 'test_bitrix') {
            $me = (new Client())->call('profile');
            $flash = $t('test_ok') . ': ' . ($me['NAME'] ?? '') . ' ' . ($me['LAST_NAME'] ?? '') . ' (' . ($me['ID'] ?? '?') . ')';
            $tab = 'settings';
        } elseif ($do === 'test_whatsapp') {
            $ok = (new Notifier())->whatsapp((string)$_POST['to'], 'Bitrix24 Glue — test ✅');
            $flash = ($ok ? $t('test_ok') : $t('test_fail'));
            $flashType = $ok ? 'ok' : 'err';
            $tab = 'settings';
        } elseif ($do === 'test_email') {
            $ok = (new Notifier())->email((string)$_POST['to'], 'Bitrix24 Glue test', '<p>Bitrix24 Glue — test ✅</p>');
            $flash = ($ok ? $t('test_ok') : $t('test_fail'));
            $flashType = $ok ? 'ok' : 'err';
            $tab = 'settings';
        } elseif ($do === 'create_user') {
            Auth::create((string)$_POST['username'], (string)$_POST['password'], (string)($_POST['role'] ?? 'admin'));
            $flash = $t('u_added');
            $tab = 'users';
        } elseif ($do === 'set_password') {
            Auth::setPassword((int)$_POST['id'], (string)$_POST['password']);
            $flash = $t('pw_changed');
            $tab = 'users';
        } elseif ($do === 'toggle_user') {
            Auth::setActive((int)$_POST['id'], ($_POST['active'] ?? '') === '1');
            $tab = 'users';
        } elseif ($do === 'delete_user') {
            if ((int)$_POST['id'] !== (int)($_SESSION['glue_user']['id'] ?? 0)) {
                Auth::delete((int)$_POST['id']);
                $flash = $t('u_deleted');
            }
            $tab = 'users';
        } elseif ($do === 'change_my_password') {
            $uid = (int)($_SESSION['glue_user']['id'] ?? 0);
            if ($uid > 0) {
                Auth::setPassword($uid, (string)$_POST['password']);
                $flash = $t('pw_changed');
            } else {
                $flash = $t('pw_change_na');
                $flashType = 'err';
            }
            $tab = 'users';
        }
    } catch (Throwable $e) {
        $flash = $t('test_fail') . ': ' . $e->getMessage();
        $flashType = 'err';
    }
}

// ---- small data helpers ----
$count = fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
$cfg = fn(string $k, $d = '') => Config::get($k, $d);

render_head($t, $h, $lang, $tab, $flash, $flashType);

switch ($tab) {
    case 'settings':    render_setup($t, $h, $cfg); break;
    case 'leads':       render_leads($t, $h, $pdo); break;
    case 'reminders':   render_reminders($t, $h, $pdo); break;
    case 'messages':    render_messages($t, $h, $pdo); break;
    case 'campaigns':   render_campaigns($t, $h, $pdo); break;
    case 'events':      render_events($t, $h, $pdo); break;
    case 'instructions':render_instructions($t); break;
    case 'users':       render_users($t, $h); break;
    default:            render_overview($t, $h, $pdo, $count); break;
}

render_foot();


// ============================ views ============================

function render_login(callable $t, callable $h, string $lang, ?string $err): void { ?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($t('login_title')) ?></title><?php css(); ?></head>
<body class="center">
  <form class="login" method="post">
    <div class="logo">B</div>
    <h1><?= $h($t('login_title')) ?></h1>
    <p class="muted"><?= $h($t('login_sub')) ?></p>
    <?php if ($err): ?><p class="err"><?= $h($err) ?></p><?php endif; ?>
    <input type="text" name="username" placeholder="<?= $h($t('login_user_ph')) ?>" autofocus>
    <input type="password" name="password" placeholder="<?= $h($t('login_ph')) ?>">
    <button type="submit"><?= $h($t('login_btn')) ?></button>
  </form>
</body></html>
<?php }

function render_head(callable $t, callable $h, string $lang, string $tab, ?string $flash, string $flashType): void {
    $nav = [
        'overview'    => 'nav_overview', 'leads' => 'nav_leads',
        'reminders'   => 'nav_reminders', 'messages' => 'nav_messages',
        'campaigns'   => 'nav_campaigns', 'events' => 'nav_events',
        'instructions'=> 'nav_instr', 'settings' => 'nav_settings', 'users' => 'nav_users',
    ]; ?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($t('app_title')) ?></title><?php css(); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script></head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand"><div class="logo">B</div><div><strong><?= $h($t('app_title')) ?></strong>
      <span class="muted small"><?= $h($t('app_subtitle')) ?></span></div></div>
    <nav>
      <?php foreach ($nav as $key => $label): ?>
        <a class="<?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= $h($key) ?>"><?= svg($key) ?><span><?= $h($t($label)) ?></span></a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <main>
    <header class="topbar">
      <div class="crumb"><?= $h($t('nav_' . ($tab === 'instructions' ? 'instr' : $tab))) ?></div>
      <div class="actions">
        <span class="langsw">
          <a class="<?= $lang === 'en' ? 'on' : '' ?>" href="?tab=<?= $h($tab) ?>&lang=en">EN</a>
          <a class="<?= $lang === 'it' ? 'on' : '' ?>" href="?tab=<?= $h($tab) ?>&lang=it">IT</a>
        </span>
        <span class="muted small"><?= $h($_SESSION['glue_user']['username'] ?? '') ?></span>
        <a class="btn ghost" href="?action=logout"><?= $h($t('logout')) ?></a>
      </div>
    </header>
    <div class="content">
    <?php if ($flash): ?><div class="flash <?= $flashType === 'err' ? 'flash-err' : '' ?>"><?= $h($flash) ?></div><?php endif; ?>
<?php }

function render_foot(): void { echo '</div></main></div></body></html>'; }

function render_overview(callable $t, callable $h, $pdo, callable $count): void {
    $bitrixOk = ($u = (string)\Glue\Config::get('bitrix.base_url', '')) !== '' && !str_contains($u, 'CHANGE_ME');
    $waOk = (new TextMeBot())->enabled();
    $mailOk = (string)\Glue\Config::get('mail.from_email', '') !== '';
    $pending = $count("SELECT COUNT(*) FROM reminders WHERE status='pending'");
    $sent = $count("SELECT COUNT(*) FROM messages WHERE status='sent'");
    $failed = $count("SELECT COUNT(*) FROM messages WHERE status='failed'");
    $leads = $count("SELECT COUNT(*) FROM tracked_entities");
    $camps = $count("SELECT COUNT(*) FROM campaigns");

    // ---- chart data: messages per day, last 14 days ----
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $days[date('Y-m-d', strtotime("-$i day"))] = ['sent' => 0, 'failed' => 0];
    }
    $mrows = $pdo->query("SELECT DATE(created_at) d, status, COUNT(*) c FROM messages
                          WHERE created_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY d, status")->fetchAll();
    foreach ($mrows as $r) {
        if (isset($days[$r['d']]) && isset($days[$r['d']][$r['status']])) {
            $days[$r['d']][$r['status']] = (int)$r['c'];
        }
    }
    $labels = array_map(fn($d) => date('M j', strtotime($d)), array_keys($days));
    $sentSeries = array_map(fn($v) => $v['sent'], array_values($days));
    $failSeries = array_map(fn($v) => $v['failed'], array_values($days));

    // ---- reminders by status ----
    $rstat = $pdo->query("SELECT status, COUNT(*) c FROM reminders GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rOrder = ['pending', 'sent', 'skipped', 'cancelled', 'failed'];
    $rData = array_map(fn($s) => (int)($rstat[$s] ?? 0), $rOrder);

    // ---- feeds ----
    $events = $pdo->query("SELECT * FROM events ORDER BY id DESC LIMIT 8")->fetchAll();
    $upcoming = $pdo->query("SELECT * FROM reminders WHERE status='pending' ORDER BY due_at ASC LIMIT 8")->fetchAll();

    $chart = [
        'labels' => $labels, 'sent' => $sentSeries, 'failed' => $failSeries,
        'rLabels' => array_map(fn($s) => ucfirst($s), $rOrder), 'rData' => $rData,
        'tSent' => $t('lg_sent'), 'tFailed' => $t('lg_failed'),
    ]; ?>
    <h2><?= $h($t('ov_title')) ?></h2>

    <div class="grid">
      <?php
      num_card($h, 'clock', $t('ov_pending'), $pending, $t('ov_pending_sub'));
      num_card($h, 'send', $t('ov_sent'), $sent, $t('ov_sent_sub'));
      num_card($h, 'alert', $t('ov_failed'), $failed, $t('ov_failed_sub'));
      num_card($h, 'users', $t('ov_leads'), $leads, $t('ov_leads_sub'));
      num_card($h, 'mega', $t('ov_campaigns'), $camps, $t('ov_campaigns_sub'));
      ?>
    </div>

    <div class="cols c-2-1">
      <div class="panel">
        <div class="panel-h"><h3><?= svg('messages') ?><?= $h($t('ch_messages_title')) ?></h3></div>
        <div class="chart-wrap"><canvas id="chMsg"></canvas></div>
      </div>
      <div class="panel">
        <div class="panel-h"><h3><?= svg('reminders') ?><?= $h($t('ch_reminders_title')) ?></h3></div>
        <div class="chart-wrap sm"><canvas id="chRem"></canvas></div>
      </div>
    </div>

    <div class="cols c-1-1">
      <div class="panel">
        <div class="panel-h"><h3><?= svg('events') ?><?= $h($t('ov_recent')) ?></h3>
          <a class="btn ghost tiny" href="?tab=events"><?= $h($t('filter_all')) ?></a></div>
        <div class="feed">
          <?php if (!$events): ?><div class="empty"><?= $h($t('none_yet')) ?></div><?php endif; ?>
          <?php foreach ($events as $e): ?>
            <div class="feed-row">
              <div class="feed-ic"><?= svg(feed_icon($e['source'])) ?></div>
              <div class="feed-main"><b><?= $h($e['event_type']) ?></b>
                <div class="meta"><?= $h($e['source']) ?><?= $e['entity_type'] ? ' · ' . $h($e['entity_type']) . ' ' . $h($e['entity_id']) : '' ?></div></div>
              <div class="feed-time"><?= $h(short_time($e['created_at'])) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="panel">
        <div class="panel-h"><h3><?= svg('clock') ?><?= $h($t('ov_upcoming')) ?></h3>
          <a class="btn ghost tiny" href="?tab=reminders"><?= $h($t('filter_all')) ?></a></div>
        <div class="feed">
          <?php if (!$upcoming): ?><div class="empty"><?= $h($t('none_yet')) ?></div><?php endif; ?>
          <?php foreach ($upcoming as $r): ?>
            <div class="feed-row">
              <div class="feed-ic"><?= svg('clock') ?></div>
              <div class="feed-main"><b><?= $h($r['rule_key']) ?></b>
                <div class="meta"><?= $h($r['recipient_type']) ?> · <?= $h($r['channel']) ?> · #<?= $h($r['bitrix_id']) ?></div></div>
              <div class="feed-time"><?= $h($r['due_at']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="panel" style="margin-bottom:16px">
      <div class="panel-h"><h3><?= svg('settings') ?><?= $h($t('ov_status')) ?></h3>
        <form method="post" class="inline" style="margin:0"><input type="hidden" name="do" value="run_scheduler">
          <button class="btn ghost tiny"><?= $h($t('ov_run')) ?></button></form></div>
      <div class="grid" style="margin-bottom:0">
        <?php
        stat_card($h, 'database', $t('st_db'), $t('configured'), true);
        stat_card($h, 'link', $t('st_bitrix'), $bitrixOk ? $t('configured') : $t('not_configured'), $bitrixOk);
        stat_card($h, 'chat', $t('st_whatsapp'), $waOk ? $t('configured') : $t('not_configured'), $waOk);
        stat_card($h, 'mail', $t('st_mail'), $mailOk ? $t('configured') : $t('not_configured'), $mailOk);
        ?>
      </div>
    </div>

    <script>
    (function(){
      const d = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;
      const css = getComputedStyle(document.documentElement);
      const c = n => css.getPropertyValue(n).trim();
      const grid = 'rgba(255,255,255,.06)', muted = c('--muted');
      Chart.defaults.color = muted; Chart.defaults.font.family = 'Inter, sans-serif';
      new Chart(document.getElementById('chMsg'), {
        type:'bar',
        data:{labels:d.labels,datasets:[
          {label:d.tSent,data:d.sent,backgroundColor:c('--green'),borderRadius:5,maxBarThickness:26},
          {label:d.tFailed,data:d.failed,backgroundColor:c('--red'),borderRadius:5,maxBarThickness:26}]},
        options:{maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:12,padding:16}}},
          scales:{x:{grid:{display:false},border:{display:false}},
                  y:{beginAtZero:true,ticks:{precision:0},grid:{color:grid},border:{display:false}}}}
      });
      new Chart(document.getElementById('chRem'), {
        type:'doughnut',
        data:{labels:d.rLabels,datasets:[{data:d.rData,borderWidth:0,
          backgroundColor:[c('--amber'),c('--green'),c('--muted'),c('--red'),'#7c5cff']}]},
        options:{maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'right',labels:{boxWidth:12,padding:12}}}}
      });
    })();
    </script>
<?php }

function render_setup(callable $t, callable $h, callable $cfg): void {
    $base = rtrim((string)$cfg('app.base_url', ''), '/');
    $is = $h($cfg('app.intake_secret', ''));
    $os = $h($cfg('bitrix.outbound_secret', ''));
    $urls = [
        'url_form'      => "$base/webhooks/form-intake.php?secret=$is",
        'url_bitrix_ev' => "$base/webhooks/bitrix-event.php?secret=$os",
        'url_appt'      => "$base/webhooks/appointment-intake.php?secret=$is",
        'url_campaign'  => "$base/campaign.php?secret=$is",
    ]; ?>
    <h2><?= $h($t('setup_title')) ?></h2>
    <p class="muted"><?= $h($t('setup_intro')) ?></p>

    <div class="card">
      <h3><?= $h($t('urls_title')) ?></h3>
      <p class="muted small"><?= $h($t('urls_intro')) ?></p>
      <?php foreach ($urls as $k => $u): ?>
        <label class="fld"><span><?= $h($t($k)) ?></span>
          <input readonly value="<?= $h($u) ?>" onclick="this.select()"></label>
      <?php endforeach; ?>
    </div>

    <form method="post" class="card">
      <input type="hidden" name="do" value="save_settings">
      <h3><?= $h($t('sec_bitrix')) ?></h3>
      <?php
      fld($h, 'bitrix.base_url', $t('f_bitrix_url'), $cfg('bitrix.base_url'), $t('f_bitrix_url_h'));
      fld($h, 'bitrix.outbound_secret', $t('f_outbound'), $cfg('bitrix.outbound_secret'), $t('f_outbound_h'));
      fld($h, 'app.intake_secret', $t('f_intake'), $cfg('app.intake_secret'), $t('f_intake_h'));
      ?>
      <h3><?= $h($t('sec_stages')) ?></h3>
      <p class="muted small"><?= $h($t('f_stages_h')) ?></p>
      <?php
      fld($h, 'bitrix.lead_status_new', $t('f_lead_new'), $cfg('bitrix.lead_status_new', 'NEW'));
      fld($h, 'bitrix.deal_stage_quote', $t('f_deal_quote'), $cfg('bitrix.deal_stage_quote', 'PREPARATION'));
      fld($h, 'bitrix.deal_stage_signed', $t('f_deal_signed'), $cfg('bitrix.deal_stage_signed', 'WON'));
      ?>
      <h3><?= $h($t('sec_whatsapp')) ?></h3>
      <?php fld($h, 'textmebot.api_key', $t('f_tmb_key'), $cfg('textmebot.api_key'), $t('f_tmb_key_h')); ?>
      <h3><?= $h($t('sec_mail')) ?></h3>
      <?php
      fld($h, 'mail.from_name', $t('f_from_name'), $cfg('mail.from_name'), $t('f_from_name_h'));
      fld($h, 'mail.from_email', $t('f_from_email'), $cfg('mail.from_email'));
      ?>
      <p class="muted small"><?= $h($t('f_smtp_h')) ?></p>
      <div class="row">
        <?php
        fld($h, 'mail.smtp.host', $t('f_smtp_host'), $cfg('mail.smtp.host'));
        fld($h, 'mail.smtp.port', $t('f_smtp_port'), $cfg('mail.smtp.port'));
        ?>
      </div>
      <div class="row">
        <?php
        fld($h, 'mail.smtp.user', $t('f_smtp_user'), $cfg('mail.smtp.user'));
        fld($h, 'mail.smtp.pass', $t('f_smtp_pass'), $cfg('mail.smtp.pass'));
        fld($h, 'mail.smtp.secure', $t('f_smtp_secure'), $cfg('mail.smtp.secure'));
        ?>
      </div>
      <h3><?= $h($t('sec_logistics')) ?></h3>
      <?php
      fld($h, 'logistics.email', $t('f_log_email'), $cfg('logistics.email'));
      fld($h, 'logistics.phone', $t('f_log_phone'), $cfg('logistics.phone'));
      ?>
      <h3><?= $h($t('sec_general')) ?></h3>
      <?php
      fld($h, 'app.default_lang', $t('f_default_lang'), $cfg('app.default_lang', 'it'));
      fld($h, 'app.timezone', $t('f_tz'), $cfg('app.timezone', 'Europe/Rome'));
      ?>
      <button class="btn"><?= $h($t('save')) ?></button>
    </form>

    <div class="card">
      <h3><?= $h($t('test_title')) ?></h3>
      <form method="post" class="inline">
        <input type="hidden" name="do" value="test_bitrix">
        <button class="btn ghost"><?= $h($t('test_bitrix')) ?></button>
      </form>
      <form method="post" class="inline">
        <input type="hidden" name="do" value="test_whatsapp">
        <input name="to" placeholder="<?= $h($t('test_phone_ph')) ?>" required>
        <button class="btn ghost"><?= $h($t('test_wa')) ?></button>
      </form>
      <form method="post" class="inline">
        <input type="hidden" name="do" value="test_email">
        <input name="to" placeholder="<?= $h($t('test_email_ph')) ?>" required>
        <button class="btn ghost"><?= $h($t('test_email')) ?></button>
      </form>
    </div>
<?php }

function render_leads(callable $t, callable $h, $pdo): void {
    $rows = $pdo->query("SELECT * FROM tracked_entities ORDER BY id DESC LIMIT 200")->fetchAll(); ?>
    <h2><?= $h($t('leads_title')) ?></h2>
    <table><thead><tr>
      <th><?= $h($t('th_type')) ?></th><th><?= $h($t('th_bitrix_id')) ?></th><th><?= $h($t('th_stage')) ?></th>
      <th><?= $h($t('th_customer')) ?></th><th><?= $h($t('th_lang')) ?></th>
      <th><?= $h($t('th_received')) ?></th><th><?= $h($t('th_status')) ?></th>
    </tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= $h($r['entity_type']) ?></td><td><?= $h($r['bitrix_id']) ?></td><td><?= $h($r['stage_id']) ?></td>
      <td><?= $h($r['customer_name']) ?><br><span class="muted small"><?= $h($r['customer_phone']) ?></span></td>
      <td><?= $h($r['lang']) ?></td><td class="small"><?= $h($r['received_at']) ?></td>
      <td><span class="pill"><?= $h($r['status']) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table>
<?php }

function render_reminders(callable $t, callable $h, $pdo): void {
    $all = ($_GET['f'] ?? 'pending') === 'all';
    $sql = "SELECT * FROM reminders " . ($all ? '' : "WHERE status='pending' ") . "ORDER BY due_at DESC LIMIT 300";
    $rows = $pdo->query($sql)->fetchAll(); ?>
    <h2><?= $h($t('rem_title')) ?></h2>
    <div class="tabs">
      <a class="<?= $all ? '' : 'on' ?>" href="?tab=reminders&f=pending"><?= $h($t('filter_pending')) ?></a>
      <a class="<?= $all ? 'on' : '' ?>" href="?tab=reminders&f=all"><?= $h($t('filter_all')) ?></a>
    </div>
    <table><thead><tr>
      <th><?= $h($t('th_due')) ?></th><th><?= $h($t('th_rule')) ?></th><th><?= $h($t('th_recipient')) ?></th>
      <th><?= $h($t('th_channel')) ?></th><th><?= $h($t('th_status')) ?></th><th></th>
    </tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr><td class="small"><?= $h($r['due_at']) ?></td><td><?= $h($r['rule_key']) ?> <span class="muted small">#<?= $h($r['bitrix_id']) ?></span></td>
      <td><?= $h($r['recipient_type']) ?></td><td><?= $h($r['channel']) ?></td>
      <td><span class="pill pill-<?= $h($r['status']) ?>"><?= $h($r['status']) ?></span></td>
      <td><?php if ($r['status'] === 'pending'): ?>
        <form method="post" class="inline"><input type="hidden" name="do" value="cancel_reminder">
        <input type="hidden" name="id" value="<?= $h($r['id']) ?>">
        <button class="btn tiny ghost"><?= $h($t('cancel')) ?></button></form>
      <?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
<?php }

function render_messages(callable $t, callable $h, $pdo): void {
    $rows = $pdo->query("SELECT * FROM messages ORDER BY id DESC LIMIT 300")->fetchAll(); ?>
    <h2><?= $h($t('msg_title')) ?></h2>
    <table><thead><tr>
      <th><?= $h($t('th_time')) ?></th><th><?= $h($t('th_channel')) ?></th><th><?= $h($t('th_recipient')) ?></th>
      <th><?= $h($t('th_subject')) ?></th><th><?= $h($t('th_status')) ?></th>
    </tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr><td class="small"><?= $h($r['created_at']) ?></td><td><?= $h($r['channel']) ?></td>
      <td><?= $h($r['recipient']) ?></td><td class="small"><?= $h($r['subject']) ?></td>
      <td><span class="pill pill-<?= $r['status'] === 'sent' ? 'sent' : 'failed' ?>"><?= $h($r['status']) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table>
<?php }

function render_campaigns(callable $t, callable $h, $pdo): void {
    $rows = $pdo->query("SELECT * FROM campaigns ORDER BY id DESC LIMIT 100")->fetchAll(); ?>
    <h2><?= $h($t('camp_title')) ?></h2>
    <div class="warn"><?= $h($t('camp_warn')) ?></div>
    <form method="post" class="card">
      <input type="hidden" name="do" value="create_campaign">
      <h3><?= $h($t('camp_new')) ?></h3>
      <label class="fld"><span><?= $h($t('camp_name')) ?></span><input name="name"></label>
      <label class="fld"><span><?= $h($t('camp_channel')) ?></span>
        <select name="channel"><option value="whatsapp">WhatsApp</option><option value="email">Email</option></select></label>
      <label class="fld"><span><?= $h($t('camp_subject')) ?></span><input name="subject"></label>
      <label class="fld"><span><?= $h($t('camp_body')) ?></span><textarea name="body" rows="3" required></textarea></label>
      <label class="fld"><span><?= $h($t('camp_recipients')) ?></span><textarea name="recipients" rows="4" required></textarea></label>
      <button class="btn"><?= $h($t('camp_create')) ?></button>
    </form>
    <table><thead><tr>
      <th><?= $h($t('camp_name')) ?></th><th><?= $h($t('th_channel')) ?></th><th><?= $h($t('th_total')) ?></th>
      <th><?= $h($t('th_sent')) ?></th><th><?= $h($t('th_failed')) ?></th><th><?= $h($t('th_status')) ?></th>
    </tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= $h($r['name']) ?></td><td><?= $h($r['channel']) ?></td><td><?= $h($r['total']) ?></td>
      <td><?= $h($r['sent']) ?></td><td><?= $h($r['failed']) ?></td>
      <td><span class="pill"><?= $h($r['status']) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table>
<?php }

function render_events(callable $t, callable $h, $pdo): void {
    $rows = $pdo->query("SELECT * FROM events ORDER BY id DESC LIMIT 300")->fetchAll(); ?>
    <h2><?= $h($t('ev_title')) ?></h2>
    <table><thead><tr>
      <th><?= $h($t('th_time')) ?></th><th><?= $h($t('th_source')) ?></th><th><?= $h($t('th_event')) ?></th><th><?= $h($t('th_entity')) ?></th>
    </tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted"><?= $h($t('none_yet')) ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr><td class="small"><?= $h($r['created_at']) ?></td><td><?= $h($r['source']) ?></td>
      <td><?= $h($r['event_type']) ?></td>
      <td class="small"><?= $h($r['entity_type']) ?> <?= $h($r['entity_id']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
<?php }

function render_instructions(callable $t): void {
    $steps = ['s1', 's2', 's3', 's4', 's5', 's6'];
    echo '<h2>' . htmlspecialchars($t('instr_title')) . '</h2>';
    echo '<p class="lead">' . htmlspecialchars($t('instr_intro')) . '</p>';
    foreach ($steps as $s) {
        echo '<div class="step"><h3>' . htmlspecialchars($t('instr_' . $s . '_t')) . '</h3>';
        echo '<p>' . $t('instr_' . $s) . '</p></div>'; // copy contains safe <b> tags
    }
    echo '<div class="step accent"><h3>' . htmlspecialchars($t('instr_manual_t')) . '</h3>';
    echo '<p>' . $t('instr_manual') . '</p></div>';
}

function render_users(callable $t, callable $h): void {
    $users = \Glue\Auth::all();
    $meId = (int)($_SESSION['glue_user']['id'] ?? 0); ?>
    <h2><?= $h($t('users_title')) ?></h2>

    <form method="post" class="card">
      <input type="hidden" name="do" value="create_user">
      <h3><?= $h($t('u_add')) ?></h3>
      <div class="row">
        <label class="fld"><span><?= $h($t('u_username')) ?></span><input name="username" required></label>
        <label class="fld"><span><?= $h($t('u_password')) ?></span><input name="password" required></label>
        <label class="fld"><span><?= $h($t('u_role')) ?></span>
          <select name="role"><option value="admin">admin</option><option value="agent">agent</option></select></label>
      </div>
      <button class="btn"><?= $h($t('u_create')) ?></button>
    </form>

    <table><thead><tr>
      <th><?= $h($t('u_username')) ?></th><th><?= $h($t('u_role')) ?></th><th><?= $h($t('u_active')) ?></th>
      <th><?= $h($t('u_reset')) ?></th><th></th>
    </tr></thead><tbody>
    <?php foreach ($users as $u): $id = (int)$u['id']; ?>
      <tr>
        <td><?= $h($u['username']) ?><?php if ($id === $meId): ?> <span class="muted small"><?= $h($t('u_you')) ?></span><?php endif; ?></td>
        <td><?= $h($u['role']) ?></td>
        <td>
          <form method="post" class="inline"><input type="hidden" name="do" value="toggle_user">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="active" value="<?= $u['active'] ? '0' : '1' ?>">
            <button class="btn tiny ghost"><?= $u['active'] ? $h($t('u_disable')) : $h($t('u_enable')) ?></button>
          </form>
        </td>
        <td>
          <form method="post" class="inline"><input type="hidden" name="do" value="set_password">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input name="password" placeholder="<?= $h($t('u_new_pw')) ?>" required>
            <button class="btn tiny ghost"><?= $h($t('u_set')) ?></button>
          </form>
        </td>
        <td><?php if ($id !== $meId): ?>
          <form method="post" class="inline" onsubmit="return confirm('?')"><input type="hidden" name="do" value="delete_user">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn tiny ghost"><?= $h($t('u_delete')) ?></button>
          </form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>

    <form method="post" class="card">
      <input type="hidden" name="do" value="change_my_password">
      <h3><?= $h($t('change_pw_title')) ?></h3>
      <label class="fld"><span><?= $h($t('u_new_pw')) ?></span><input name="password" required></label>
      <button class="btn"><?= $h($t('save')) ?></button>
    </form>
<?php }

// ============================ ui bits ============================

function stat_card(callable $h, string $icon, string $label, string $val, bool $ok): void {
    echo '<div class="tile"><div class="tile-top">' . svg($icon) . '<span class="small">' . $h($label) . '</span></div>'
        . '<span class="badge ' . ($ok ? 'ok' : 'no') . '"><span class="dot"></span>' . $h($val) . '</span></div>';
}
function num_card(callable $h, string $icon, string $label, int $n, string $sub = ''): void {
    echo '<div class="tile"><div class="tile-top">' . svg($icon) . '<span class="small">' . $h($label) . '</span></div>'
        . '<span class="big">' . $h((string)$n) . '</span>'
        . ($sub !== '' ? '<div class="sub">' . $h($sub) . '</div>' : '') . '</div>';
}

/** Map an event source to a feed icon name. */
function feed_icon(string $source): string {
    return [
        'form_intake'   => 'leads',
        'bitrix_event'  => 'link',
        'scheduler'     => 'clock',
        'campaign'      => 'mega',
        'appointment'   => 'reminders',
    ][$source] ?? 'events';
}

/** Compact timestamp for the activity feed. */
function short_time(?string $dt): string {
    $ts = $dt ? strtotime($dt) : false;
    return $ts ? date('M j, H:i', $ts) : (string)$dt;
}

/** Inline line-icons (no external lib). 18px, stroke = currentColor. */
function svg(string $name): string {
    $p = [
        'overview'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'leads'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'reminders'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'messages'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'chat'        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'campaigns'   => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'mega'        => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'events'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'instructions'=> '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'database'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>',
        'link'        => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/>',
        'send'        => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'alert'       => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    ];
    $body = $p[$name] ?? $p['overview'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}
function fld(callable $h, string $name, string $label, $value, string $hint = ''): void {
    echo '<label class="fld"><span>' . $h($label) . '</span>'
        . '<input name="' . $h($name) . '" value="' . $h($value) . '">'
        . ($hint ? '<small class="muted">' . $h($hint) . '</small>' : '') . '</label>';
}

function css(): void { ?>
<style>
:root{
  --bg:#0e131c;--surface:#161c28;--surface2:#1c2533;--line:#28303f;--line2:#39435a;
  --txt:#e7ecf4;--muted:#8b95a7;--accent:#5b6cff;--accent-soft:rgba(91,108,255,.14);
  --green:#3fb868;--green-bg:rgba(63,184,104,.13);--red:#e5616e;--red-bg:rgba(229,97,110,.13);
  --amber:#d9a40a;--amber-bg:rgba(217,164,10,.13);--radius:12px;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',system-ui,sans-serif;color:var(--txt);font-size:14px;line-height:1.5;
  background:var(--bg);min-height:100vh;-webkit-font-smoothing:antialiased;}
.center{display:flex;align-items:center;justify-content:center;min-height:100vh;}
.muted{color:var(--muted);} .small{font-size:12px;} .big{font-size:30px;font-weight:700;letter-spacing:-.02em;}
a{color:inherit;text-decoration:none;}
.logo{width:40px;height:40px;border-radius:10px;background:var(--accent);display:flex;align-items:center;
  justify-content:center;font-weight:800;color:#fff;font-size:18px;flex:0 0 auto;}
.shell{display:flex;min-height:100vh;}
.sidebar{width:244px;background:var(--surface);border-right:1px solid var(--line);
  padding:18px 14px;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;flex:0 0 auto;}
.brand{display:flex;gap:11px;align-items:center;margin:4px 6px 22px;}
.brand strong{display:block;font-size:15px;} .brand span{display:block;line-height:1.3;margin-top:2px;}
nav{display:flex;flex-direction:column;gap:2px;}
nav a{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:8px;color:var(--muted);
  font-weight:500;transition:background .12s,color .12s;}
nav a svg{width:18px;height:18px;flex:0 0 auto;}
nav a:hover{background:var(--surface2);color:var(--txt);}
nav a.active{background:var(--accent);color:#fff;}
main{flex:1;display:flex;flex-direction:column;min-width:0;}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:15px 28px;
  border-bottom:1px solid var(--line);background:var(--surface);position:sticky;top:0;z-index:5;}
.crumb{font-weight:700;font-size:17px;}
.actions{display:flex;gap:14px;align-items:center;}
.langsw{display:inline-flex;background:var(--surface2);border:1px solid var(--line);border-radius:8px;padding:2px;}
.langsw a{padding:4px 9px;border-radius:6px;color:var(--muted);font-weight:600;font-size:12px;}
.langsw a.on{background:var(--accent);color:#fff;}
.content{padding:26px 28px;width:100%;}
h2{font-size:21px;margin-bottom:18px;letter-spacing:-.01em;} h3{font-size:15px;margin:16px 0 12px;}
.lead{font-size:15px;color:var(--muted);margin-bottom:20px;line-height:1.65;max-width:820px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-bottom:16px;}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:20px;margin-bottom:16px;}
.tile{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:16px 18px;
  transition:border-color .12s;}
.tile:hover{border-color:var(--line2);}
.tile-top{display:flex;align-items:center;gap:9px;margin-bottom:10px;color:var(--muted);}
.tile-top svg{width:17px;height:17px;}
.tile .big{display:block;margin-top:6px;}
.tile .sub{font-size:12px;color:var(--muted);margin-top:4px;}
.badge{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;border-radius:7px;font-size:12.5px;font-weight:600;}
.badge .dot{width:7px;height:7px;border-radius:50%;}
.badge.ok{background:var(--green-bg);color:var(--green);} .badge.ok .dot{background:var(--green);}
.badge.no{background:var(--red-bg);color:var(--red);} .badge.no .dot{background:var(--red);}
/* dashboard layout */
.cols{display:grid;gap:16px;margin-bottom:16px;}
.cols.c-2-1{grid-template-columns:2fr 1fr;} .cols.c-1-1{grid-template-columns:1fr 1fr;}
@media(max-width:1100px){.cols.c-2-1,.cols.c-1-1{grid-template-columns:1fr;}}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;margin-bottom:0;}
.panel-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.panel-h h3{margin:0;display:flex;align-items:center;gap:9px;} .panel-h h3 svg{width:17px;height:17px;color:var(--muted);}
.chart-wrap{position:relative;height:240px;} .chart-wrap.sm{height:210px;}
.feed{display:flex;flex-direction:column;gap:2px;}
.feed-row{display:flex;align-items:center;gap:11px;padding:9px 4px;border-bottom:1px solid var(--line);}
.feed-row:last-child{border-bottom:none;}
.feed-ic{width:30px;height:30px;border-radius:8px;background:var(--surface2);display:flex;align-items:center;justify-content:center;color:var(--muted);flex:0 0 auto;}
.feed-ic svg{width:15px;height:15px;}
.feed-main{min-width:0;flex:1;} .feed-main b{font-weight:600;font-size:13.5px;} .feed-main .meta{font-size:12px;color:var(--muted);}
.feed-time{font-size:11.5px;color:var(--muted);white-space:nowrap;}
.empty{color:var(--muted);text-align:center;padding:26px 0;font-size:13px;}
.fld{display:block;margin-bottom:16px;} .fld span{display:block;margin-bottom:7px;color:var(--muted);font-size:13px;font-weight:500;}
input,select,textarea{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--bg);
  color:var(--txt);font-size:14px;outline:none;font-family:inherit;transition:border-color .12s;}
input:focus,select:focus,textarea:focus{border-color:var(--accent);}
input[readonly]{color:var(--muted);cursor:pointer;}
.fld small{display:block;margin-top:6px;font-size:12px;line-height:1.5;}
.row{display:flex;gap:14px;flex-wrap:wrap;} .row .fld{flex:1;min-width:160px;}
.btn{padding:10px 18px;border:none;border-radius:8px;background:var(--accent);color:#fff;font-weight:600;
  cursor:pointer;font-size:14px;transition:filter .12s;} .btn:hover{filter:brightness(1.08);}
.btn.ghost{background:var(--surface2);border:1px solid var(--line);color:var(--txt);}
.btn.ghost:hover{border-color:var(--line2);filter:none;background:var(--surface);}
.btn.tiny{padding:6px 12px;font-size:12.5px;}
.inline{display:inline-flex;gap:8px;align-items:center;margin:0 12px 10px 0;}
.inline input{width:auto;}
table{width:100%;border-collapse:separate;border-spacing:0;background:var(--surface);
  border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;}
th,td{text-align:left;padding:12px 15px;border-bottom:1px solid var(--line);vertical-align:middle;}
th{color:var(--muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;background:var(--surface2);}
tbody tr:hover{background:var(--surface2);}
tr:last-child td{border-bottom:none;}
.pill{display:inline-block;padding:4px 10px;border-radius:7px;background:var(--surface2);font-size:12px;font-weight:600;border:1px solid var(--line);}
.pill-pending{color:var(--amber);background:var(--amber-bg);border-color:transparent;}
.pill-sent{color:var(--green);background:var(--green-bg);border-color:transparent;}
.pill-failed,.pill-cancelled{color:var(--red);background:var(--red-bg);border-color:transparent;}
.flash{background:var(--green-bg);border:1px solid var(--green);color:var(--green);padding:12px 16px;
  border-radius:8px;margin-bottom:18px;word-break:break-word;font-weight:500;}
.flash-err{background:var(--red-bg);border-color:var(--red);color:var(--red);}
.warn{background:var(--amber-bg);border:1px solid var(--amber);color:var(--amber);padding:12px 16px;
  border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.55;}
.step{background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--accent);
  border-radius:10px;padding:17px 21px;margin-bottom:14px;}
.step.accent{border-left-color:var(--amber);} .step p{line-height:1.65;color:var(--muted);} .step b{color:var(--txt);font-weight:600;}
.tabs{display:inline-flex;gap:4px;margin-bottom:16px;background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:3px;}
.tabs a{padding:7px 14px;border-radius:7px;color:var(--muted);font-size:13px;font-weight:500;}
.tabs a.on{background:var(--accent);color:#fff;}
.login{background:var(--surface);padding:38px 36px;border-radius:14px;width:360px;text-align:center;border:1px solid var(--line);}
.login .logo{margin:0 auto 18px;width:50px;height:50px;font-size:22px;} .login h1{font-size:21px;margin-bottom:5px;}
.login input{margin:9px 0;} .login button{width:100%;margin-top:10px;}
.err{color:var(--red);font-size:13px;margin-bottom:8px;}
@media(max-width:760px){.sidebar{width:60px;padding:14px 8px;} .brand span,.brand strong,nav a span{display:none;}
  .brand{justify-content:center;} nav a{justify-content:center;padding:11px;} .content{padding:18px;}}
</style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php }
