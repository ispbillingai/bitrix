<?php
declare(strict_types=1);

/**
 * Standalone CRM control panel — sidebar + header layout, EN/IT, DB-backed
 * settings, leads/deals kanban, contacts, appointments, tasks/KPI, agents,
 * campaigns and the message/automation logs. Thin controller: it handles auth +
 * POST actions, then includes a per-page view from /views. House dashboard style.
 */
require __DIR__ . '/../src/Bootstrap.php';

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
$agentViews   = ['overview', 'leads', 'deals', 'appointments', 'tasks', 'messages', 'tickets', 'instructions'];
$techViews    = ['devices', 'network_areas'];
$agentActions = [
    'lead_create', 'lead_move', 'lead_convert', 'lead_note', 'lead_edit',
    'deal_move', 'deal_note', 'deal_invite',
    'appt_create', 'appt_schedule', 'appt_status',
    'task_complete', 'task_status', 'ticket_reply', 'ticket_status', 'ticket_open_staff', 'change_my_password',
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

// ---- leads export (?export=leads&m=YYYY-MM[&src=cashmatic]) — admin only ----
// Excel-compatible CSV of the leads received in a month (optionally one source),
// including each lead's full processing trail (stage moves + agent notes).
if (($_GET['export'] ?? '') === 'leads' && !$isAgent) {
    $xm  = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
    $xsrc = mb_strtolower(trim((string)($_GET['src'] ?? '')));
    $sql = "SELECT l.*, u.username AS agent_username, u.full_name AS agent_name
            FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
            WHERE l.received_at >= CONCAT(?, '-01')
              AND l.received_at <  CONCAT(?, '-01') + INTERVAL 1 MONTH"
        . ($xsrc !== '' ? ' AND l.source = ?' : '') . ' ORDER BY l.received_at';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($xsrc !== '' ? [$xm, $xm, $xsrc] : [$xm, $xm]);
    $xrows = $stmt->fetchAll();

    $fname = 'leads_' . ($xsrc !== '' ? $xsrc : 'all') . '_' . $xm . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly
    $sep = ';';                   // Italian Excel expects semicolons
    fputcsv($out, ['ID', $t('th_created'), $t('f_name'), $t('f_phone'), $t('f_email'), $t('f_vat'),
        $t('f_source'), $t('f_zone'), $t('f_fair'), $t('f_fair_city'),
        $t('th_stage'), $t('th_status'), $t('th_agent'),
        $t('f_message'), $t('exp_processing')], $sep);
    foreach ($xrows as $xr) {
        $trail = [];
        foreach (array_reverse(Activities::forEntity('lead', (int)$xr['id'], 200)) as $a) {
            $who = $a['full_name'] ?: ($a['username'] ?: $t('system'));
            $trail[] = '[' . $a['created_at'] . '] ' . $who . ': ' . $a['body'];
        }
        fputcsv($out, [
            $xr['id'], $xr['received_at'], $xr['customer_name'], $xr['customer_phone'],
            $xr['customer_email'], (string)($xr['vat_number'] ?? ''), $xr['source'],
            (string)($xr['zone'] ?? ''), (string)($xr['fair_name'] ?? ''), (string)($xr['fair_city'] ?? ''),
            stage_label($t, (string)$xr['stage_code'], Pipelines::label('lead', (string)$xr['stage_code'])),
            $xr['status'], $xr['agent_name'] ?: ($xr['agent_username'] ?: ''),
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
                     'ticket_' => ['tickets', 'assigned_agent_id']];
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
                $newLeadId = Leads::create([
                    'name' => $_POST['name'] ?? '', 'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? '',
                    'company' => $_POST['company'] ?? '', 'comments' => $_POST['comments'] ?? '',
                    'source' => $src ?: 'manual', 'zone' => $_POST['zone'] ?? '', 'lang' => $_POST['lang'] ?? null,
                    'vat_number' => $vat,
                    // set by the trade-fair form (#16); blank on the standard form
                    'fair_name' => $_POST['fair_name'] ?? '', 'fair_city' => $_POST['fair_city'] ?? '',
                ], $uid);
                // An agent's own entry is theirs: auto-assign so it shows in their scope.
                if ($isAgent && $scopeId) {
                    Leads::assign($newLeadId, $scopeId, $uid);
                }
                if ($vat !== '' && !empty($vc['fresh'])) {
                    \Glue\Crm\VatLock::attachLead($vat, $newLeadId);
                    \Glue\Crm\VatLock::notifyThanks('agent', (int)$uid, $vat, trim((string)($_POST['name'] ?? '')));
                }
                $flash = $t('saved');
                $tab = 'leads';
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
                Leads::update((int)$_POST['id'], $editData, $uid);
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
                $newId = Auth::create((string)$_POST['username'], (string)$_POST['password'], (string)($_POST['role'] ?? 'agent'));
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
                if ((int)($_POST['id'] ?? 0) > 0) {
                    \Glue\Partner\Partners::update((int)$_POST['id'], $pdata);
                    $flash = $t('saved');
                } else {
                    \Glue\Partner\Partners::create($pdata);
                    $flash = $t('pt_added');
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

$views = ['overview', 'leads', 'deals', 'contacts', 'appointments', 'tasks', 'tickets',
          'campaigns', 'messages', 'outbound', 'reminders', 'templates', 'events', 'agents', 'partners',
          'devices', 'network_areas', 'settings', 'instructions'];
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
        'tickets' => 'nav_tickets',
        'campaigns' => 'nav_campaigns', 'messages' => 'nav_messages', 'outbound' => 'nav_outbound',
        'reminders' => 'nav_reminders', 'templates' => 'nav_templates',
        'devices' => 'nav_devices', 'network_areas' => 'nav_network_areas',
        'events' => 'nav_events', 'agents' => 'nav_agents', 'partners' => 'nav_partners', 'instructions' => 'nav_instr', 'settings' => 'nav_settings',
    ];
    if ($isAgent) { // agents only see their own work
        $nav = array_intersect_key($nav, array_flip(['overview', 'leads', 'deals', 'appointments', 'tasks', 'messages', 'instructions']));
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
    <?php if ($flash): ?><div class="flash <?= $flashType === 'err' ? 'flash-err' : '' ?>"><?= $h($flash) ?></div><?php endif; ?>
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
function fld(callable $h, string $name, string $label, $value, string $hint = ''): void {
    echo '<label class="fld"><span>' . $h($label) . '</span>'
        . '<input name="' . $h($name) . '" value="' . $h($value) . '">'
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
 * Admin-only "view one agent's pipeline" filter. A tiny GET form that reloads the
 * board scoped to ?agent=<id>; the empty option clears it back to everyone. Keeps
 * the current tab via the hidden field so the querystring stays on this view.
 */
function agent_filter(callable $h, callable $t, array $agents, string $tab, ?int $selected = null): void {
    echo '<form method="get" class="agent-filter" style="margin:0 0 14px;display:flex;align-items:center;gap:8px">';
    echo '<input type="hidden" name="tab" value="' . $h($tab) . '">';
    echo '<span class="muted small">' . $h($t('filter_by_agent')) . '</span>';
    echo '<select name="agent" onchange="this.form.submit()" style="padding:7px 10px;border-radius:8px;border:1px solid var(--line);background:var(--surface2);color:var(--txt);font-size:13px">';
    echo '<option value="">' . $h($t('all_agents')) . '</option>';
    foreach ($agents as $a) {
        $label = trim((string)($a['full_name'] ?? '')) ?: $a['username'];
        $sel = ($selected !== null && (int)$selected === (int)$a['id']) ? ' selected' : '';
        echo '<option value="' . $h($a['id']) . '"' . $sel . '>' . $h($label) . '</option>';
    }
    echo '</select>';
    if ($selected !== null) {
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

function svg(string $name): string {
    $p = [
        'overview'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'leads'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'deals'       => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M3 11v3"/><line x1="7" y1="10" x2="7" y2="15"/>',
        'pipeline'    => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="14" y2="12"/><line x1="4" y1="18" x2="9" y2="18"/>',
        'contacts'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/>',
        'appointments'=> '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'tasks'       => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'agents'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
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
        'devices'     => '<rect x="4" y="3" width="16" height="12" rx="1"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="15" x2="12" y2="21"/>',
        'partners'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M12 12l2 2 4-4"/>',
        'network_areas' => '<rect x="9" y="2" width="6" height="6" rx="1"/><rect x="3" y="16" width="6" height="6" rx="1"/><rect x="15" y="16" width="6" height="6" rx="1"/><path d="M12 8v4M12 12H6v4M12 12h6v4"/>',
        'link'        => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/>',
        'send'        => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'outbound'    => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'templates'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>',
        'money'       => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'alert'       => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'check'       => '<path d="M20 6 9 17l-5-5"/>',
        'trophy'      => '<path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0V4z"/><path d="M5 4H3v2a3 3 0 0 0 3 3M19 4h2v2a3 3 0 0 1-3 3"/>',
        'phone'       => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    ];
    $body = $p[$name] ?? $p['overview'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}

function css(): void { ?>
<style>
:root{
  --bg:#0e131c;--surface:#161c28;--surface2:#1c2533;--line:#28303f;--line2:#39435a;
  --txt:#e7ecf4;--muted:#8b95a7;--accent:#5b6cff;--accent-soft:rgba(91,108,255,.14);
  --green:#3fb868;--green-bg:rgba(63,184,104,.13);--red:#e5616e;--red-bg:rgba(229,97,110,.13);
  --amber:#d9a40a;--amber-bg:rgba(217,164,10,.13);--violet:#7c5cff;--radius:12px;
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
.sidebar{width:236px;background:var(--surface);border-right:1px solid var(--line);
  padding:18px 14px;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;flex:0 0 auto;}
.brand{display:flex;gap:11px;align-items:center;margin:4px 6px 22px;}
.brand strong{display:block;font-size:15px;} .brand span{display:block;line-height:1.3;margin-top:2px;}
nav{display:flex;flex-direction:column;gap:2px;overflow-y:auto;}
nav a{display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:8px;color:var(--muted);
  font-weight:500;transition:background .12s,color .12s;}
nav a svg{width:18px;height:18px;flex:0 0 auto;}
nav a:hover{background:var(--surface2);color:var(--txt);}
nav a.active{background:var(--accent);color:#fff;}
main{flex:1;display:flex;flex-direction:column;min-width:0;}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:13px 28px;
  border-bottom:1px solid var(--line);background:var(--surface);position:sticky;top:0;z-index:5;}
.crumb{font-weight:700;font-size:17px;}
.actions{display:flex;gap:12px;align-items:center;}
.actions .btn.tiny svg{width:14px;height:14px;}
.langsw{display:inline-flex;background:var(--surface2);border:1px solid var(--line);border-radius:8px;padding:2px;}
.langsw a{padding:4px 9px;border-radius:6px;color:var(--muted);font-weight:600;font-size:12px;}
.langsw a.on{background:var(--accent);color:#fff;}
.content{padding:24px 28px;width:100%;}
h2{font-size:21px;margin-bottom:18px;letter-spacing:-.01em;} h3{font-size:15px;margin:16px 0 12px;}
.lead{font-size:15px;color:var(--muted);margin-bottom:20px;line-height:1.65;max-width:820px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px;}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:20px;margin-bottom:16px;}
.tile{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:16px 18px;transition:border-color .12s;}
.tile:hover{border-color:var(--line2);}
.tile-top{display:flex;align-items:center;gap:9px;margin-bottom:10px;color:var(--muted);}
.tile-top svg{width:17px;height:17px;}
.tile .big{display:block;margin-top:6px;} .tile .sub{font-size:12px;color:var(--muted);margin-top:4px;}
.badge{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;border-radius:7px;font-size:12.5px;font-weight:600;}
.badge .dot{width:7px;height:7px;border-radius:50%;}
.badge.ok{background:var(--green-bg);color:var(--green);} .badge.ok .dot{background:var(--green);}
.badge.no{background:var(--red-bg);color:var(--red);} .badge.no .dot{background:var(--red);}
.cols{display:grid;gap:16px;margin-bottom:16px;}
.cols.c-2-1{grid-template-columns:2fr 1fr;} .cols.c-1-1{grid-template-columns:1fr 1fr;}
@media(max-width:1100px){.cols.c-2-1,.cols.c-1-1{grid-template-columns:1fr;}}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;}
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
.row{display:flex;gap:14px;flex-wrap:wrap;} .row .fld{flex:1;min-width:150px;}
.btn{padding:10px 16px;border:none;border-radius:8px;background:var(--accent);color:#fff;font-weight:600;
  cursor:pointer;font-size:14px;transition:filter .12s;display:inline-flex;align-items:center;gap:7px;} .btn:hover{filter:brightness(1.08);}
.btn svg{width:15px;height:15px;}
.btn.ghost{background:var(--surface2);border:1px solid var(--line);color:var(--txt);}
.btn.ghost:hover{border-color:var(--line2);filter:none;background:var(--surface);}
.btn.danger{background:var(--red-bg);border:1px solid var(--red);color:var(--red);}
.btn.danger:hover{background:var(--red);color:#fff;filter:none;}
.btn.tiny{padding:6px 12px;font-size:12.5px;}
.inline{display:inline-flex;gap:8px;align-items:center;margin:0 10px 8px 0;}
.inline input,.inline select{width:auto;}
table{width:100%;border-collapse:separate;border-spacing:0;background:var(--surface);
  border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;}
th,td{text-align:left;padding:11px 14px;border-bottom:1px solid var(--line);vertical-align:middle;}
th{color:var(--muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;background:var(--surface2);}
tbody tr:hover{background:var(--surface2);} tr:last-child td{border-bottom:none;}
.pill{display:inline-block;padding:4px 10px;border-radius:7px;background:var(--surface2);font-size:12px;font-weight:600;border:1px solid var(--line);text-transform:capitalize;}
.pill-pending,.pill-requested,.pill-open{color:var(--amber);background:var(--amber-bg);border-color:transparent;}
.pill-sent,.pill-confirmed,.pill-done,.pill-won,.pill-converted{color:var(--green);background:var(--green-bg);border-color:transparent;}
.pill-failed,.pill-cancelled,.pill-lost,.pill-junk,.pill-no_show{color:var(--red);background:var(--red-bg);border-color:transparent;}
.reason-err{color:var(--red);word-break:break-word;max-width:340px;}
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
/* kanban */
.kanban{display:flex;gap:14px;overflow-x:auto;padding-bottom:8px;}
.kcol{flex:0 0 270px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);display:flex;flex-direction:column;max-height:72vh;}
.kcol-h{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;}
.kcol-h .dotc{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:8px;}
.kcol-h .cnt{font-size:11px;color:var(--muted);background:var(--surface2);border-radius:20px;padding:2px 8px;}
.kbody{padding:10px;display:flex;flex-direction:column;gap:9px;overflow-y:auto;min-height:60px;}
.kbody.drag{outline:2px dashed var(--line2);outline-offset:-6px;border-radius:8px;}
.kcard{background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:11px 12px;cursor:grab;}
.kcard:hover{border-color:var(--line2);} .kcard b{font-size:13.5px;font-weight:600;}
.kcard .meta{font-size:11.5px;color:var(--muted);margin-top:4px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.kcard .amt{color:var(--green);font-weight:600;}
.avatar{display:inline-flex;width:22px;height:22px;border-radius:50%;background:var(--accent-soft);color:var(--accent);
  align-items:center;justify-content:center;font-size:11px;font-weight:700;}
.tl{display:flex;flex-direction:column;gap:0;}
.tl-row{display:flex;gap:11px;padding:9px 0;border-bottom:1px solid var(--line);}
.tl-row:last-child{border-bottom:none;}
.tl-ic{width:26px;height:26px;border-radius:7px;background:var(--surface2);display:flex;align-items:center;justify-content:center;color:var(--muted);flex:0 0 auto;}
.tl-ic svg{width:13px;height:13px;} .tl-main{flex:1;min-width:0;} .tl-main .meta{font-size:11.5px;color:var(--muted);}
.lb{display:flex;align-items:center;gap:10px;padding:10px 4px;border-bottom:1px solid var(--line);}
.lb:last-child{border-bottom:none;} .lb .nm{flex:1;font-weight:600;} .lb .sc{font-weight:700;color:var(--accent);}
.lb .mini{font-size:11.5px;color:var(--muted);}
details.drawer{margin-bottom:8px;} details.drawer>summary{cursor:pointer;list-style:none;}
details.drawer>summary::-webkit-details-marker{display:none;}
/* Drawer header row (leads/deals/partners/agents): avatar · .dw-info · pills · agent.
   .dw-info carries the name + phone + email and absorbs the slack. */
summary.dw-sum{display:flex;align-items:center;gap:12px;padding:13px 18px;}
.dw-info{flex:1;min-width:0;}
/* Click-to-call number: accent-coloured link + phone glyph, sits inline in muted text. */
a.tel{display:inline-flex;align-items:center;gap:4px;color:var(--accent);white-space:nowrap;vertical-align:baseline;}
a.tel svg{width:13px;height:13px;flex:0 0 auto;}
a.tel:hover{text-decoration:underline;}
@media(max-width:560px){
  /* A phone number is one unbreakable token. Squeezed into what the pills leave over on
     a narrow screen it overflowed the card and agents could not read it, so let the row
     wrap and give .dw-info the full width. */
  summary.dw-sum{flex-wrap:wrap;row-gap:6px;}
  .dw-info{flex-basis:100%;}
}
/* hamburger (mobile only) + off-canvas backdrop */
.navtoggle{display:none;background:var(--surface2);border:1px solid var(--line);color:var(--txt);
  border-radius:8px;padding:7px;cursor:pointer;align-items:center;justify-content:center;margin-right:4px;}
.navtoggle svg{width:20px;height:20px;display:block;}
.nav-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:40;}
@media(max-width:900px){
  /* Sidebar becomes a slide-in drawer with full labels — no more icon-only rail. */
  .sidebar{position:fixed;top:0;left:0;height:100dvh;width:248px;z-index:50;
    transform:translateX(-100%);transition:transform .22s ease;box-shadow:0 0 40px rgba(0,0,0,.4);}
  .sidebar.open{transform:translateX(0);}
  .nav-backdrop.show{display:block;}
  .navtoggle{display:inline-flex;}
  .topbar{padding:11px 16px;gap:10px;}
  .crumb{font-size:16px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .content{padding:16px;}
  /* Trim the topbar so it fits a phone: drop the public-form button + username. */
  .actions{gap:8px;} .actions .btn.tiny span{display:none;}
  .topbar .pubform,.topbar .who{display:none;}
}
@media(max-width:560px){
  .row{flex-direction:column;gap:0;} .row .fld{min-width:0;}
  .grid{grid-template-columns:1fr;}
  .cols.c-2-1,.cols.c-1-1{grid-template-columns:1fr;}
  .langsw a{padding:4px 8px;}
  .login{width:100%;max-width:360px;padding:30px 22px;}
  .tabs{display:flex;flex-wrap:wrap;}
  th,td{padding:9px 11px;}
}
/* Wide tables scroll sideways inside their own box instead of overflowing the
   page. .table-wrap is added around every table by JS (see render_foot). */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin-bottom:16px;}
.table-wrap table{margin-bottom:0;}
@media(max-width:560px){.table-wrap table{min-width:520px;}}
</style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php }
