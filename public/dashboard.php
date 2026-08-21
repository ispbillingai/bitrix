<?php
declare(strict_types=1);

/**
 * Standalone CRM control panel — sidebar + header layout, EN/IT, DB-backed
 * settings, leads/deals kanban, contacts, appointments, tasks/KPI, agents,
 * campaigns and the message/automation logs. Thin controller: it handles auth +
 * POST actions, then includes a per-page view from /views. House dashboard style.
 */
require __DIR__ . '/../src/Bootstrap.php';
// svg() + css(): the shell's icon set and stylesheet, shared with the partner
// area. Required at the top, not the bottom — a require runs when it is reached,
// so loading it after the render would be too late (unlike a function declared
// in this file, which is hoisted).
require_once __DIR__ . '/../views/_ui.php';

use Glue\Auth;
use Glue\Bootstrap;
use Glue\Bitrix\Client;
use Glue\Campaign\Sender;
use Glue\Config;
use Glue\Crm\Activities;
use Glue\Crm\Appointments;
use Glue\Crm\Contacts;
use Glue\Crm\Deals;
use Glue\Crm\Leads;
use Glue\Crm\Pipelines;
use Glue\Crm\Tasks;
use Glue\Crm\Tickets;
use Glue\Db;
use Glue\Event\Log;
use Glue\Notify\Notifier;
use Glue\Notify\TextMeBot;
use Glue\Pay\Contracts as PayContracts;
use Glue\Reminder\Scheduler;
use Glue\Settings;
use Glue\Sibill\Client as SibillClient;
use Glue\Sibill\Customers as SibillCustomers;
use Glue\Sibill\Invoices as SibillInvoices;
use Glue\Sign\Documents as SignDocs;

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
// flash left by a previous redirect (post/redirect/get)
if (!empty($_SESSION['dash_flash'])) {
    [$flash, $flashType] = $_SESSION['dash_flash'];
    unset($_SESSION['dash_flash']);
}
if (!isset($_SESSION['glue_auth'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $username = trim((string)($_POST['username'] ?? ''));
        $user = Auth::verify($username, (string)$_POST['password']);
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
$uid = (int)($_SESSION['glue_user']['id'] ?? 0) ?: null;

// Cron-less dispatch: flush any due time-delayed reminders on page load (throttled
// to once a minute app-wide). Instant messages — welcome, agent-assigned, closing —
// already send the moment they fire, so this only catches inactivity/sign cadences.
// Best-effort: a dispatch error must never blank the dashboard.
try { (new Scheduler())->tickWeb(); } catch (Throwable $e) {
    Log::write('scheduler', 'web_tick_failed', null, null, ['error' => $e->getMessage()]);
}

// ---- role-based access ----
// Agents see a restricted panel: only their own leads/deals/appointments/tasks,
// no settings/agents/campaigns/global logs. Admins (and the master login) see all.
$role    = (string)($_SESSION['glue_user']['role'] ?? 'admin');
$isAgent = $role === 'agent';
// Technical-area users: not a CRM role — they only see network monitoring
// (Devices + Network areas), no leads/deals/etc. Not scoped like agents.
$isTech  = $role === 'tech';
$scopeId = $isAgent ? (int)($_SESSION['glue_user']['id'] ?? 0) : null; // null = no scope (admin)
// Admin-only pipeline filter: ?agent=<id> narrows the Leads/Deals boards (and the
// overview) to one seller. Agents are always hard-scoped to themselves and ignore it.
$filterAgentId = (!$isAgent && !empty($_GET['agent'])) ? (int)$_GET['agent'] : null;
if ($filterAgentId !== null) {
    $scopeId = $filterAgentId;
}
// Admin-only too: ?partner=<id> narrows the Leads board to the leads one partner
// brought in — entered in their own area or through their referral link.
$filterPartnerId = (!$isAgent && !empty($_GET['partner'])) ? (int)$_GET['partner'] : null;
$agentViews   = ['overview', 'leads', 'deals', 'appointments', 'tasks', 'messages', 'tickets', 'documents', 'instructions'];
$techViews    = ['devices', 'network_areas'];
$agentActions = [
    'lead_create', 'lead_move', 'lead_convert', 'lead_note', 'lead_edit',
    'deal_move', 'deal_note', 'deal_invite',
    'appt_create', 'appt_schedule', 'appt_status',
    'task_complete', 'task_status', 'ticket_reply', 'ticket_status', 'ticket_open_staff', 'change_my_password',
    'doc_create', 'doc_send', 'doc_void',
];

// ---- ticket attachment download (?dl=<message_id>) ----
if (isset($_GET['dl'])) {
    $msg = Tickets::messageFile((int)$_GET['dl']);
    // Admin can fetch anything; an agent only files on tickets assigned to them.
    if ($msg && (!$isAgent || (int)$msg['assigned_agent_id'] === $scopeId)) {
        Tickets::streamAttachment($msg);
    }
    http_response_code(404);
    exit('Not found');
}

// ---- signed-document download (?sdl=<id>&k=orig|signed) ----
// Nothing under storage/sign is web-reachable; this is the only way out, and an
// agent only reaches the documents they raised.
if (isset($_GET['sdl'])) {
    $sdoc = SignDocs::find((int)$_GET['sdl']);
    if ($sdoc && (!$isAgent || (int)$sdoc['created_by'] === $scopeId)) {
        $wantSigned = ($_GET['k'] ?? 'orig') === 'signed';
        $path = $wantSigned ? SignDocs::signedPath($sdoc) : SignDocs::originalPath($sdoc);
        if ($path !== null) {
            SignDocs::stream($path, $wantSigned ? 'signed-' . $sdoc['uid'] . '.pdf' : (string)$sdoc['orig_name']);
        }
    }
    http_response_code(404);
    exit('Not found');
}

// ---- leads export (?export=leads&m=YYYY-MM[&src=cashmatic]) — admin only ----
// Excel-compatible CSV of the leads received in a month (optionally one source),
// including each lead's full processing trail (stage moves + agent notes).
if (($_GET['export'] ?? '') === 'leads' && !$isAgent) {
    $xm  = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
    $xsrc = mb_strtolower(trim((string)($_GET['src'] ?? '')));
    // ?partner=<id> exports just that partner's leads — the filter on the board,
    // carried into the spreadsheet, so "show me what this partner brought" is one
    // click from the answer on screen.
    $xpid = (int)($_GET['partner'] ?? 0);
    $sql = "SELECT l.*, u.username AS agent_username, u.full_name AS agent_name,
                   c.username AS creator_username, c.full_name AS creator_name,
                   pt.name AS partner_name, ct.company AS company
            FROM leads l
            LEFT JOIN users u ON u.id = l.assigned_to
            LEFT JOIN users c ON c.id = l.created_by
            LEFT JOIN partners pt ON pt.id = l.referred_by_partner_id
            LEFT JOIN contacts ct ON ct.id = l.contact_id
            WHERE l.received_at >= CONCAT(?, '-01')
              AND l.received_at <  CONCAT(?, '-01') + INTERVAL 1 MONTH"
        . ($xsrc !== '' ? ' AND l.source = ?' : '')
        . ($xpid > 0 ? ' AND l.referred_by_partner_id = ' . $xpid : '') . ' ORDER BY l.received_at';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($xsrc !== '' ? [$xm, $xm, $xsrc] : [$xm, $xm]);
    $xrows = $stmt->fetchAll();

    $fname = 'leads_' . ($xpid > 0 ? 'partner' . $xpid : ($xsrc !== '' ? $xsrc : 'all')) . '_' . $xm . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
    $sep = ';';                   // Italian Excel expects semicolons
    fputcsv($out, ['ID', $t('th_created'), $t('f_name'), $t('f_phone'), $t('f_email'), $t('f_company'), $t('f_vat'),
        $t('f_source'), $t('f_zone'), $t('f_fair'), $t('f_fair_city'),
        $t('th_stage'), $t('th_status'), $t('th_agent'), $t('entered_by'), $t('th_partner'),
        $t('f_message'), $t('exp_processing')], $sep);
    foreach ($xrows as $xr) {
        $trail = [];
        foreach (array_reverse(Activities::forEntity('lead', (int)$xr['id'], 200)) as $a) {
            $who = $a['full_name'] ?: ($a['username'] ?: $t('system'));
            $trail[] = '[' . $a['created_at'] . '] ' . $who . ': ' . $a['body'];
        }
        fputcsv($out, [
            $xr['id'], $xr['received_at'], $xr['customer_name'], $xr['customer_phone'],
            $xr['customer_email'], (string)($xr['company'] ?? ''), (string)($xr['vat_number'] ?? ''), $xr['source'],
            (string)($xr['zone'] ?? ''), (string)($xr['fair_name'] ?? ''), (string)($xr['fair_city'] ?? ''),
            stage_label($t, (string)$xr['stage_code'], Pipelines::label('lead', (string)$xr['stage_code'])),
            $xr['status'], $xr['agent_name'] ?: ($xr['agent_username'] ?: ''),
            // Blank creator = the lead arrived on its own (form/API), not keyed in.
            $xr['creator_name'] ?: ($xr['creator_username'] ?: $t('entered_inbound')),
            // Which partner brought it in ('' = none). Its own column: "entered by"
            // above reads "came in from the web" for a partner lead, since no CRM
            // user typed it, and that alone hid who the lead actually came from.
            (string)($xr['partner_name'] ?? ''),
            (string)$xr['comments'], implode("\n", $trail),
        ], $sep);
    }
    fclose($out);
    exit;
}

// ---- POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    $ajax = ($_POST['ajax'] ?? '') === '1';
    // Agents may only run their own whitelisted actions; block admin actions.
    if ($isAgent && !in_array($do, $agentActions, true)) {
        if ($ajax) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
        $flash = $t('not_allowed');
        $flashType = 'err';
        $do = ''; // fall through the switch without matching any case
    }
    // ...and only on records assigned to them (block IDOR via a forged id). An
    // unassigned or non-existent record reads as owner 0 and is denied too.
    if ($isAgent && $do !== '') {
        $rid = (int)($_POST['id'] ?? 0);
        $ownerCol = ['lead_' => ['leads', 'assigned_to'], 'deal_' => ['deals', 'assigned_to'],
                     'appt_' => ['appointments', 'agent_id'], 'task_' => ['tasks', 'assigned_to'],
                     'ticket_' => ['tickets', 'assigned_agent_id'],
                     'doc_' => ['sign_documents', 'created_by']];
        $needsOwner = null;
        foreach ($ownerCol as $prefix => $tc) {
            if (str_starts_with($do, $prefix)) { $needsOwner = $tc; break; }
        }
        // Only check existing-record actions (those carrying an id). Create actions
        // like appt_create have no id and set ownership themselves.
        if ($needsOwner !== null && $rid > 0) {
            [$table, $col] = $needsOwner;
            $owner = (int)$pdo->query("SELECT $col FROM $table WHERE id = $rid")->fetchColumn();
            if ($owner !== $scopeId) {
                if ($ajax) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
                $flash = $t('not_allowed');
                $flashType = 'err';
                $do = '';
            }
        }
    }
    try {
        switch ($do) {
            // ---------- settings ----------
            case 'save_settings':
                $allowed = [
                    'app.company_name', 'app.default_lang', 'app.timezone', 'app.base_url', 'app.intake_secret',
                    'app.default_country_code',
                    'crm.currency', 'crm.deal_quote_stage',
                    'reminders.lead_inactivity_hours', 'reminders.deal_inactivity_hours',
                    'reminders.lead_nudge_repeat_hours', 'reminders.lead_customer_after_hours',
                    'reminders.sign_after_sent_days',
                    'reminders.sign_overdue_every_days', 'reminders.sign_overdue_max_days',
                    'reminders.sign_due_default_days',
                    'reminders.appointment_offsets_min', 'reminders.sign_before_due_days', 'reminders.offer_read_days',
                    'textmebot.api_key', 'mail.from_name', 'mail.from_email',
                    'mail.smtp.host', 'mail.smtp.port', 'mail.smtp.user', 'mail.smtp.pass', 'mail.smtp.secure',
                    'logistics.email', 'logistics.phone',
                    'bitrix.sync_enabled', 'bitrix.base_url', 'bitrix.outbound_secret',
                    'sibill.enabled', 'sibill.api_key', 'sibill.company_id',
                    'sibill.sync_minutes', 'sibill.sync_months',
                    'sibill.chase_enabled', 'sibill.chase_from_date',
                    'sibill.chase_every_days', 'sibill.chase_min_days_late',
                    'sibill.chase_min_amount', 'sibill.chase_max_per_run', 'sibill.chase_channel',
                    'sibill.chase_hour_from', 'sibill.chase_hour_to',
                    'leads_mailbox.host', 'leads_mailbox.port', 'leads_mailbox.user',
                    'leads_mailbox.pass', 'leads_mailbox.poll_minutes',
                    'smallpay.enabled', 'smallpay.env', 'smallpay.id_merchant', 'smallpay.unique_id',
                    'smallpay.service_id', 'smallpay.domain', 'smallpay.reference_prefix',
                    'smallpay.sync_minutes', 'smallpay.modify_installments',
                    'smallpay.notify_customer_on_failure',
                ];
                // PHP rewrites dots in POST field names to underscores, so a field
                // named 'mail.from_email' actually arrives as 'mail_from_email'.
                // Read the rewritten key (fall back to the exact one just in case).
                $post = static function (string $k) {
                    $mangled = str_replace('.', '_', $k);
                    if (array_key_exists($mangled, $_POST)) { return $_POST[$mangled]; }
                    if (array_key_exists($k, $_POST))       { return $_POST[$k]; }
                    return null;
                };
                $pairs = [];
                foreach ($allowed as $k) {
                    $v = $post($k);
                    if ($v !== null) {
                        $pairs[$k] = trim((string)$v);
                    }
                }
                // checkbox: present only when ticked
                $pairs['bitrix.sync_enabled'] = $post('bitrix.sync_enabled') !== null ? 'true' : 'false';
                $pairs['sibill.enabled'] = $post('sibill.enabled') !== null ? 'true' : 'false';
                $pairs['sibill.chase_enabled'] = $post('sibill.chase_enabled') !== null ? 'true' : 'false';
                $pairs['leads_mailbox.enabled'] = $post('leads_mailbox.enabled') !== null ? 'true' : 'false';
                $pairs['smallpay.enabled'] = $post('smallpay.enabled') !== null ? 'true' : 'false';
                $pairs['smallpay.modify_installments'] = $post('smallpay.modify_installments') !== null ? 'true' : 'false';
                $pairs['smallpay.notify_customer_on_failure'] = $post('smallpay.notify_customer_on_failure') !== null ? 'true' : 'false';
                // Sender allow-list: comma-separated in the UI, stored as JSON so
                // the config overlay yields an array. Emptied = fall back to the
                // config.php default rather than "accept everyone".
                if (($af = $post('leads_mailbox.allowed_from')) !== null) {
                    $senders = array_values(array_filter(array_map('trim', explode(',', (string)$af)), 'strlen'));
                    $pairs['leads_mailbox.allowed_from'] = $senders === [] ? '' : json_encode($senders);
                }
                // Sender => source ("noreply@cashmatic.eu = cashmatic, ..."), so
                // each partner's email leads are counted under their own label.
                // Stored as a JSON object; emptied = back to the config.php map.
                if (($sm = $post('leads_mailbox.source_by_sender')) !== null) {
                    $map = [];
                    foreach (explode(',', (string)$sm) as $entry) {
                        [$sender, $source] = array_pad(explode('=', $entry, 2), 2, '');
                        if (trim($sender) !== '' && trim($source) !== '') {
                            $map[mb_strtolower(trim($sender))] = mb_strtolower(trim($source));
                        }
                    }
                    $pairs['leads_mailbox.source_by_sender'] = $map === [] ? '' : json_encode($map);
                }
                // Welcome image (sent with the first-contact lead message on both
                // channels). Stored under /uploads with a fixed name; the setting
                // keeps the site-relative path. The clear checkbox removes it.
                if (!empty($_POST['welcome_lead_image_clear'])) {
                    foreach (glob(__DIR__ . '/uploads/welcome-lead.*') ?: [] as $old) { @unlink($old); }
                    $pairs['welcome.lead_image'] = '';
                } elseif (!empty($_FILES['welcome_lead_image']['tmp_name'])
                    && is_uploaded_file($_FILES['welcome_lead_image']['tmp_name'])) {
                    $ext = strtolower(pathinfo((string)$_FILES['welcome_lead_image']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)
                        && str_starts_with((string)mime_content_type($_FILES['welcome_lead_image']['tmp_name']), 'image/')) {
                        foreach (glob(__DIR__ . '/uploads/welcome-lead.*') ?: [] as $old) { @unlink($old); }
                        $dest = __DIR__ . '/uploads/welcome-lead.' . $ext;
                        if (move_uploaded_file($_FILES['welcome_lead_image']['tmp_name'], $dest)) {
                            $pairs['welcome.lead_image'] = '/uploads/welcome-lead.' . $ext;
                        }
                    } else {
                        $flashType = 'err';
                        $flash = $t('welcome_img_bad');
                    }
                }
                // Comma/space-separated number lists -> JSON arrays (so Config::get
                // returns an array the cadence code can loop over). Clearing a field
                // stores '' so it falls back to the built-in default.
                foreach (['reminders.appointment_offsets_min', 'reminders.sign_before_due_days', 'reminders.offer_read_days'] as $lk) {
                    if (array_key_exists($lk, $pairs)) {
                        $nums = array_values(array_filter(array_map(
                            'intval', preg_split('/[\s,]+/', (string)$pairs[$lk], -1, PREG_SPLIT_NO_EMPTY) ?: []
                        ), static fn($n) => $n > 0));
                        $pairs[$lk] = $nums ? json_encode($nums) : '';
                    }
                }
                Settings::setMany($pairs);
                // Config was overlaid once at boot; re-apply so the form below this
                // request reflects the values we just saved (not the pre-save snapshot).
                Config::applyOverlay(Settings::nested());
                $flash = $t('saved') . ' · ' . count($pairs) . ' ' . $t('settings_saved_n');
                $tab = 'settings';
                break;

            case 'save_templates':
                // Save the custom reminder/notification copy for one language. A
                // blank field, or one left equal to the shipped default, clears the
                // override so the default is used again.
                $tlang = in_array($_POST['tpl_lang'] ?? '', ['en', 'it'], true) ? (string)$_POST['tpl_lang'] : $lang;
                $saved = 0;
                foreach (\Glue\Reminder\Templates::ruleKeys() as $rk) {
                    foreach (['wa' => "tpl_wa_$rk", 'es' => "tpl_es_$rk", 'eh' => "tpl_eh_$rk"] as $kind => $field) {
                        if (!array_key_exists($field, $_POST)) { continue; }
                        $val = trim((string)$_POST[$field]);
                        $key = \Glue\Reminder\Templates::key($kind, $rk, $tlang);
                        if ($val === '' || $val === trim(\Glue\Reminder\Templates::defaultText($kind, $rk, $tlang))) {
                            Settings::set($key, null); // revert to default
                        } else {
                            Settings::set($key, $val);
                            $saved++;
                        }
                    }
                }
                $flash = $t('saved') . ' · ' . $saved . ' ' . $t('tpl_saved_n');
                $tab = 'templates';
                break;

            case 'stage_add':
                $pid = (int)$_POST['pipeline_id'];
                $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)$_POST['code']));
                if ($pid && $code !== '') {
                    $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0)+1 FROM stages WHERE pipeline_id=$pid")->fetchColumn();
                    $st = $pdo->prepare('INSERT INTO stages (pipeline_id, code, name, sort, color) VALUES (?,?,?,?,?)
                                         ON DUPLICATE KEY UPDATE name=VALUES(name)');
                    $st->execute([$pid, $code, trim((string)$_POST['name']) ?: $code, $maxSort, '#5b6cff']);
                    Pipelines::clearCache();
                }
                $flash = $t('saved');
                $tab = 'settings';
                break;

            case 'stage_delete':
                $pdo->prepare('DELETE FROM stages WHERE id=? AND is_first=0 AND is_won=0 AND is_lost=0')
                    ->execute([(int)$_POST['id']]);
                Pipelines::clearCache();
                $tab = 'settings';
                break;

            // ---------- leads ----------
            case 'lead_create':
                // 90-day VAT exclusivity: the first enterer of a VAT number owns
                // it; someone else re-entering it is blocked and notified.
                $vat = \Glue\Crm\VatLock::normalize((string)($_POST['vat_number'] ?? ''));
                if ($vat !== '') {
                    $vc = \Glue\Crm\VatLock::claim($vat, 'agent', (int)$uid);
                    if (!$vc['ok']) {
                        \Glue\Crm\VatLock::notifyTaken('agent', (int)$uid, $vat, (string)$vc['available_at']);
                        if (!empty($vc['lead_id'])) {
                            Activities::add('lead', (int)$vc['lead_id'], 'system',
                                "Blocked duplicate entry of VAT $vat (locked until " . date('d/m/Y', strtotime((string)$vc['available_at'])) . ')', $uid);
                        }
                        $flash = sprintf($t('vat_taken_flash'), $vat, date('d/m/Y', strtotime((string)$vc['available_at'])));
                        $flashType = 'err';
                        $tab = 'leads';
                        break;
                    }
                }
                // Source comes from the dropdown; picking "+ new source…" (empty
                // value) uses the free-text field instead.
                $src = trim((string)($_POST['source'] ?? '')) ?: trim((string)($_POST['source_new'] ?? ''));
                // create() folds a duplicate into the lead that already exists and
                // hands that one back. Ask first, so the seller is told what
                // happened instead of reading "Saved" and expecting a new record.
                // Nome and Cognome are two boxes on the form and one stored name,
                // the same shape request.php and fair.php already post.
                $leadName = \Glue\Crm\Contacts::fullName(
                    (string)($_POST['first_name'] ?? ''), (string)($_POST['last_name'] ?? ''));
                $dupLeadId = Leads::duplicateId([
                    'name' => $leadName, 'phone' => $_POST['phone'] ?? '',
                    'email' => $_POST['email'] ?? '', 'vat_number' => $vat,
                    'source' => $src ?: 'manual',
                ]);
                $newLeadId = Leads::create([
                    'name' => $leadName, 'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? '',
                    'company' => $_POST['company'] ?? '', 'comments' => $_POST['comments'] ?? '',
                    'source' => $src ?: 'manual', 'zone' => $_POST['zone'] ?? '', 'lang' => $_POST['lang'] ?? null,
                    'vat_number' => $vat,
                    // set by the trade-fair form (#16); blank on the standard form
                    'fair_name' => $_POST['fair_name'] ?? '', 'fair_city' => $_POST['fair_city'] ?? '',
                ], $uid);
                // An agent's own entry is theirs: auto-assign so it shows in their
                // scope. Only when it really is a NEW lead — when the entry merged
                // into an existing one, create() handed back somebody's live lead,
                // and auto-assigning would hand it to whoever re-typed it (and
                // message the customer this seller's profile over the first one's).
                if ($isAgent && $scopeId && $dupLeadId === null) {
                    Leads::assign($newLeadId, $scopeId, $uid);
                }
                // The owner filing a lead on behalf of a partner who phoned it in:
                // same credit the ?ref= link and the partner's own area confer.
                // Admins only — sellers post this form too, and who earns the
                // commission is not theirs to set. Never onto a merged duplicate:
                // that would hand a live lead somebody else already owns to a
                // partner who happened to be named while re-typing it.
                $onBehalfOf = (!$isAgent && !empty($_POST['partner_id'])) ? (int)$_POST['partner_id'] : 0;
                if ($onBehalfOf > 0 && $dupLeadId === null) {
                    \Glue\Partner\Partners::setReferrer($newLeadId, $onBehalfOf, $uid);
                }
                if ($vat !== '' && !empty($vc['fresh'])) {
                    \Glue\Crm\VatLock::attachLead($vat, $newLeadId);
                    \Glue\Crm\VatLock::notifyThanks('agent', (int)$uid, $vat, trim((string)($_POST['name'] ?? '')));
                }
                if ($dupLeadId !== null) {
                    // Two different stories for the seller: an OPEN twin means
                    // "someone is on this", a CONVERTED one means "this is
                    // already our customer" — the request was grouped either way.
                    $dupRow = Leads::find($dupLeadId);
                    $key = ($dupRow && (string)$dupRow['status'] === 'converted')
                        ? 'lead_dup_customer_flash' : 'lead_dup_flash';
                    $flash = sprintf($t($key), $dupLeadId);
                    // Amber, not red: the request was not thrown away, it was written
                    // onto the twin as a note. Red read as "refused" and sent sellers
                    // hunting for something the CRM had already filed.
                    $flashType = 'warn';
                } else {
                    $flash = $t('saved');
                }
                $tab = 'leads';
                // Post/redirect/get, as the ticket actions below already do. Saving a
                // lead holds the page for 5-6 seconds — the welcome WhatsApp goes out
                // inline, and TextMeBot's rate-limit gap is waited out first — so a
                // seller who thinks the click missed reloads, and reloading a POST
                // result re-sends the entire form. That is how the Debora entry
                // arrived a second time, byte-identical, 18 seconds after the first,
                // and got reported as the CRM refusing to file the lead. Landing on a
                // GET makes reload, back and forward harmless.
                if (!$ajax) {
                    $_SESSION['dash_flash'] = [$flash, $flashType];
                    header('Location: ?tab=leads');
                    exit;
                }
                break;
            case 'lead_assign':
                Leads::assign((int)$_POST['id'], (int)$_POST['agent_id'], $uid);
                $flash = $t('saved');
                $tab = 'leads';
                break;
            case 'lead_move':
                Leads::moveStage((int)$_POST['id'], (string)$_POST['stage'], $uid);
                // Optional note describing how the contact evolved, recorded on the
                // same timeline as the stage change (agents fill it when moving).
                $moveNote = trim((string)($_POST['note'] ?? ''));
                if ($moveNote !== '') {
                    Activities::add('lead', (int)$_POST['id'], 'note', $moveNote, $uid);
                }
                if ($ajax) { echo json_encode(['ok' => true]); exit; }
                $tab = 'leads';
                break;
            case 'lead_delete': // admin only (not whitelisted for agents) — test-data cleanup
                Leads::delete((int)$_POST['id'], $uid);
                $flash = $t('lead_deleted');
                $tab = 'leads';
                break;
            case 'lead_convert':
                $dealId = Leads::convert((int)$_POST['id'], $uid);
                if (!$dealId) { // already converted (double-submit) or gone
                    $flash = $t('lead_already_converted');
                    $flashType = 'err';
                    $tab = 'leads';
                    break;
                }
                // Also send the customer their portal login link on conversion, so a
                // converted request gets into the portal straight away (same as the
                // manual "Send portal access" button on the deal). Best-effort.
                $portalNote = '';
                $convDeal = $dealId ? Deals::find($dealId) : null;
                $convContactId = (int)($convDeal['contact_id'] ?? 0);
                if ($convContactId > 0) {
                    $token = \Glue\Portal\Account::invite($convContactId);
                    \Glue\Portal\Account::sendInvite($convContactId, $token);
                    Activities::add('deal', $dealId, 'system', 'Portal access sent to customer', $uid);
                    $portalNote = ' · ' . $t('portal_sent');
                }
                $flash = $t('lead_converted') . ' #' . $dealId . $portalNote;
                $tab = 'deals';
                break;
            case 'lead_note':
                Activities::add('lead', (int)$_POST['id'], 'note', (string)$_POST['body'], $uid);
                $tab = 'leads';
                break;
            case 'lead_edit': // #15 edit a lead's name/other data
                // Only send keys the form actually posted so update() leaves the rest
                // untouched. A VAT change re-claims exclusivity for the enterer.
                $editData = [];
                foreach (['name', 'phone', 'email', 'company', 'source', 'zone', 'fair_name', 'fair_city', 'comments', 'lang'] as $ef) {
                    if (array_key_exists($ef, $_POST)) { $editData[$ef] = $_POST[$ef]; }
                }
                // The form posts the name in two boxes; rebuild the one stored name.
                if (array_key_exists('first_name', $_POST) || array_key_exists('last_name', $_POST)) {
                    $editData['name'] = \Glue\Crm\Contacts::fullName(
                        (string)($_POST['first_name'] ?? ''), (string)($_POST['last_name'] ?? ''));
                }
                $newVat = \Glue\Crm\VatLock::normalize((string)($_POST['vat_number'] ?? ''));
                $editLead = Leads::find((int)$_POST['id']);
                $oldVat = \Glue\Crm\VatLock::normalize((string)($editLead['vat_number'] ?? ''));
                if ($editLead && $newVat !== $oldVat && $newVat !== '') {
                    // Editing to a VAT owned by someone else is blocked, exactly like entry.
                    $vc = \Glue\Crm\VatLock::claim($newVat, 'agent', (int)$uid, (int)$_POST['id']);
                    if (!$vc['ok']) {
                        \Glue\Crm\VatLock::notifyTaken('agent', (int)$uid, $newVat, (string)$vc['available_at']);
                        $flash = sprintf($t('vat_taken_flash'), $newVat, date('d/m/Y', strtotime((string)$vc['available_at'])));
                        $flashType = 'err';
                        $tab = 'leads';
                        break;
                    }
                    if (!empty($vc['fresh'])) {
                        \Glue\Crm\VatLock::notifyThanks('agent', (int)$uid, $newVat, trim((string)($_POST['name'] ?? '')));
                    }
                }
                if (array_key_exists('vat_number', $_POST)) { $editData['vat_number'] = $newVat; }
                // An edit must not graft another customer's identity onto this
                // lead: creating "Andrea" with only a name (passes — a bare name
                // is not identity) and then editing his phone in was a working
                // recipe for a duplicate the create-time filter can never see.
                // Only CHANGED identifiers are checked — the pre-existing twins
                // share values as they stand, and keeping a value is not what
                // creates a duplicate — so a note edit on a twin still saves.
                if ($editLead) {
                    $probe = [];
                    if (array_key_exists('phone', $editData)
                        && \Glue\Notify\Notifier::normalizePhone((string)$editData['phone'])
                           !== (string)($editLead['customer_phone'] ?? '')) {
                        $probe['phone'] = $editData['phone'];
                    }
                    if (array_key_exists('email', $editData)
                        && mb_strtolower(trim((string)$editData['email']))
                           !== mb_strtolower((string)($editLead['customer_email'] ?? ''))) {
                        $probe['email'] = $editData['email'];
                    }
                    if ($newVat !== '' && $newVat !== $oldVat) {
                        $probe['vat_number'] = $newVat;
                    }
                    $ownerId = $probe ? Leads::duplicateId($probe, (int)$_POST['id']) : null;
                    if ($ownerId !== null) {
                        $flash = sprintf($t('lead_edit_dup_flash'), $ownerId);
                        $flashType = 'err';
                        $tab = 'leads';
                        break;
                    }
                }
                Leads::update((int)$_POST['id'], $editData, $uid);
                // Attribution is not a lead column update() knows about — it lives
                // on the partner side, and moving it is logged there. Admins only,
                // and only when the form actually carried the field: an empty value
                // is a real instruction ("no partner"), a missing one is not.
                if (!$isAgent && array_key_exists('partner_id', $_POST)) {
                    \Glue\Partner\Partners::setReferrer(
                        (int)$_POST['id'], ((int)$_POST['partner_id']) ?: null, $uid);
                }
                $flash = $t('lead_saved');
                $tab = 'leads';
                break;

            // ---------- deals ----------
            case 'deal_create':
                Deals::create([
                    'title' => $_POST['title'] ?? 'Deal', 'amount' => $_POST['amount'] ?? 0,
                    'currency' => $_POST['currency'] ?? null, 'name' => $_POST['name'] ?? '',
                    'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? '',
                    'assigned_to' => ($_POST['assigned_to'] ?? '') !== '' ? (int)$_POST['assigned_to'] : null,
                    'expected_close_date' => $_POST['expected_close_date'] ?? null,
                    'sign_due_date' => $_POST['sign_due_date'] ?? null,
                ], $uid);
                $flash = $t('saved');
                $tab = 'deals';
                break;
            case 'deal_assign':
                Deals::assign((int)$_POST['id'], (int)$_POST['agent_id'], $uid);
                $flash = $t('saved');
                $tab = 'deals';
                break;
            case 'deal_move':
                Deals::moveStage((int)$_POST['id'], (string)$_POST['stage'], $uid, $_POST['sign_due_date'] ?? null);
                if ($ajax) { echo json_encode(['ok' => true]); exit; }
                $tab = 'deals';
                break;
            case 'deal_note':
                Activities::add('deal', (int)$_POST['id'], 'note', (string)$_POST['body'], $uid);
                $tab = 'deals';
                break;
            case 'deal_delete': // admin only (not whitelisted for agents) — #13 remove a wrong/rejected deal
                Deals::delete((int)$_POST['id'], $uid);
                $flash = $t('deal_deleted');
                $tab = 'deals';
                break;
            case 'deal_invite': // create/refresh the customer's portal access and send the magic link
                $dealId = (int)$_POST['id'];
                $deal = Deals::find($dealId);
                if ($deal) {
                    $contactId = (int)($deal['contact_id'] ?? 0);
                    if ($contactId <= 0) {
                        $contactId = Contacts::findOrCreate([
                            'name' => $deal['customer_name'] ?? '', 'phone' => $deal['customer_phone'] ?? '',
                            'email' => $deal['customer_email'] ?? '', 'lang' => $deal['lang'] ?? null,
                        ]);
                        $pdo->prepare('UPDATE deals SET contact_id = ? WHERE id = ?')->execute([$contactId, $dealId]);
                    }
                    $token = \Glue\Portal\Account::invite($contactId);
                    \Glue\Portal\Account::sendInvite($contactId, $token);
                    Activities::add('deal', $dealId, 'system', 'Portal access sent to customer', $uid);
                    $flash = $t('portal_sent');
                } else {
                    $flash = $t('not_allowed');
                    $flashType = 'err';
                }
                $tab = 'deals';
                break;

            // ---------- documents (electronic signature) ----------
            case 'doc_create':
                // Collected as nome + cognome, stored as one name — the same shape
                // request.php and LeadIntake already use for contacts.
                $docName = trim(trim((string)($_POST['first_name'] ?? ''))
                    . ' ' . trim((string)($_POST['last_name'] ?? '')));
                $res = SignDocs::create([
                    'title'      => $_POST['title'] ?? '',
                    'contact_id' => (int)($_POST['contact_id'] ?? 0),
                    'name'       => $docName, 'phone' => $_POST['phone'] ?? '',
                    'email'      => $_POST['email'] ?? '', 'lang' => $_POST['lang'] ?? null,
                ], $_FILES['document'] ?? null, $uid);
                if ($res['ok']) {
                    // Sending is the normal case, so it is one action, not two.
                    $flash = !empty($_POST['send_now']) && SignDocs::send($res['id'], $uid)
                        ? $t('dc_sent') : $t('dc_created');
                } else {
                    $flash = $t('dc_err_' . $res['error']) !== 'dc_err_' . $res['error']
                        ? $t('dc_err_' . $res['error']) : $t('dc_err_save_failed');
                    $flashType = 'err';
                }
                $tab = 'documents';
                break;
            case 'doc_send':
                $flash = SignDocs::send((int)$_POST['id'], $uid) ? $t('dc_sent') : $t('not_allowed');
                $flashType = $flash === $t('not_allowed') ? 'err' : 'ok';
                $tab = 'documents';
                break;
            case 'doc_void':
                $flash = SignDocs::void((int)$_POST['id'], $uid) ? $t('dc_voided') : $t('not_allowed');
                $tab = 'documents';
                break;

            // ---------- contacts ----------
            case 'contact_create':
                Contacts::create([
                    'name' => $_POST['name'] ?? '', 'company' => $_POST['company'] ?? '',
                    'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? '',
                    'lang' => $_POST['lang'] ?? null, 'notes' => $_POST['notes'] ?? '',
                ]);
                $flash = $t('saved');
                $tab = 'contacts';
                break;

            // ---------- appointments ----------
            case 'appt_create':
                Appointments::request([
                    'name' => $_POST['name'] ?? '', 'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? '',
                    'preferred_at' => $_POST['preferred_at'] ?? '', 'title' => $_POST['title'] ?? null,
                    'notes' => $_POST['notes'] ?? null, 'lang' => $_POST['lang'] ?? null,
                    // an agent's appointment is owned by them so they can manage it
                    'agent_id' => $isAgent ? $uid : null,
                ], $uid);
                $flash = $t('saved');
                $tab = 'appointments';
                break;
            case 'appt_schedule':
                Appointments::schedule(
                    (int)$_POST['id'], (int)$_POST['agent_id'], (string)$_POST['starts_at'],
                    ['location' => $_POST['location'] ?? '', 'title' => $_POST['title'] ?? ''], $uid
                );
                $flash = $t('appt_scheduled');
                $tab = 'appointments';
                break;
            case 'appt_status':
                Appointments::setStatus((int)$_POST['id'], (string)$_POST['status'], $uid);
                $tab = 'appointments';
                break;

            // ---------- tasks ----------
            case 'task_create':
                Tasks::create([
                    'title' => $_POST['title'] ?? 'Task', 'description' => $_POST['description'] ?? '',
                    'assigned_to' => ($_POST['assigned_to'] ?? '') !== '' ? (int)$_POST['assigned_to'] : null,
                    'due_at' => $_POST['due_at'] ?? null, 'priority' => $_POST['priority'] ?? 'normal',
                    'kpi_weight' => $_POST['kpi_weight'] ?? 1,
                ], $uid);
                $flash = $t('saved');
                $tab = 'tasks';
                break;
            case 'task_complete':
                Tasks::complete((int)$_POST['id'], ($_POST['kpi_score'] ?? '') !== '' ? (int)$_POST['kpi_score'] : null, $uid);
                $flash = $t('saved');
                $tab = 'tasks';
                break;
            case 'task_status':
                Tasks::setStatus((int)$_POST['id'], (string)$_POST['status']);
                $tab = 'tasks';
                break;

            // ---------- tickets ----------
            case 'ticket_reply':
                $senderName = (string)($_SESSION['glue_user']['full_name'] ?? $_SESSION['glue_user']['username'] ?? 'Staff');
                $att = Tickets::storeUpload($_FILES['attachment'] ?? null, $attErr);
                $ok = $attErr === null && Tickets::reply((int)$_POST['id'], $isAgent ? 'agent' : 'admin', $uid, $senderName,
                    (string)($_POST['body'] ?? ''), $att);
                $flash = $ok ? $t('saved')
                    : ($attErr === 'too_big' ? 'File too large (max 10 MB).'
                        : ($attErr === 'bad_type' ? 'File type not allowed.'
                            : ($attErr === 'save_failed' ? 'Could not save the file.' : $t('test_fail'))));
                $flashType = $ok ? 'ok' : 'err';
                // Redirect (PRG) so a browser refresh can't re-send the reply.
                $tab = ($_POST['back'] ?? '') === 'messages' ? 'messages' : 'tickets';
                $_SESSION['dash_flash'] = [$flash, $flashType];
                header('Location: ?tab=' . $tab . '&tk=' . (int)$_POST['id']);
                exit;
            case 'ticket_open_staff':
                $contactId = (int)($_POST['contact_id'] ?? 0);
                // Agents may only message their own customers.
                $allowed = array_column(Tickets::customersForStaff($scopeId), 'id');
                $att = Tickets::storeUpload($_FILES['attachment'] ?? null, $attErr);
                $tab = ($_POST['back'] ?? '') === 'messages' ? 'messages' : 'tickets';
                if ($attErr !== null) {
                    $_SESSION['dash_flash'] = [$attErr === 'too_big' ? 'File too large (max 10 MB).'
                        : ($attErr === 'bad_type' ? 'File type not allowed.' : 'Could not save the file.'), 'err'];
                    header('Location: ?tab=' . $tab);
                    exit;
                }
                if ($contactId && in_array($contactId, array_map('intval', $allowed), true)
                    && (trim((string)($_POST['body'] ?? '')) !== '' || $att !== null)) {
                    $senderName = (string)($_SESSION['glue_user']['full_name'] ?? $_SESSION['glue_user']['username'] ?? 'Staff');
                    $newId = Tickets::openFromStaff($contactId, $isAgent ? 'agent' : 'admin', $uid, $senderName,
                        (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''), $att);
                    $_SESSION['dash_flash'] = [$t('saved'), 'ok'];
                    header('Location: ?tab=' . $tab . '&tk=' . $newId);
                    exit;
                }
                $_SESSION['dash_flash'] = [$t('test_fail'), 'err'];
                header('Location: ?tab=' . $tab);
                exit;
            case 'ticket_status':
                Tickets::setStatus((int)$_POST['id'], (string)$_POST['status']);
                $tab = ($_POST['back'] ?? '') === 'messages' ? 'messages' : 'tickets';
                $_SESSION['dash_flash'] = [$t('saved'), 'ok'];
                header('Location: ?tab=' . $tab . '&tk=' . (int)$_POST['id']);
                exit;

            // ---------- reminders / scheduler / campaigns ----------
            case 'cancel_reminder':
                $pdo->prepare("UPDATE reminders SET status='cancelled' WHERE id=? AND status='pending'")
                    ->execute([(int)$_POST['id']]);
                $flash = $t('rem_cancelled');
                $tab = 'reminders';
                break;
            case 'run_scheduler':
                $r = (new Scheduler())->runDue();
                (new Sender())->runBatch();
                $flash = $t('ov_ran') . ' ' . json_encode($r);
                break;
            case 'create_campaign':
                $recips = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$_POST['recipients'])));
                (new Sender())->create(
                    trim((string)$_POST['name']) ?: 'Campaign', (string)$_POST['channel'],
                    (string)$_POST['body'], $_POST['subject'] ?? null, array_values($recips), $lang
                );
                $flash = $t('camp_created');
                $tab = 'campaigns';
                break;

            // ---------- connection tests ----------
            case 'test_bitrix':
                $me = (new Client())->call('profile');
                $flash = $t('test_ok') . ': ' . ($me['NAME'] ?? '') . ' ' . ($me['LAST_NAME'] ?? '') . ' (' . ($me['ID'] ?? '?') . ')';
                $tab = 'settings';
                break;
            case 'test_sibill':
                // Lists what the token can see. A token is issued per organisation,
                // so when there is exactly one company we save its id rather than
                // making someone copy a uuid by hand.
                $cos = (new SibillClient())->companies();
                $names = implode(', ', array_map(fn($c) => (string)($c['name'] ?? '?'), $cos));
                if (count($cos) === 1 && trim((string)Config::get('sibill.company_id', '')) === '') {
                    Settings::set('sibill.company_id', (string)$cos[0]['id']);
                    $names .= ' — ' . $t('sib_company_saved');
                }
                $flash = $t('test_ok') . ': ' . ($names ?: $t('sib_no_companies'));
                $tab = 'settings';
                break;
            case 'test_mailbox':
                // Read-only: connects with the SAVED settings (save first, then
                // test) and counts the inbox. Nothing is imported or marked.
                if (!function_exists('imap_open')) {
                    $flash = $t('lm_test_fail') . ': php-imap extension missing';
                } else {
                    $mb = Config::section('leads_mailbox');
                    $conn = sprintf('{%s:%d/pop3/ssl}INBOX',
                        (string)($mb['host'] ?? ''), (int)($mb['port'] ?? 995));
                    $im = @imap_open($conn, (string)($mb['user'] ?? ''), (string)($mb['pass'] ?? ''));
                    if ($im === false) {
                        $flash = $t('lm_test_fail') . ': ' . (imap_last_error() ?: '?');
                    } else {
                        $flash = $t('test_ok') . ': ' . sprintf($t('lm_test_ok'), imap_num_msg($im));
                        imap_close($im);
                    }
                    imap_errors(); // swallow c-client notices so they don't leak to the page
                }
                $tab = 'settings';
                break;
            // ---------- payments (SmallPay) ----------
            // Every one of these ends on the payments tab, and none of them moves
            // money by itself: they ask SmallPay to file a position, retry a rate,
            // or say again what it knows. The one that costs something — cancel —
            // is confirmed in the view.
            case 'pay_contract_create': {
                $contactId = (int)($_POST['contact_id'] ?? 0);
                $ct = null;
                if ($contactId > 0) {
                    $q = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
                    $q->execute([$contactId]);
                    $ct = $q->fetch() ?: null;
                }
                if ($ct === null) {
                    throw new RuntimeException($t('pay_e_no_customer'));
                }
                // A deal only counts if it belongs to this customer — picking the
                // wrong one from the list would file the contract against someone
                // else's sale and quietly mis-report the revenue.
                $dealId = (int)($_POST['deal_id'] ?? 0);
                $deal = null;
                if ($dealId > 0) {
                    $dq = $pdo->prepare('SELECT contact_id, assigned_to FROM deals WHERE id = ?');
                    $dq->execute([$dealId]);
                    $deal = $dq->fetch() ?: null;
                    if (!$deal || (int)$deal['contact_id'] !== $contactId) {
                        throw new RuntimeException($t('pay_e_deal_mismatch'));
                    }
                }
                $c = PayContracts::open([
                    'kind'               => (string)($_POST['kind'] ?? 'subscription'),
                    'contact_id'         => $contactId,
                    'deal_id'            => $dealId,
                    'assigned_to'        => (int)($deal['assigned_to'] ?? $ct['assigned_to'] ?? 0),
                    'customer_name'      => (string)$ct['name'],
                    'customer_phone'     => (string)($ct['phone'] ?? ''),
                    'customer_email'     => (string)($ct['email'] ?? ''),
                    'lang'               => (string)($ct['lang'] ?? 'it'),
                    'description'        => (string)($_POST['description'] ?? ''),
                    'amount_cents'       => money_cents($_POST['amount'] ?? ''),
                    'first_amount_cents' => money_cents($_POST['first_amount'] ?? ''),
                    'total_cycles'       => (int)($_POST['total_cycles'] ?? 0),
                ], $uid);
                $flash = $t('pay_created');
                if (!empty($_POST['send_link'])) {
                    $flash .= PayContracts::sendLink((int)$c['id'], 'both', $uid) > 0
                        ? ' · ' . $t('pay_link_sent')
                        : ' · ' . $t('pay_link_not_sent');
                }
                $tab = 'payments';
                break;
            }
            case 'pay_send_link':
                $flash = PayContracts::sendLink((int)$_POST['id'], 'both', $uid) > 0
                    ? $t('pay_link_sent') : $t('pay_link_not_sent');
                $tab = 'payments';
                break;
            case 'pay_sync':
                PayContracts::sync((int)$_POST['id']);
                $flash = $t('pay_refreshed');
                $tab = 'payments';
                break;
            case 'pay_sync_all': {
                // The manual button ignores the cadence the cron respects —
                // someone pressing it wants an answer now, not "not due yet".
                Settings::set('smallpay.last_sync_at', null);
                $r = PayContracts::syncIfDue() ?? ['checked' => 0, 'changed' => 0, 'errors' => 0];
                $flash = $t('pay_refreshed') . ': ' . (int)$r['checked']
                    . ($r['errors'] ? ' · ' . (int)$r['errors'] . ' ' . $t('pay_errors') : '');
                $flashType = $r['errors'] ? 'err' : 'ok';
                $tab = 'payments';
                break;
            }
            case 'pay_relaunch': {
                $r = PayContracts::relaunch((int)$_POST['id'], [], $uid);
                $flash = $t('pay_retried') . ': ' . count((array)($r['installmentsProcessed'] ?? []));
                $tab = 'payments';
                break;
            }
            case 'pay_cash': {
                $charges = array_values(array_filter((array)($_POST['charges'] ?? []), 'strlen'));
                PayContracts::payInCash((int)$_POST['id'], $charges, $uid);
                $flash = $t('pay_cashed') . ': ' . count($charges);
                $tab = 'payments';
                break;
            }
            case 'pay_regenerate':
                PayContracts::regenerateFirstPayment((int)$_POST['id'], $uid);
                $flash = $t('pay_regenerated');
                $tab = 'payments';
                break;
            case 'pay_cancel':
                PayContracts::cancel((int)$_POST['id'], $uid);
                $flash = $t('pay_cancelled');
                $tab = 'payments';
                break;
            case 'test_smallpay':
                // checkSellConfigs validates merchant + service + gateway without
                // creating a position, so this is safe to press against the live
                // account. It is the only SmallPay call that is.
                (new \Glue\Pay\SmallPay())->checkSellConfig();
                $flash = $t('test_ok') . ' · ' . $t('pay_test_ok');
                $tab = 'settings';
                break;

            case 'sibill_sync':
                $s = SibillInvoices::sync();
                $flash = $t('sib_synced') . ': ' . $s['invoices'] . ' — '
                    . $t('sib_paid') . ' ' . $s['paid'] . ', ' . $t('sib_partial') . ' ' . $s['partial']
                    . ', ' . $t('sib_unpaid') . ' ' . $s['unpaid'];
                $tab = 'invoices';
                break;
            case 'sibill_customer_save':
                // The phone/email Sibill cannot give us, plus the per-customer
                // chase controls. chase_enabled is a checkbox: absent means off.
                SibillCustomers::saveDetails((int)$_POST['id'], [
                    'phone'         => (string)($_POST['phone'] ?? ''),
                    'email'         => (string)($_POST['email'] ?? ''),
                    'lang'          => (string)($_POST['lang'] ?? 'it'),
                    'notes'         => (string)($_POST['notes'] ?? ''),
                    'snooze_until'  => (string)($_POST['snooze_until'] ?? ''),
                    'chase_enabled' => isset($_POST['chase_enabled']),
                ]);
                $flash = $t('saved');
                $tab = 'invoices';
                break;
            case 'sibill_remind':
                // No redirect: the form posts to the current URL, so $_GET still
                // holds the open customer and the flash stays visible — which for
                // "did that message actually go?" is the whole point. An invoice_id
                // narrows the reminder to that single invoice.
                $invId = (int)($_POST['invoice_id'] ?? 0);
                $rid = SibillCustomers::remind((int)$_POST['id'], true, $invId > 0 ? [$invId] : null);
                $flash = $rid > 0 ? $t('inv_reminded') : $t('inv_remind_failed');
                $flashType = $rid > 0 ? 'ok' : 'err';
                $tab = 'invoices';
                break;
            case 'sibill_invoice_chase':
                // Toggle whether one invoice is chased automatically.
                SibillCustomers::setInvoiceChase((int)$_POST['invoice_id'], !empty($_POST['excluded']));
                $flash = $t('saved');
                $tab = 'invoices';
                break;
            case 'sibill_import':
                // Attach phone/email to customers by VAT. The result (incl. the
                // unmatched VATs) is handed to the view so it can be shown in full
                // rather than squeezed into a one-line flash.
                $sibillImport = SibillCustomers::importContacts((string)($_POST['data'] ?? ''));
                $flash = str_replace(
                    ['{m}', '{u}'],
                    [(string)$sibillImport['matched'], (string)count($sibillImport['unmatched'])],
                    $t('inv_import_done')
                );
                $tab = 'invoices';
                break;
            case 'test_whatsapp':
                $res = (new Notifier())->whatsappResult((string)$_POST['to'], (string)Config::get('app.company_name', 'CRM') . ' — test ✅');
                $flash = $res['ok'] ? $t('test_ok') : $t('test_fail') . ': ' . test_reason($res);
                $flashType = $res['ok'] ? 'ok' : 'err';
                $tab = 'settings';
                break;
            case 'test_email':
                $res = (new Notifier())->emailResult((string)$_POST['to'], 'CRM test', '<p>CRM — test ✅</p>');
                $flash = $res['ok'] ? $t('test_ok') : $t('test_fail') . ': ' . test_reason($res);
                $flashType = $res['ok'] ? 'ok' : 'err';
                $tab = 'settings';
                break;

            // ---------- users / agents ----------
            case 'create_user':
                try {
                    $newId = Auth::create((string)$_POST['username'], (string)$_POST['password'], (string)($_POST['role'] ?? 'agent'));
                } catch (\RuntimeException $e) {
                    if ($e->getMessage() !== 'username_taken') { throw $e; }
                    // Two agents can share a first name — say so in plain language
                    // instead of leaking the SQL constraint at them.
                    $flash = str_replace('{u}', trim((string)$_POST['username']), $t('u_username_taken'));
                    $flashType = 'err';
                    $tab = 'agents';
                    break;
                }
                Auth::updateProfile($newId, [
                    'full_name' => $_POST['full_name'] ?? '', 'email' => $_POST['email'] ?? '',
                    'phone' => $_POST['phone'] ?? '', 'title' => $_POST['title'] ?? '',
                ]);
                // Send the new user their login details by email + WhatsApp, so the
                // admin doesn't have to relay the username/password by hand.
                $creds = send_user_credentials(
                    (string)($_POST['email'] ?? ''), (string)($_POST['phone'] ?? ''),
                    trim((string)($_POST['full_name'] ?? '')) ?: (string)$_POST['username'],
                    (string)$_POST['username'], (string)$_POST['password']
                );
                $flash = $t('u_added') . ' · ' . ($creds ? $t('u_creds_sent') : $t('u_creds_none'));
                $tab = 'agents';
                break;
            case 'update_profile':
                Auth::updateProfile((int)$_POST['id'], [
                    'full_name' => $_POST['full_name'] ?? '', 'email' => $_POST['email'] ?? '',
                    'phone' => $_POST['phone'] ?? '', 'title' => $_POST['title'] ?? '', 'role' => $_POST['role'] ?? 'agent',
                ]);
                $flash = $t('saved');
                $tab = 'agents';
                break;
            case 'set_password':
                Auth::setPassword((int)$_POST['id'], (string)$_POST['password']);
                $flash = $t('pw_changed');
                $tab = 'agents';
                break;
            case 'toggle_user':
                Auth::setActive((int)$_POST['id'], ($_POST['active'] ?? '') === '1');
                $tab = 'agents';
                break;
            case 'delete_user':
                $tab = 'agents';
                if ((int)$_POST['id'] === (int)($_SESSION['glue_user']['id'] ?? 0)) {
                    $flash = $t('u_delete_self');
                    $flashType = 'err';
                    break;
                }
                try {
                    Auth::delete((int)$_POST['id']);
                    $flash = $t('u_deleted');
                } catch (Throwable $e) {
                    $flash = $t('u_delete_last_admin');
                    $flashType = 'err';
                }
                break;
            case 'change_my_password':
                if ($uid) {
                    Auth::setPassword($uid, (string)$_POST['password']);
                    $flash = $t('pw_changed');
                } else {
                    $flash = $t('pw_change_na');
                    $flashType = 'err';
                }
                $tab = 'agents';
                break;

            // ---------- partners (referrers) ----------
            case 'partner_save':
                $pdata = [
                    'name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? '',
                    'phone' => $_POST['phone'] ?? '', 'ref_code' => $_POST['ref_code'] ?? '',
                    'commission_pct' => $_POST['commission_pct'] ?? 10,
                    'active' => isset($_POST['active']) ? 1 : 0,
                    'password' => $_POST['password'] ?? '',
                ];
                // An email or a phone belongs to one referrer, so reusing one is
                // refused. A shared NAME is not: two partners can genuinely be called
                // the same thing. It is only flagged after the save — which is the
                // only signal there is for the pair that started this, entered once
                // with an email alone and once with a phone alone.
                $pEditId = (int)($_POST['id'] ?? 0);
                $pDupId = \Glue\Partner\Partners::duplicateId($pdata, $pEditId ?: null);
                if ($pDupId !== null) {
                    $pDup = \Glue\Partner\Partners::find($pDupId);
                    $flash = sprintf($t('pt_dup_flash'), $pDupId, (string)($pDup['name'] ?? ''));
                    $flashType = 'err';
                    $tab = 'partners';
                    break;
                }
                $pSameName = \Glue\Partner\Partners::sameNameId($pdata, $pEditId ?: null);
                if ($pEditId > 0) {
                    \Glue\Partner\Partners::update($pEditId, $pdata);
                    $flash = $t('saved');
                } else {
                    \Glue\Partner\Partners::create($pdata);
                    $flash = $t('pt_added');
                }
                if ($pSameName !== null) {
                    $flash .= ' ' . sprintf($t('pt_same_name_warn'), $pSameName);
                    $flashType = 'warn';
                }
                $tab = 'partners';
                break;
            case 'partner_delete': // admin only — remove a roster mistake (duplicate, typo)
                $pDel = \Glue\Partner\Partners::delete((int)($_POST['id'] ?? 0), $uid);
                if ($pDel['ok']) {
                    $flash = $t('pt_deleted');
                } elseif (($pDel['error'] ?? '') === 'in_use') {
                    // Referred leads / accrued commission make this a record, not a
                    // mistake — deactivating is what removes it from circulation.
                    $flash = sprintf($t('pt_del_in_use'), (int)($pDel['referrals'] ?? 0), (int)($pDel['accruals'] ?? 0));
                    $flashType = 'err';
                } else {
                    $flash = $t('not_allowed');
                    $flashType = 'err';
                }
                $tab = 'partners';
                break;
            case 'accrual_status':
                \Glue\Partner\Partners::setAccrualStatus((int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''));
                $flash = $t('saved');
                $tab = 'partners';
                break;
        }
    } catch (Throwable $e) {
        if ($ajax) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit; }
        $flash = $t('test_fail') . ': ' . $e->getMessage();
        $flashType = 'err';
    }
}

