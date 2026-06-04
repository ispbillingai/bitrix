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
<title><?= $h($t('app_title')) ?></title><?php css(); ?></head>
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
    $db = true;
    $bitrixOk = ($u = (string)\Glue\Config::get('bitrix.base_url', '')) !== '' && !str_contains($u, 'CHANGE_ME');
    $waOk = (new TextMeBot())->enabled();
    $mailOk = (string)\Glue\Config::get('mail.from_email', '') !== '';
    $pending = $count("SELECT COUNT(*) FROM reminders WHERE status='pending'");
    $sent = $count("SELECT COUNT(*) FROM messages WHERE status='sent'");
    $failed = $count("SELECT COUNT(*) FROM messages WHERE status='failed'");
    $leads = $count("SELECT COUNT(*) FROM tracked_entities");
    $camps = $count("SELECT COUNT(*) FROM campaigns"); ?>
    <h2><?= $h($t('ov_title')) ?></h2>
    <div class="grid">
      <?php
      stat_card($h, 'database', $t('st_db'), $db ? $t('configured') : $t('not_configured'), $db);
      stat_card($h, 'link', $t('st_bitrix'), $bitrixOk ? $t('configured') : $t('not_configured'), $bitrixOk);
      stat_card($h, 'chat', $t('st_whatsapp'), $waOk ? $t('configured') : $t('not_configured'), $waOk);
      stat_card($h, 'mail', $t('st_mail'), $mailOk ? $t('configured') : $t('not_configured'), $mailOk);
      ?>
    </div>
    <div class="grid">
      <?php
      num_card($h, 'clock', $t('ov_pending'), $pending);
      num_card($h, 'send', $t('ov_sent'), $sent);
      num_card($h, 'alert', $t('ov_failed'), $failed);
      num_card($h, 'users', $t('ov_leads'), $leads);
      num_card($h, 'mega', $t('ov_campaigns'), $camps);
      ?>
    </div>
    <form method="post" class="inline">
      <input type="hidden" name="do" value="run_scheduler">
      <button class="btn"><?= $h($t('ov_run')) ?></button>
      <span class="muted small"><?= $h($t('ov_cron_hint')) ?></span>
    </form>
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
function num_card(callable $h, string $icon, string $label, int $n): void {
    echo '<div class="tile"><div class="tile-top">' . svg($icon) . '<span class="small">' . $h($label) . '</span></div>'
        . '<span class="big">' . $h((string)$n) . '</span></div>';
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
  --bg:#0b1120;--bg2:#0e1729;--panel:#141d31;--panel2:#1b2742;--line:#26324d;--line2:#30405f;
  --txt:#e6edf7;--muted:#8a97ad;--accent:#6366f1;--accent2:#a855f7;
  --green:#34d399;--green-bg:rgba(52,211,153,.12);--red:#f87171;--red-bg:rgba(248,113,113,.12);
  --amber:#fbbf24;--shadow:0 10px 30px -12px rgba(0,0,0,.6);--radius:14px;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',system-ui,sans-serif;color:var(--txt);font-size:14px;line-height:1.5;
  background:radial-gradient(1200px 600px at 80% -10%,rgba(99,102,241,.10),transparent 60%),
             radial-gradient(900px 500px at -10% 10%,rgba(168,85,247,.08),transparent 55%),var(--bg);
  min-height:100vh;-webkit-font-smoothing:antialiased;}
.center{display:flex;align-items:center;justify-content:center;min-height:100vh;}
.muted{color:var(--muted);} .small{font-size:12px;} .big{font-size:32px;font-weight:700;letter-spacing:-.02em;}
a{color:inherit;text-decoration:none;}
.logo{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:19px;flex:0 0 auto;
  box-shadow:0 8px 20px -6px rgba(99,102,241,.6);}
.shell{display:flex;min-height:100vh;}
.sidebar{width:248px;background:linear-gradient(180deg,var(--panel),var(--bg2));border-right:1px solid var(--line);
  padding:20px 16px;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;}
.brand{display:flex;gap:12px;align-items:center;margin:6px 6px 26px;}
.brand strong{display:block;font-size:15px;letter-spacing:-.01em;} .brand span{display:block;line-height:1.3;margin-top:2px;}
nav{display:flex;flex-direction:column;gap:3px;}
nav a{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:10px;color:var(--muted);
  font-weight:500;transition:background .15s,color .15s,transform .15s;}
nav a svg{width:18px;height:18px;flex:0 0 auto;opacity:.85;}
nav a:hover{background:var(--panel2);color:var(--txt);}
nav a.active{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;
  box-shadow:0 8px 18px -8px rgba(99,102,241,.7);}
nav a.active svg{opacity:1;}
main{flex:1;display:flex;flex-direction:column;min-width:0;}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:16px 32px;
  border-bottom:1px solid var(--line);background:rgba(20,29,49,.7);backdrop-filter:blur(10px);
  position:sticky;top:0;z-index:5;}
.crumb{font-weight:700;font-size:17px;letter-spacing:-.01em;}
.actions{display:flex;gap:14px;align-items:center;}
.langsw{display:inline-flex;background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:2px;}
.langsw a{padding:4px 9px;border-radius:6px;color:var(--muted);font-weight:600;font-size:12px;}
.langsw a.on{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;}
.content{padding:30px 32px;max-width:1040px;width:100%;}
h2{font-size:22px;margin-bottom:20px;letter-spacing:-.02em;} h3{font-size:15px;margin:18px 0 12px;letter-spacing:-.01em;}
.lead{font-size:15px;color:var(--muted);margin-bottom:22px;line-height:1.65;max-width:760px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:18px;}
.card{background:linear-gradient(180deg,var(--panel),var(--bg2));border:1px solid var(--line);
  border-radius:var(--radius);padding:22px;margin-bottom:18px;box-shadow:var(--shadow);}
