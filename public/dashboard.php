<?php
declare(strict_types=1);

/**
 * Bitrix24 Glue control panel — sidebar + header layout, EN/IT, with Setup
 * (DB-backed settings), connection tests, queues/logs and a translated
 * Instructions page. Single self-contained file in the house dashboard style.
 */
require __DIR__ . '/../src/Bootstrap.php';

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
        if (hash_equals((string)Config::get('dashboard.password', ''), (string)$_POST['password'])) {
            $_SESSION['glue_auth'] = true;
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
    <input type="password" name="password" placeholder="<?= $h($t('login_ph')) ?>" autofocus>
    <button type="submit"><?= $h($t('login_btn')) ?></button>
  </form>
</body></html>
<?php }

function render_head(callable $t, callable $h, string $lang, string $tab, ?string $flash, string $flashType): void {
    $nav = [
        'overview'    => 'nav_overview', 'leads' => 'nav_leads',
        'reminders'   => 'nav_reminders', 'messages' => 'nav_messages',
        'campaigns'   => 'nav_campaigns', 'events' => 'nav_events',
        'instructions'=> 'nav_instr', 'settings' => 'nav_settings',
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
        <a class="<?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= $h($key) ?>"><?= $h($t($label)) ?></a>
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
      stat_card($h, $t('st_db'), $db ? $t('configured') : $t('not_configured'), $db);
      stat_card($h, $t('st_bitrix'), $bitrixOk ? $t('configured') : $t('not_configured'), $bitrixOk);
      stat_card($h, $t('st_whatsapp'), $waOk ? $t('configured') : $t('not_configured'), $waOk);
      stat_card($h, $t('st_mail'), $mailOk ? $t('configured') : $t('not_configured'), $mailOk);
      ?>
    </div>
    <div class="grid">
      <?php
      num_card($h, $t('ov_pending'), $pending);
      num_card($h, $t('ov_sent'), $sent);
      num_card($h, $t('ov_failed'), $failed);
      num_card($h, $t('ov_leads'), $leads);
      num_card($h, $t('ov_campaigns'), $camps);
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

// ============================ ui bits ============================

function stat_card(callable $h, string $label, string $val, bool $ok): void {
    echo '<div class="card stat"><span class="muted small">' . $h($label) . '</span>'
        . '<span class="dot ' . ($ok ? 'green' : 'red') . '"></span>'
        . '<strong>' . $h($val) . '</strong></div>';
}
function num_card(callable $h, string $label, int $n): void {
    echo '<div class="card stat"><span class="muted small">' . $h($label) . '</span>'
        . '<strong class="big">' . $h((string)$n) . '</strong></div>';
}
function fld(callable $h, string $name, string $label, $value, string $hint = ''): void {
    echo '<label class="fld"><span>' . $h($label) . '</span>'
        . '<input name="' . $h($name) . '" value="' . $h($value) . '">'
        . ($hint ? '<small class="muted">' . $h($hint) . '</small>' : '') . '</label>';
}

function css(): void { ?>
<style>
:root{--bg:#0f172a;--panel:#1e293b;--panel2:#162032;--line:#334155;--txt:#e2e8f0;--muted:#94a3b8;--accent:#6366f1;--accent2:#8b5cf6;--green:#22c55e;--red:#ef4444;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--txt);font-size:14px;}
.center{display:flex;align-items:center;justify-content:center;height:100vh;}
.muted{color:var(--muted);} .small{font-size:12px;} .big{font-size:28px;}
a{color:inherit;text-decoration:none;}
.logo{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:18px;flex:0 0 auto;}
.shell{display:flex;min-height:100vh;}
.sidebar{width:240px;background:var(--panel);border-right:1px solid var(--line);padding:18px;position:sticky;top:0;height:100vh;}
.brand{display:flex;gap:12px;align-items:center;margin-bottom:24px;}
.brand strong{display:block;} .brand span{display:block;}
nav{display:flex;flex-direction:column;gap:4px;}
nav a{padding:10px 12px;border-radius:8px;color:var(--muted);}
nav a:hover{background:var(--panel2);color:var(--txt);}
nav a.active{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;}
main{flex:1;display:flex;flex-direction:column;min-width:0;}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:16px 28px;border-bottom:1px solid var(--line);background:var(--panel);position:sticky;top:0;z-index:5;}
.crumb{font-weight:600;font-size:16px;}
.actions{display:flex;gap:12px;align-items:center;}
.langsw a{padding:4px 8px;border-radius:6px;color:var(--muted);font-weight:600;font-size:12px;}
.langsw a.on{background:var(--panel2);color:#fff;}
.content{padding:28px;max-width:1000px;}
h2{font-size:20px;margin-bottom:16px;} h3{font-size:15px;margin:18px 0 10px;}
.lead{font-size:15px;color:var(--muted);margin-bottom:20px;line-height:1.6;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:18px;}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:18px;}
.stat{display:flex;flex-direction:column;gap:6px;position:relative;margin-bottom:0;}
.dot{width:10px;height:10px;border-radius:50%;position:absolute;top:18px;right:18px;}
.dot.green{background:var(--green);} .dot.red{background:var(--red);}
.fld{display:block;margin-bottom:14px;} .fld span{display:block;margin-bottom:6px;color:var(--muted);font-size:13px;}
input,select,textarea{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--txt);font-size:14px;outline:none;font-family:inherit;}
input:focus,select:focus,textarea:focus{border-color:var(--accent);}
.fld small{display:block;margin-top:5px;font-size:12px;}
.row{display:flex;gap:12px;} .row .fld{flex:1;}
.btn{padding:10px 18px;border:none;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-weight:600;cursor:pointer;font-size:14px;}
.btn:hover{opacity:.92;} .btn.ghost{background:transparent;border:1px solid var(--line);color:var(--txt);} .btn.tiny{padding:5px 10px;font-size:12px;}
.inline{display:inline-flex;gap:8px;align-items:center;margin:0 14px 12px 0;}
.inline input{width:auto;}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);border-radius:12px;overflow:hidden;}
th,td{text-align:left;padding:11px 14px;border-bottom:1px solid var(--line);vertical-align:top;}
th{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
tr:last-child td{border-bottom:none;}
.pill{padding:3px 9px;border-radius:999px;background:var(--panel2);font-size:12px;border:1px solid var(--line);}
.pill-pending{color:#fbbf24;} .pill-sent{color:var(--green);} .pill-failed,.pill-cancelled{color:var(--red);}
.flash{background:#064e3b;border:1px solid #10b981;color:#d1fae5;padding:12px 16px;border-radius:8px;margin-bottom:18px;word-break:break-word;}
.flash-err{background:#4c1d1d;border-color:#ef4444;color:#fee2e2;}
.warn{background:#422006;border:1px solid #b45309;color:#fde68a;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;}
.step{background:var(--panel);border:1px solid var(--line);border-left:3px solid var(--accent);border-radius:10px;padding:16px 20px;margin-bottom:14px;}
.step.accent{border-left-color:var(--accent2);} .step p{line-height:1.6;color:var(--muted);} .step b{color:var(--txt);}
.tabs{display:flex;gap:6px;margin-bottom:14px;} .tabs a{padding:6px 12px;border-radius:8px;color:var(--muted);border:1px solid var(--line);font-size:13px;} .tabs a.on{background:var(--panel2);color:#fff;}
.login{background:var(--panel);padding:36px;border-radius:14px;width:340px;text-align:center;border:1px solid var(--line);}
.login .logo{margin:0 auto 16px;} .login h1{font-size:20px;margin-bottom:4px;} .login input{margin:16px 0;text-align:center;} .login button{width:100%;}
.err{color:#f87171;font-size:13px;}
</style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php }