// ---- small data helpers available to views ----
$count = fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
$cfg = fn(string $k, $d = '') => Config::get($k, $d);
$agents = Auth::agents();
$money = fn($n, $cur = 'EUR') => $cfg('crm.currency', $cur) . ' ' . number_format((float)$n, 0);

$views = ['overview', 'leads', 'deals', 'contacts', 'appointments', 'tasks', 'tickets', 'documents',
          'invoices', 'payments', 'campaigns', 'messages', 'outbound', 'reminders', 'templates', 'events', 'agents',
          'partners', 'devices', 'network_areas', 'settings', 'instructions'];
$view = in_array($tab, $views, true) ? $tab : 'overview';
// Agents can't reach admin views, even by typing the URL.
if ($isAgent && !in_array($view, $agentViews, true)) {
    $view = 'overview';
    $tab  = 'overview';
}
// Technical-area users can only reach their two views. Default them to Devices.
if ($isTech) {
    if (!in_array($view, $techViews, true)) {
        $view = 'devices';
        $tab  = 'devices';
    }
    // network_areas edits credentials — keep it admin-only even for tech.
    if ($view === 'network_areas') {
        $view = 'devices';
        $tab  = 'devices';
    }
}

render_head($t, $h, $lang, $tab, $flash, $flashType, $isAgent, $isTech);