.tile{background:linear-gradient(180deg,var(--panel),var(--bg2));border:1px solid var(--line);
  border-radius:var(--radius);padding:18px;box-shadow:var(--shadow);transition:transform .15s,border-color .15s;}
.tile:hover{transform:translateY(-2px);border-color:var(--line2);}
.tile-top{display:flex;align-items:center;gap:10px;margin-bottom:12px;color:var(--muted);}
.tile-top svg{width:18px;height:18px;}
.tile .big{display:block;margin-top:8px;}
.badge{display:inline-flex;align-items:center;gap:7px;padding:6px 11px;border-radius:999px;font-size:12.5px;font-weight:600;}
.badge .dot{width:7px;height:7px;border-radius:50%;}
.badge.ok{background:var(--green-bg);color:var(--green);} .badge.ok .dot{background:var(--green);box-shadow:0 0 0 3px rgba(52,211,153,.18);}
.badge.no{background:var(--red-bg);color:var(--red);} .badge.no .dot{background:var(--red);box-shadow:0 0 0 3px rgba(248,113,113,.18);}
.fld{display:block;margin-bottom:16px;} .fld span{display:block;margin-bottom:7px;color:var(--muted);font-size:13px;font-weight:500;}
input,select,textarea{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:10px;background:var(--bg);
  color:var(--txt);font-size:14px;outline:none;font-family:inherit;transition:border-color .15s,box-shadow .15s;}
input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(99,102,241,.18);}
input[readonly]{color:var(--muted);cursor:pointer;}
.fld small{display:block;margin-top:6px;font-size:12px;line-height:1.5;}
.row{display:flex;gap:14px;flex-wrap:wrap;} .row .fld{flex:1;min-width:160px;}
.btn{padding:11px 20px;border:none;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent2));
  color:#fff;font-weight:600;cursor:pointer;font-size:14px;transition:transform .12s,box-shadow .15s,opacity .15s;
  box-shadow:0 8px 18px -8px rgba(99,102,241,.7);}
.btn:hover{transform:translateY(-1px);} .btn:active{transform:translateY(0);}
.btn.ghost{background:var(--panel2);border:1px solid var(--line);color:var(--txt);box-shadow:none;}
.btn.ghost:hover{border-color:var(--line2);background:var(--panel);}
.btn.tiny{padding:6px 12px;font-size:12.5px;}
.inline{display:inline-flex;gap:8px;align-items:center;margin:0 12px 10px 0;}
.inline input{width:auto;}
table{width:100%;border-collapse:separate;border-spacing:0;background:linear-gradient(180deg,var(--panel),var(--bg2));
  border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);}
th,td{text-align:left;padding:13px 16px;border-bottom:1px solid var(--line);vertical-align:middle;}
th{color:var(--muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;background:rgba(0,0,0,.12);}
tbody tr{transition:background .12s;} tbody tr:hover{background:rgba(255,255,255,.02);}
tr:last-child td{border-bottom:none;}
.pill{display:inline-block;padding:4px 11px;border-radius:999px;background:var(--panel2);font-size:12px;font-weight:600;border:1px solid var(--line);}
.pill-pending{color:var(--amber);background:rgba(251,191,36,.1);border-color:rgba(251,191,36,.25);}
.pill-sent{color:var(--green);background:var(--green-bg);border-color:rgba(52,211,153,.25);}
.pill-failed,.pill-cancelled{color:var(--red);background:var(--red-bg);border-color:rgba(248,113,113,.25);}
.flash{background:var(--green-bg);border:1px solid rgba(52,211,153,.4);color:var(--green);padding:13px 17px;
  border-radius:10px;margin-bottom:20px;word-break:break-word;font-weight:500;}
.flash-err{background:var(--red-bg);border-color:rgba(248,113,113,.4);color:var(--red);}
.warn{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.3);color:#fcd34d;padding:13px 17px;
  border-radius:10px;margin-bottom:20px;font-size:13px;line-height:1.55;}
.step{background:linear-gradient(180deg,var(--panel),var(--bg2));border:1px solid var(--line);border-left:3px solid var(--accent);
  border-radius:12px;padding:18px 22px;margin-bottom:14px;box-shadow:var(--shadow);}
.step.accent{border-left-color:var(--accent2);} .step p{line-height:1.65;color:var(--muted);} .step b{color:var(--txt);font-weight:600;}
.tabs{display:inline-flex;gap:4px;margin-bottom:16px;background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:3px;}
.tabs a{padding:7px 14px;border-radius:8px;color:var(--muted);font-size:13px;font-weight:500;}
.tabs a.on{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;}
.login{background:linear-gradient(180deg,var(--panel),var(--bg2));padding:40px 38px;border-radius:18px;width:360px;
  text-align:center;border:1px solid var(--line);box-shadow:0 30px 60px -20px rgba(0,0,0,.7);}
.login .logo{margin:0 auto 18px;width:52px;height:52px;font-size:24px;} .login h1{font-size:21px;margin-bottom:5px;letter-spacing:-.02em;}
.login input{margin:9px 0;} .login button{width:100%;margin-top:10px;}
.err{color:var(--red);font-size:13px;margin-bottom:8px;}
@media(max-width:760px){.sidebar{width:64px;padding:16px 8px;} .brand span,.brand strong,nav a span{display:none;}
  .brand{justify-content:center;} nav a{justify-content:center;padding:11px;} .content{padding:20px;}}
</style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php }