require dirname(__DIR__) . '/views/' . $view . '.php';

render_foot();


// ============================ shared chrome ============================

function render_login(callable $t, callable $h, string $lang, ?string $err): void { ?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($t('login_title')) ?></title><?php css(); ?></head>
<body class="center">
  <form class="login" method="post">
    <div class="logo">C</div>
    <h1><?= $h($t('login_title')) ?></h1>
    <p class="muted"><?= $h($t('login_sub')) ?></p>
    <?php if ($err): ?><p class="err"><?= $h($err) ?></p><?php endif; ?>
    <input type="text" name="username" placeholder="<?= $h($t('login_user_ph')) ?>" autofocus>
    <input type="password" name="password" placeholder="<?= $h($t('login_ph')) ?>">
    <button type="submit"><?= $h($t('login_btn')) ?></button>
  </form>
</body></html>
<?php }

function render_head(callable $t, callable $h, string $lang, string $tab, ?string $flash, string $flashType, bool $isAgent = false, bool $isTech = false): void {
    $brand = (string)\Glue\Config::get('app.company_name', '') ?: $t('app_title');
    $nav = [
        'overview' => 'nav_overview', 'leads' => 'nav_leads', 'deals' => 'nav_deals',
        'contacts' => 'nav_contacts', 'appointments' => 'nav_appointments', 'tasks' => 'nav_tasks',
        'tickets' => 'nav_tickets', 'documents' => 'nav_documents', 'invoices' => 'nav_invoices',
        'payments' => 'nav_payments',
        'campaigns' => 'nav_campaigns', 'messages' => 'nav_messages', 'outbound' => 'nav_outbound',
        'reminders' => 'nav_reminders', 'templates' => 'nav_templates',
        'devices' => 'nav_devices', 'network_areas' => 'nav_network_areas',
        'events' => 'nav_events', 'agents' => 'nav_agents', 'partners' => 'nav_partners', 'instructions' => 'nav_instr', 'settings' => 'nav_settings',
    ];
    if ($isAgent) { // agents only see their own work
        $nav = array_intersect_key($nav, array_flip(['overview', 'leads', 'deals', 'appointments', 'tasks', 'messages', 'documents', 'instructions']));
    } elseif ($isTech) { // technical-area users only see device monitoring
        $nav = array_intersect_key($nav, array_flip(['devices']));
    } ?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($brand) ?> — CRM</title><?php css(); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script></head>
<body>
<div class="shell">
  <div class="nav-backdrop" id="navBackdrop" onclick="closeNav()"></div>
  <aside class="sidebar" id="sidebar">
    <div class="brand"><div class="logo"><?= $h(strtoupper(substr($brand, 0, 1)) ?: 'C') ?></div>
      <div><strong><?= $h($brand) ?></strong><span class="muted small"><?= $h($t('app_subtitle')) ?></span></div></div>
    <nav>
      <?php foreach ($nav as $key => $label): ?>
        <a class="<?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= $h($key) ?>"><?= svg($key) ?><span><?= $h($t($label)) ?></span></a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <main>
    <header class="topbar">
      <button class="navtoggle" id="navToggle" onclick="openNav()" aria-label="Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="crumb"><?= $h($t('nav_' . ($tab === 'instructions' ? 'instr' : $tab))) ?></div>
      <div class="actions">
        <a class="btn ghost tiny pubform" href="request.php" target="_blank"><?= svg('link') ?> <?= $h($t('public_form')) ?></a>
        <span class="langsw">
          <a class="<?= $lang === 'en' ? 'on' : '' ?>" href="?tab=<?= $h($tab) ?>&lang=en">EN</a>
          <a class="<?= $lang === 'it' ? 'on' : '' ?>" href="?tab=<?= $h($tab) ?>&lang=it">IT</a>
        </span>
        <span class="muted small who"><?= $h($_SESSION['glue_user']['username'] ?? '') ?></span>
        <a class="btn ghost" href="?action=logout"><?= $h($t('logout')) ?></a>
      </div>
    </header>
    <div class="content">
    <?php if ($flash): ?><div class="flash <?= $flashType === 'err' ? 'flash-err' : ($flashType === 'warn' ? 'flash-warn' : '') ?>"><?= $h($flash) ?></div><?php endif; ?>
<?php }

function render_foot(): void { ?>
</div></main></div>
<script>
// Mobile sidebar drawer: open/close + close on backdrop tap, Escape, or nav click.
function openNav(){document.getElementById('sidebar').classList.add('open');
  document.getElementById('navBackdrop').classList.add('show');}
function closeNav(){document.getElementById('sidebar').classList.remove('open');
  document.getElementById('navBackdrop').classList.remove('show');}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeNav();});
// Double-submit guard: once a form is actually submitting, disable its submit
// button so a second click can't fire the same POST twice (e.g. creating a
// duplicate lead). The 'submit' event fires only after native validation and any
// onsubmit confirm() have passed, so a cancelled/invalid submit leaves the button
// usable. setTimeout keeps the button in the POST body for this submission.
document.addEventListener('submit',function(e){
  var b=e.target.querySelector('button[type=submit],button:not([type]),input[type=submit]');
  if(b){setTimeout(function(){b.disabled=true;b.style.opacity='0.6';},0);}
});
// Reveal a masked secret (API keys, passwords) while it is being checked or
// typed. Deliberately momentary: it flips back on blur, so a revealed key can't
// be left legible on a screen someone walks away from or keeps sharing.
function peek(id, btn){
  var i=document.getElementById(id); if(!i) return;
  var show = i.type === 'password';
  i.type = show ? 'text' : 'password';
  btn.classList.toggle('on', show);
  if(show){ i.addEventListener('blur', function once(){
    i.type='password'; btn.classList.remove('on'); i.removeEventListener('blur', once);
  }); }
}
// Same for the read-only webhook URLs, which carry the intake secret inside the
// address itself — masking the input alone would still leave them readable.
document.querySelectorAll('input[data-secret-url]').forEach(function(i){
  var real = i.value;
  i.value = i.dataset.secretUrl;
  i.addEventListener('focus', function(){ i.value = real; i.select(); });
  i.addEventListener('blur',  function(){ i.value = i.dataset.secretUrl; });
});
// Make every table horizontally scrollable on small screens without editing each
// view: wrap any unwrapped <table> in a .table-wrap container.
document.querySelectorAll('main table').forEach(function(tb){
  if(!tb.parentElement.classList.contains('table-wrap')){
    var w=document.createElement('div');w.className='table-wrap';
    tb.parentNode.insertBefore(w,tb);w.appendChild(tb);
  }
});
</script>
</body></html>
<?php }

/**
 * Send a newly created user their login details (email + WhatsApp) using the
 * editable 'agent_welcome' template. Best-effort and recorded in the Outbound
 * tab. Returns true if at least one channel was sent. Staff get the office
 * default language.
 */
function send_user_credentials(string $email, string $phone, string $name, string $username, string $password): bool {
    $email = trim($email);
    $phone = trim($phone);
    if ($email === '' && $phone === '') {
        return false;
    }
    $lang = \Glue\Reminder\Templates::lang((string)\Glue\Config::get('app.default_lang', 'it'));
    $vars = [
        'name'     => $name,
        'username' => $username,
        'password' => $password,
        'company'  => (string)(\Glue\Config::get('mail.from_name', '') ?: \Glue\Config::get('app.company_name', 'CRM')),
        'link'     => \Glue\Config::appBaseUrl() . '/dashboard.php',
    ];
    $notifier = new Notifier();
    $ok = false;
    if ($phone !== '') {
        $ok = $notifier->whatsapp($phone, \Glue\Reminder\Templates::whatsapp('agent_welcome', $vars, $lang)) || $ok;
    }
    if ($email !== '') {
        $mail = \Glue\Reminder\Templates::email('agent_welcome', $vars, $lang);
        $ok = $notifier->email($email, $mail['subject'], $mail['html']) || $ok;
    }
    return $ok;
}

/**
 * Turn a Notifier provider response into a short human-readable failure reason
 * for the Settings test buttons. Prefers the explicit 'error', then a non-200
 * HTTP code + response body (TextMeBot), then any 'skipped' marker.
 */
function test_reason(array $res): string {
    $parts = [];
    if (!empty($res['skipped'])) {
        $map = [
            'no_phone'           => 'No phone number entered',
            'no_email'           => 'No email address entered',
            'textmebot_disabled' => 'WhatsApp (TextMeBot) API key is not configured',
        ];
        $parts[] = $map[$res['skipped']] ?? (string)$res['skipped'];
    }
    if (!empty($res['error']) && (empty($res['skipped']) || $res['error'] !== $res['skipped'])) {
        $parts[] = (string)$res['error'];
    }
    if (isset($res['http']) && (int)$res['http'] !== 200 && (int)$res['http'] !== 0) {
        $parts[] = 'HTTP ' . (int)$res['http'];
    }
    if (!empty($res['body']) && empty($res['error'])) {
        $parts[] = 'Response: ' . trim((string)$res['body']);
    }
    $reason = trim(implode(' — ', array_filter($parts)));
    return $reason !== '' ? $reason : 'unknown error';
}


// ============================ ui bits (shared by views) ============================

function stat_card(callable $h, string $icon, string $label, string $val, bool $ok): void {
    echo '<div class="tile"><div class="tile-top">' . svg($icon) . '<span class="small">' . $h($label) . '</span></div>'
        . '<span class="badge ' . ($ok ? 'ok' : 'no') . '"><span class="dot"></span>' . $h($val) . '</span></div>';
}
function num_card(callable $h, string $icon, string $label, $n, string $sub = ''): void {
    echo '<div class="tile"><div class="tile-top">' . svg($icon) . '<span class="small">' . $h($label) . '</span></div>'
        . '<span class="big">' . $h((string)$n) . '</span>'
        . ($sub !== '' ? '<div class="sub">' . $h($sub) . '</div>' : '') . '</div>';
}
function avatar(callable $h, ?string $name): string {
    $n = trim((string)$name);
    $ini = $n !== '' ? strtoupper(mb_substr($n, 0, 1)) : '?';
    return '<span class="avatar">' . $h($ini) . '</span>';
}
/**
 * Render a phone number as a click-to-call link with a phone icon. The visible
 * text keeps the human formatting; the tel: href is reduced to digits plus a
 * single leading + so the dialer receives a clean number. Returns '' for an empty
 * phone (callers drop it straight into a template). onclick stops propagation so
 * tapping the number inside a <summary> drawer header dials instead of toggling.
 */
function phone_link(callable $h, ?string $phone): string {
    $raw = trim((string)$phone);
    if ($raw === '') { return ''; }
    $digits = preg_replace('/[^\d+]/', '', $raw);          // keep digits and +
    $plus   = ($digits !== '' && $digits[0] === '+') ? '+' : '';
    $tel    = $plus . str_replace('+', '', $digits);       // at most one leading +
    if ($tel === '' || $tel === '+') { return $h($raw); }  // no dialable digits
    return '<a class="tel" href="tel:' . $h($tel) . '" onclick="event.stopPropagation()">'
        . svg('phone') . '<span>' . $h($raw) . '</span></a>';
}
function feed_icon(string $source): string {
    return ['form_intake' => 'leads', 'crm' => 'leads', 'bitrix_event' => 'link', 'sync' => 'link',
        'scheduler' => 'clock', 'campaign' => 'mega', 'appointment' => 'appointments',
        'request_form' => 'leads'][$source] ?? 'events';
}
function short_time(?string $dt): string {
    $ts = $dt ? strtotime($dt) : false;
    return $ts ? date('M j, H:i', $ts) : (string)$dt;
}
/** Compact localized "how long ago" — e.g. "35 min ago" / "3 h fa" / "2 days ago". */
function time_ago(?string $dt, callable $t): string {
    $ts = $dt ? strtotime($dt) : false;
    if (!$ts) { return ''; }
    $s = max(0, time() - $ts);
    if ($s < 3600)  { return sprintf($t('ago_min'), max(1, intdiv($s, 60))); }
    if ($s < 86400) { return sprintf($t('ago_h'), intdiv($s, 3600)); }
    return sprintf($t('ago_d'), intdiv($s, 86400));
}
/**
 * Read a money field typed by a human into integer cents.
 *
 * Italian keyboards produce "1.234,56"; the same person on another day types
 * "1234.56" or just "49". All three mean the same amount and all three have to
 * survive, because this number is what the customer's card is charged: read
 * "1.234,56" as a plain float and you bill 1,23 instead of 1234,56.
 *
 * Rule: the LAST separator is the decimal point when it leaves 1-2 digits
 * behind it; anything else is a thousands mark and is dropped.
 */
function money_cents($raw): int {
    $s = trim((string)$raw);
    if ($s === '') { return 0; }
    $s = preg_replace('/[^\d.,\-]/', '', $s) ?? '';
    $neg = str_starts_with($s, '-');
    $s = str_replace('-', '', $s);

    $lastSep = max(strrpos($s, ',') ?: -1, strrpos($s, '.') ?: -1);
    if ($lastSep >= 0 && strlen($s) - $lastSep - 1 <= 2 && strlen($s) - $lastSep - 1 >= 1) {
        $int = preg_replace('/\D/', '', substr($s, 0, $lastSep)) ?? '';
        $dec = str_pad(preg_replace('/\D/', '', substr($s, $lastSep + 1)) ?? '', 2, '0');
    } else {
        $int = preg_replace('/\D/', '', $s) ?? '';
        $dec = '00';
    }
    $cents = (int)($int === '' ? '0' : $int) * 100 + (int)substr($dec, 0, 2);
    return $neg ? -$cents : $cents;
}
function fld(callable $h, string $name, string $label, $value, string $hint = ''): void {
    echo '<label class="fld"><span>' . $h($label) . '</span>'
        . '<input name="' . $h($name) . '" value="' . $h($value) . '">'
        . ($hint ? '<small class="muted">' . $h($hint) . '</small>' : '') . '</label>';
}
/**
 * Same, for a field holding a secret — API keys, mailbox and SMTP passwords,
 * the intake secret, SmallPay's uniqueId.
 *
 * These used to render as ordinary text inputs, so every credential the CRM
 * holds was legible to anyone standing behind the screen while Settings was
 * open — or watching a screen share, which is how the page is usually walked
 * through with the client.
 *
 * The value is still submitted normally, so saving keeps working exactly as it
 * did: an empty field still means empty, and there is no way to accidentally
 * blank a stored key by not retyping it. What changes is that it is dotted out
 * until someone deliberately reveals it. That is the threat this addresses —
 * a shoulder, not an attacker, who by this point is already an authenticated
 * admin and could read the settings table anyway.
 */
function secret_fld(callable $h, string $name, string $label, $value, string $hint = ''): void {
    static $n = 0;
    $id = 'sec' . (++$n);
    echo '<label class="fld"><span>' . $h($label) . '</span>'
        . '<span class="secretwrap">'
        . '<input type="password" id="' . $id . '" name="' . $h($name) . '" value="' . $h($value)
        . '" autocomplete="off" spellcheck="false">'
        . '<button type="button" class="peek" onclick="peek(\'' . $id . '\',this)" tabindex="-1"'
        . ' aria-label="show">' . svg('eye') . '</button>'
        . '</span>'
        . ($hint ? '<small class="muted">' . $h($hint) . '</small>' : '') . '</label>';
}
/** <select> of agents for assignment. */
function agent_select(callable $h, array $agents, string $name, $selected = null, string $placeholder = '—'): void {
    echo '<select name="' . $h($name) . '"><option value="">' . $h($placeholder) . '</option>';
    foreach ($agents as $a) {
        $label = trim((string)($a['full_name'] ?? '')) ?: $a['username'];
        $sel = ((string)$selected === (string)$a['id']) ? ' selected' : '';
        echo '<option value="' . $h($a['id']) . '"' . $sel . '>' . $h($label) . '</option>';
    }
    echo '</select>';
}
/**
 * Partner picker for the lead forms — "this one was brought in by…". Admin only:
 * who gets the credit (and the commission that follows it) is not a seller's call.
 * Lists disabled partners too, like the filter bar does, so an existing
 * attribution survives an edit made after the partner's account was switched off.
 */
function partner_select(callable $h, callable $t, array $partners, $selected = null, string $name = 'partner_id'): void {
    echo '<select name="' . $h($name) . '"><option value="">' . $h($t('partner_none')) . '</option>';
    foreach ($partners as $p) {
        $sel = ((string)$selected !== '' && (string)$selected === (string)$p['id']) ? ' selected' : '';
        $suffix = (int)$p['active'] === 1 ? '' : ' (' . $t('u_disabled') . ')';
        echo '<option value="' . $h($p['id']) . '"' . $sel . '>' . $h($p['name'] . $suffix) . '</option>';
    }
    echo '</select>';
}
/**
 * Admin-only "view one agent's pipeline" filter. A tiny GET form that reloads the
 * board scoped to ?agent=<id>; the empty option clears it back to everyone. Keeps
 * the current tab via the hidden field so the querystring stays on this view.
 */
/**
 * The board's "whose work am I looking at?" bar. One form, so the selects
 * compose: narrowing to a partner keeps the seller you had chosen, and the other
 * way round — two separate forms would each have dropped the other's field on
 * submit. $partners empty (Deals) renders the seller select alone, as before.
 */
function pipeline_filter(callable $h, callable $t, array $agents, string $tab, ?int $selected = null,
                         array $partners = [], ?int $selPartner = null): void {
    $sty = 'padding:7px 10px;border-radius:8px;border:1px solid var(--line);background:var(--surface2);color:var(--txt);font-size:13px';
    echo '<form method="get" class="agent-filter" style="margin:0 0 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
    echo '<input type="hidden" name="tab" value="' . $h($tab) . '">';
    echo '<span class="muted small">' . $h($t('filter_by_agent')) . '</span>';
    echo '<select name="agent" onchange="this.form.submit()" style="' . $sty . '">';
    echo '<option value="">' . $h($t('all_agents')) . '</option>';
    foreach ($agents as $a) {
        $label = trim((string)($a['full_name'] ?? '')) ?: $a['username'];
        $sel = ($selected !== null && (int)$selected === (int)$a['id']) ? ' selected' : '';
        echo '<option value="' . $h($a['id']) . '"' . $sel . '>' . $h($label) . '</option>';
    }
    echo '</select>';
    if ($partners) {
        echo '<span class="muted small">' . $h($t('filter_by_partner')) . '</span>';
        echo '<select name="partner" onchange="this.form.submit()" style="' . $sty . '">';
        echo '<option value="">' . $h($t('all_partners')) . '</option>';
        foreach ($partners as $p) {
            $sel = ($selPartner !== null && (int)$selPartner === (int)$p['id']) ? ' selected' : '';
            // An inactive partner stays listed: the leads they already brought in
            // are still ours to look through.
            $suffix = (int)$p['active'] === 1 ? '' : ' (' . $t('u_disabled') . ')';
            echo '<option value="' . $h($p['id']) . '"' . $sel . '>' . $h($p['name'] . $suffix) . '</option>';
        }
        echo '</select>';
    }
    if ($selected !== null || $selPartner !== null) {
        echo '<a class="btn ghost tiny" href="?tab=' . $h($tab) . '">' . $h($t('clear')) . '</a>';
    }
    echo '</form>';
}
/** Status pill with a localised label but the raw status as the CSS class. */
function pill(callable $h, string $status, ?callable $t = null): string {
    $label = $status;
    if ($t !== null) {
        $tr = $t('stt_' . $status);
        $label = $tr !== 'stt_' . $status ? $tr : $status; // fall back to raw if untranslated
    }
    return '<span class="pill pill-' . $h($status) . '">' . $h($label) . '</span>';
}
/**
 * Localised stage label. The default pipeline stages are seeded in English
 * (migration 008); translate those by code. If an operator renamed a stage away
 * from its seed default, respect their custom name. Custom stages with no
 * translation fall back to their stored name.
 */
function stage_label(callable $t, string $code, ?string $name = null): string {
    static $seed = [
        'NEW' => 'New', 'CONTACTED' => 'In Contact', 'QUALIFIED' => 'Qualified',
        'CONVERTED' => 'Converted', 'JUNK' => 'Junk', 'QUOTE' => 'Quote sent',
        'NEGOTIATION' => 'Negotiation', 'SIGNATURE' => 'Signature', 'WON' => 'Won', 'LOST' => 'Lost',
    ];
    if ($name !== null && $name !== '' && isset($seed[$code]) && strcasecmp($name, $seed[$code]) !== 0) {
        return $name; // operator-renamed → keep their label
    }
    $key = 'stg_' . $code;
    $tr  = $t($key);
    if ($tr !== $key) {
        return $tr;
    }
    return ($name !== null && $name !== '') ? $name : $code;
}
/**
 * Localise a machine code (event type, source, rule key, recipient, channel)
 * via a prefixed lang key, e.g. code_label($t, 'evt_', 'lead_created'). Falls
 * back to the raw code when there's no translation, so new codes still show.
 */
function code_label(callable $t, string $prefix, ?string $code): string {
    $code = (string)$code;
    if ($code === '') {
        return '';
    }
    $key = $prefix . $code;
    $tr  = $t($key);
    return $tr !== $key ? $tr : $code;
}

