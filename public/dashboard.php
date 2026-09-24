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
use Glue\Crm\Calendar as CrmCalendar;
use Glue\Crm\Contacts;
use Glue\Crm\Deals;
use Glue\Crm\Interventions;
use Glue\Crm\Leads;
use Glue\Crm\Pipelines;
use Glue\Crm\Tasks;
use Glue\Crm\Tickets;
use Glue\Db;
use Glue\Event\Log;
use Glue\Inspect\Inspections;
use Glue\Install\Reports as InstallReports;
use Glue\Notify\Notifier;
use Glue\Notify\TextMeBot;
use Glue\Pay\Contracts as PayContracts;
use Glue\Reminder\Scheduler;
use Glue\Settings;
use Glue\Team\Chat as TeamChat;
use Glue\Ai\Assistant;
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
    // "Ho dimenticato la password": send a one-time link to the address already
    // on the account. The answer is the SAME sentence whatever was typed —
    // saying "no such user" would turn the login page into a staff directory —
    // so there is nothing to branch on here.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot'])) {
        \Glue\PasswordReset::request((string)($_POST['identifier'] ?? ''));
        render_login($t, $h, $lang, null, true, $t('fp_sent'));
        exit;
    }
    if (isset($_GET['forgot'])) {
        render_login($t, $h, $lang, null, true);
        exit;
    }
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
// Amministrazione: the back office. Does the whole operational job — customers,
// quotes, documents, invoices, the support queue — but does not hold the keys
// to the system. Deliberately NOT a variant of "agent": every existing
// (!$isAgent && !$isTech) check means "office or administrator", and that is
// exactly right for operational work, so this role inherits all of it and is
// then held OUT of the four system tabs below. Nothing changes for anyone until
// an account is actually moved to this role.
$isOffice = $role === 'office';
// The Administrator: the only role that may change configuration, create or
// re-role accounts, edit the message templates, and read the global audit log.
$isSysAdmin = !$isAgent && !$isTech && !$isOffice;
$scopeId = $isAgent ? (int)($_SESSION['glue_user']['id'] ?? 0) : null; // null = no scope (admin/office)

/**
 * Tabs and POST actions that belong to the Administrator alone — configuration,
 * accounts and the audit trail. Everything else in the CRM is operational and
 * the office does it too.
 */
const SYS_VIEWS = ['settings', 'agents', 'events', 'templates'];
const SYS_ACTIONS = [
    'save_settings', 'save_templates', 'stage_add', 'stage_delete',
    'create_user', 'update_profile', 'set_password', 'toggle_user', 'delete_user',
    'test_whatsapp', 'test_email', 'test_bitrix', 'test_sibill', 'test_ai',
    'test_mailbox', 'test_smallpay',
];
// Admin-only pipeline filter: ?agent=<id> narrows the Leads/Deals boards (and the
// overview) to one seller. Agents are always hard-scoped to themselves and ignore it.
$filterAgentId = (!$isAgent && !empty($_GET['agent'])) ? (int)$_GET['agent'] : null;
if ($filterAgentId !== null) {
    $scopeId = $filterAgentId;
}
// Admin-only too: ?partner=<id> narrows the Leads board to the leads one partner
// brought in — entered in their own area or through their referral link.
$filterPartnerId = (!$isAgent && !empty($_GET['partner'])) ? (int)$_GET['partner'] : null;
$agentViews   = ['overview', 'calendar', 'leads', 'deals', 'quotes', 'articles', 'pricelists', 'appointments', 'tasks', 'messages', 'tickets', 'team', 'documents', 'instructions', 'my_commissions'];
$techViews    = ['devices', 'network_areas', 'installations', 'inspections', 'support', 'calendar', 'tickets', 'team'];
// The installation-report flow: open a draft, fill it in, attach the photos,
// send it for signature. Deleting a report stays admin-only.
$installActions = ['install_create', 'install_save', 'install_photos', 'install_photo_del', 'install_send'];
// Surveys: the same shape as an installation report, plus the opinion the
// technical group writes once the customer has signed it.
$inspectActions = ['insp_create', 'insp_save', 'insp_photos', 'insp_photo_del', 'insp_send',
                   'insp_claim', 'insp_opinion', 'insp_opinion_send'];
// Technicians' POST whitelist: the installation-report flow, taking charge of
// assistance requests, and replying on the tickets they claimed.
// The team chat and the assistant: every role has them; membership is checked per chat.
$teamActions  = ['team_new', 'team_send', 'team_add', 'team_leave', 'team_rename', 'ai_ask', 'ai_confirm', 'ai_cancel'];
$techActions  = array_merge($installActions, $inspectActions,
    ['assist_claim', 'ticket_reply', 'ticket_status',
     // booking, moving and closing the visit they took charge of, and the
     // address their own phone subscribes to
     'interv_schedule', 'interv_status', 'cal_feed_reset',
     // the calendar: book their own round, take a job out of the pool, hand
     // one to a colleague, move it, add a customer they found on site, and
     // say the next day is planned
     'cal_save', 'cal_assign', 'cal_move', 'cal_newcust', 'plan_confirm'], $teamActions);
$agentActions = [
    'lead_create', 'lead_move', 'lead_convert', 'lead_note', 'lead_edit', 'lead_quote',
    'lead_appointment',
    'deal_move', 'deal_note', 'deal_invite',
    'appt_create', 'appt_schedule', 'appt_status',
    'task_complete', 'task_status', 'ticket_reply', 'ticket_status', 'ticket_open_staff', 'change_my_password',
    'doc_create', 'doc_send', 'doc_void', 'cal_feed_reset',
    'cal_save', 'cal_assign', 'cal_move', 'cal_newcust', 'plan_confirm',
    // Asking the office for a quote and sending back the answer is the seller's
    // job; uploading the quote and cancelling a request are the office's.
    'quote_scratch', 'quote_send', 'quote_revise',
];
$agentActions = array_merge($agentActions, $teamActions, ['cm_invoice', // an agent invoices their own statements
    'lead_docs_upload', 'fin_open', 'fin_save', 'fin_upload', 'fin_file_del', 'fin_submit']); // …and fills their customers' folders
// An agent who also installs — the tick box on their account — gets the
// Installations tab and the report flow on top, with a technician's scope:
// only the reports they opened. Read from the users row on every request, not
// from the login session, so ticking or unticking it takes effect on the
// agent's next click rather than their next login.
$agentInstalls = false;
if ($isAgent && $uid) {
    $ciq = $pdo->prepare('SELECT can_install FROM users WHERE id = ?');
    $ciq->execute([$uid]);
    $agentInstalls = (bool)$ciq->fetchColumn();
}
if ($agentInstalls) {
    $agentViews[] = 'installations';
    $agentActions = array_merge($agentActions, $installActions);
}

// ---- lead documents (?ldl=<file id>) and a lender's blank privacy form (?lpr=<lender id>) ----
// The customers' paperwork lives outside the web root. The office reads all of
// it; a seller only the files on their own leads; a technician none.
if (isset($_GET['ldl'])) {
    $ldFile = \Glue\Finance\Docs::file((int)$_GET['ldl']);
    $ldOk = $ldFile && !$isTech;
    if ($ldOk && $isAgent) {
        $ldOwn = $pdo->prepare('SELECT assigned_to FROM leads WHERE id = ?');
        $ldOwn->execute([(int)$ldFile['lead_id']]);
        $ldOk = (int)$ldOwn->fetchColumn() === (int)$uid && $uid;
    }
    if ($ldOk) {
        \Glue\Finance\Docs::stream($ldFile);
    }
    http_response_code(404);
    exit('Not found');
}
if (isset($_GET['lpr']) && !$isAgent && !$isTech) {
    $lpr = \Glue\Finance\Docs::lender((int)$_GET['lpr']);
    if ($lpr && $lpr['privacy_path']) {
        \Glue\Finance\Docs::stream(['path' => $lpr['privacy_path'], 'name' => $lpr['privacy_name'] ?: 'privacy']);
    }
    http_response_code(404);
    exit('Not found');
}

// ---- commission statement files (?cmf=<statement_id>&w=calc|invoice) ----
// The calculation and the invoice are kept outside the web root; this is the
// only way out. The office sees every statement's files, an agent only their
// own, a technician none.
if (isset($_GET['cmf'])) {
    $cmSt = \Glue\Commission\Statements::find((int)$_GET['cmf']);
    if ($cmSt && !$isTech && (!$isAgent
            || ($uid && $cmSt['payee_type'] === 'agent' && (int)$cmSt['payee_id'] === (int)$uid))) {
        \Glue\Commission\Statements::stream($cmSt, (string)($_GET['w'] ?? 'calc'));
    }
    http_response_code(404);
    exit('Not found');
}

// ---- a product's photo or document (?amf=<media id>[&s=t for the small copy]) ----
// Product sheets are sales material, not customer data: every signed-in user
// may read them. The session is released first — a catalogue page asks for
// dozens of photos at once, and PHP would otherwise serve them one at a time
// behind the session lock.
if (isset($_GET['amf'])) {
    session_write_close();
    $am = \Glue\Crm\ArticleMedia::find((int)$_GET['amf']);
    if ($am) {
        \Glue\Crm\ArticleMedia::stream($am, ($_GET['s'] ?? '') === 't');
    }
    http_response_code(404);
    exit('Not found');
}

// ---- a price list as PDF (?plpdf=<list id>[&q=&category=][&dl=1]) ----
// The catalogue as it is filtered on screen. Inline opens the browser's viewer
// (the "Stampa" button); dl=1 downloads. Agents only reach published lists.
if (isset($_GET['plpdf'])) {
    $plList = \Glue\Crm\PriceLists::find((int)$_GET['plpdf']);
    if (!$plList || $isTech || ($isAgent && (int)$plList['visible'] !== 1)) {
        http_response_code(404);
        exit('Not found');
    }
    session_write_close();
    @set_time_limit(300);
    \Glue\Crm\ArticleMedia::raiseMemory('512M');   // photos are embedded; a long list is tens of MB
    $plF = ['q' => trim((string)($_GET['q'] ?? '')), 'category' => trim((string)($_GET['category'] ?? ''))];
    $plRows = \Glue\Crm\PriceLists::allItems((int)$plList['id'], $plF);
    $plPdf = \Glue\Crm\PriceListPdf::build(
        $plList, $plRows,
        \Glue\Crm\ArticleMedia::byIds(array_column($plRows, 'cover_id')),
        $lang,
        implode(' · ', array_filter([$plF['category'], $plF['q'] !== '' ? '"' . $plF['q'] . '"' : '']))
    );
    $plSlug = trim((string)preg_replace('/[^a-z0-9]+/', '-',
        strtolower((string)(@iconv('UTF-8', 'ASCII//TRANSLIT', (string)$plList['name']) ?: 'listino'))), '-') ?: 'listino';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline')
        . '; filename="listino-' . $plSlug . '-' . date('Y-m-d') . '.pdf"');
    header('Content-Length: ' . strlen($plPdf));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $plPdf;
    exit;
}

// ---- an instalment commission's calculation (?cpf=<plan id>) ----
// Same rule as a statement's files: the office any, an agent only their own.
if (isset($_GET['cpf'])) {
    $cpPlan = \Glue\Commission\Plans::find((int)$_GET['cpf']);
    if ($cpPlan && !$isTech && (!$isAgent
            || ($uid && $cpPlan['payee_type'] === 'agent' && (int)$cpPlan['payee_id'] === (int)$uid))) {
        \Glue\Commission\Statements::stream($cpPlan, 'calc');
    }
    http_response_code(404);
    exit('Not found');
}

// ---- Customer type-ahead for the calendar's booking form (?find=book_contact&q=) ----
// Its OWN name, not ?find=contacts: that one belongs to the Messaggi picker and
// answers a different shape (name/label). Sharing the name shadowed it — the
// first handler to match exits — and the message picker rendered "undefined"
// for every hit while quietly losing its agent scoping too.
//
// Matches a VAT number and a gestionale code as well as a name, because whoever
// is booking has whichever of those the caller read out. Agents are scoped to
// their own customers, exactly like the message picker; technicians and the
// office see everyone, because a visit can be to any customer.
if (($_GET['find'] ?? '') === 'book_contact') {
    header('Content-Type: application/json');
    echo json_encode(
        Contacts::searchPicker((string)($_GET['q'] ?? ''), 12, $isAgent ? $scopeId : null),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ---- Sibill invoices for an instalment commission (?find=sibill_invoices&q=...) ----
// Office only: the plan form picks the customer's invoice, and its instalments
// become the commission's.
if (($_GET['find'] ?? '') === 'sibill_invoices') {
    header('Content-Type: application/json');
    if ($isAgent || $isTech) {
        echo json_encode([]);
        exit;
    }
    echo json_encode(array_map(static fn(array $i): array => [
        'id'       => (int)$i['id'],
        'number'   => (string)$i['number'],
        'date'     => (string)$i['creation_date'],
        'customer' => (string)$i['counterpart_name'],
        'gross'    => (float)$i['gross_amount'],
        'flows'    => array_map(static fn(array $f): array => [
            'amount' => (float)$f['amount'], 'due' => (string)($f['due_date'] ?? ''),
            'paid'   => $f['payment_status'] === 'PAID',
        ], $i['flows']),
    ], \Glue\Commission\Plans::searchSibill((string)($_GET['q'] ?? ''))), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- team chat attachment (?tdl=<message_id>) ----
// Files in the team chat live outside the web root; this is the only way out,
// and only for a member of the chat the message is in.
if (isset($_GET['tdl'])) {
    $tm = TeamChat::message((int)$_GET['tdl']);
    if ($tm && !empty($tm['attachment_path']) && $uid && TeamChat::isMember((int)$tm['chat_id'], (int)$uid)) {
        TeamChat::streamAttachment($tm);
    }
    http_response_code(404);
    exit('Not found');
}

// ---- team chat live poll (?poll=team&c=<chat>&after=<msgId>) ----
// New messages as ready-to-append bubbles; reading them marks them read.
if (($_GET['poll'] ?? '') === 'team') {
    header('Content-Type: application/json');
    $tcId = (int)($_GET['c'] ?? 0);
    if (!$uid || !TeamChat::isMember($tcId, (int)$uid)) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
    $tcOut = [];
    $tcLast = (int)($_GET['after'] ?? 0);
    foreach (TeamChat::thread($tcId, $tcLast) as $m) {
        $tcOut[] = ['id' => (int)$m['id'], 'html' => team_bubble($m, $t, $h, (int)$uid, !$isAgent && !$isTech)];
        $tcLast = max($tcLast, (int)$m['id']);
    }
    if ($tcOut) {
        TeamChat::markRead($tcId, (int)$uid, $tcLast);
    }
    echo json_encode(['ok' => true, 'messages' => $tcOut]);
    exit;
}

// ---- ticket attachment download (?dl=<message_id>) ----
if (isset($_GET['dl'])) {
    $msg = Tickets::messageFile((int)$_GET['dl']);
    // Admin can fetch anything; agents and techs only files on tickets
    // assigned to them (a tech gets assigned by claiming the request).
    if ($msg && ((!$isAgent && !$isTech) || (int)$msg['assigned_agent_id'] === (int)$uid)) {
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
    // Admin: everything. Agent or tech: only the documents they raised — which
    // for a technician is their own installation reports.
    //
    // Plus the one document a seller did NOT raise but must be able to read: the
    // quote the office uploaded in answer to their own request. Without this the
    // seller is asked to send a customer a PDF they cannot open themselves.
    $sdocMine = $sdoc && (int)$sdoc['created_by'] === (int)$uid;
    if ($sdoc && !$sdocMine && ($isAgent || $isTech)) {
        $qown = $pdo->prepare('SELECT 1 FROM quote_requests WHERE document_id = ? AND requested_by = ?');
        $qown->execute([(int)$sdoc['id'], (int)$uid]);
        $sdocMine = (bool)$qown->fetchColumn();
    }
    if ($sdoc && ((!$isAgent && !$isTech) || $sdocMine)) {
        $wantSigned = ($_GET['k'] ?? 'orig') === 'signed';
        $path = $wantSigned ? SignDocs::signedPath($sdoc) : SignDocs::originalPath($sdoc);
        if ($path !== null) {
            SignDocs::stream($path, $wantSigned ? 'signed-' . $sdoc['uid'] . '.pdf' : (string)$sdoc['orig_name']);
        }
    }
    http_response_code(404);
    exit('Not found');
}

// ---- customer lookup for the "new message" picker (?find=contacts&q=...) ----
// The picker cannot be a plain <select>: the registry is ten thousand contacts
// deep, and a dropdown capped at 500 stopped inside the letter A. Same scope as
// starting a thread — admins anyone, agents only their own customers.
if (($_GET['find'] ?? '') === 'contacts') {
    header('Content-Type: application/json');
    $fq = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($fq) < 2) {
        echo json_encode([]);
        exit;
    }
    echo json_encode(array_map(
        fn(array $c): array => [
            'id'    => (int)$c['id'],
            'name'  => (string)($c['name'] ?: '#' . $c['id']),
            'label' => trim(implode(' · ', array_filter([
                (string)($c['company'] ?? ''), (string)($c['email'] ?? ''), (string)($c['phone'] ?? ''),
            ], 'strlen'))),
        ],
        Tickets::searchCustomersForStaff($isAgent ? $scopeId : null, $fq, 25)
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- article lookup for the quote builder (?find=articles&q=...) ----
// Office only: sellers never build quotes, and the builder is where prices are
// set. Priced from the LISTINO, the discounts going on top of it; where the
// gestionale has no listino but does have price list 4, that is the price.
// Where it has neither — 2,239 articles, CASH.0536 among them — the price is 0
// and 'source' says 'none', so the builder can SAY there is no price rather
// than let a 0,00 line slide onto a document the customer signs.
if (($_GET['find'] ?? '') === 'articles') {
    header('Content-Type: application/json');
    $fq = trim((string)($_GET['q'] ?? ''));
    if ($isAgent || $isTech || mb_strlen($fq) < 2) {
        echo json_encode([]);
        exit;
    }
    // With a price list chosen (?list=N) its own prices win for the products in
    // it, and the rest still answer with the catalogue price — the office may
    // need a product the list does not carry, and refusing to find it would be
    // worse than saying where the price came from.
    $fRows = \Glue\Crm\Articles::search(['q' => $fq], 1, 20)['rows'];
    $fList = (int)($_GET['list'] ?? 0) > 0 ? \Glue\Crm\PriceLists::find((int)$_GET['list']) : null;
    $fIn   = $fList ? \Glue\Crm\PriceLists::membership((int)$fList['id'], array_column($fRows, 'id')) : [];
    echo json_encode(array_map(
        function (array $a) use ($fList, $fIn): array {
            $listino = (float)$a['list_price'];
            $sale4   = (float)($a['sale_price4'] ?? 0);
            $price   = $listino > 0 ? $listino : $sale4;
            $source  = $listino > 0 ? 'listino' : ($sale4 > 0 ? 'vendita' : 'none');
            $inList  = $fList !== null && array_key_exists((int)$a['id'], $fIn);
            if ($inList) {
                $p = \Glue\Crm\PriceLists::netPrice($a + ['pl_price' => $fIn[(int)$a['id']]], $fList);
                if ($p > 0) {
                    $price  = $p;
                    $source = 'list';
                }
            }
            return [
                'id'          => (int)$a['id'],
                'code'        => (string)$a['code'],
                'description' => (string)($a['description'] ?? ''),
                'listino'     => $listino,
                'vendita'     => $sale4,
                'price'       => $price,
                'source'      => $price > 0 ? $source : 'none',
                'in_list'     => $inList,
                'vat'         => $a['vat_rate'] !== null ? (float)$a['vat_rate'] : 22.0,
                'available'   => (float)$a['stock_available'],
            ];
        },
        $fRows
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- live chat poll (?poll=ticket&tk=<id>&after=<msgId>) ----
// Returns messages newer than <after> as ready-to-append HTML, so an open
// thread stays current without a page refresh. Same scope as viewing: admins
// any ticket, agents/techs only the ones assigned to them.
if (($_GET['poll'] ?? '') === 'ticket') {
    header('Content-Type: application/json');
    $tkId  = (int)($_GET['tk'] ?? 0);
    $after = (int)($_GET['after'] ?? 0);
    $tk    = $tkId > 0 ? Tickets::find($tkId) : null;
    if (!$tk || (($isAgent || $isTech) && (int)$tk['assigned_agent_id'] !== (int)$uid)) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
    $out = [];
    foreach (Tickets::thread($tkId) as $m) {
        if ((int)$m['id'] > $after) {
            $out[] = ['id' => (int)$m['id'], 'html' => ticket_bubble($m, $t, $h)];
        }
    }
    echo json_encode(['ok' => true, 'messages' => $out, 'status' => (string)$tk['status']]);
    exit;
}

// ---- installation-report photo (?ipf=<photo_id>) ----
// Photos live outside the web root (or behind the uploads deny); this is the
// only way out. Admin sees all, a technician/agent only their own reports'.
if (isset($_GET['ipf'])) {
    $ph = InstallReports::photoFile((int)$_GET['ipf']);
    if ($ph && ((!$isAgent && !$isTech) || (int)$ph['created_by'] === (int)$uid)) {
        InstallReports::streamPhoto($ph);
    }
    http_response_code(404);
    exit('Not found');
}

// ---- survey photo (?ispf=<photo_id>) ----
// Same door as the installation report's. A technician sees their own survey's
// photos, the one they took in charge, and any survey still waiting for an
// opinion — the queue is the whole group's until somebody claims it.
if (isset($_GET['ispf'])) {
    $isp = Inspections::photoFile((int)$_GET['ispf']);
    if ($isp && ((!$isAgent && !$isTech)
        || (int)$isp['created_by'] === (int)$uid
        || (int)($isp['claimed_by'] ?? 0) === (int)$uid
        || (string)$isp['status'] === 'signed')) {
        Inspections::streamPhoto($isp);
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
    // Every phone field posts number + country selector (phone_field() in
    // _ui.php); joined here, once, so each handler below finds the
    // international number in $_POST['phone'] as before.
    \Glue\Crm\Phone::applyPosted($_POST);
    // Agents may only run their own whitelisted actions; block admin actions.
    if ($isAgent && !in_array($do, $agentActions, true)) {
        if ($ajax) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
        $flash = $t('not_allowed');
        $flashType = 'err';
        $do = ''; // fall through the switch without matching any case
    }
    // Technicians the same: only the install-report actions are theirs. (Before
    // this whitelist the tech role, being neither agent nor admin, slipped past
    // the agent gate and could POST anything — closed now that techs get logins.)
    if ($isTech && !in_array($do, $techActions, true)) {
        if ($ajax) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
        $flash = $t('not_allowed');
        $flashType = 'err';
        $do = '';
    }
    // Amministrazione is a blacklist, not a whitelist: the office does the whole
    // operational job, so listing everything it MAY do would be a list of nearly
    // every action in this file and a new feature would silently be denied to
    // them. What it may not do is the short, stable list — configuration,
    // accounts, templates, the connection tests.
    if ($isOffice && in_array($do, SYS_ACTIONS, true)) {
        if ($ajax) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
        $flash = $t('not_allowed');
        $flashType = 'err';
        $do = '';
    }
    // ...and only on records assigned to them (block IDOR via a forged id). An
    // unassigned or non-existent record reads as owner 0 and is denied too.
    if (($isAgent || $isTech) && $do !== '') {
        $rid = (int)($_POST['id'] ?? 0);
        $ownerCol = ['lead_' => ['leads', 'assigned_to'], 'deal_' => ['deals', 'assigned_to'],
                     'appt_' => ['appointments', 'agent_id'], 'task_' => ['tasks', 'assigned_to'],
                     // interv_status carries an appointment id; interv_schedule
                     // carries the REQUEST id in req_id and no 'id', so it falls
                     // past this guard and checks the claimer itself.
                     'interv_' => ['appointments', 'agent_id'],
                     // cal_* is NOT listed here on purpose: taking a job out of
                     // the pool means acting on a row owned by nobody, which this
                     // guard reads as owner 0 and denies. Each cal_ handler checks
                     // "mine or unowned" itself.
                     'ticket_' => ['tickets', 'assigned_agent_id'],
                     'doc_' => ['sign_documents', 'created_by'],
                     'quote_' => ['quote_requests', 'requested_by'],
                     'install_' => ['install_reports', 'created_by']];
        // insp_ is NOT listed: a survey waiting for an opinion belongs to
        // nobody until a technician claims it, which this guard would read as
        // owner 0 and deny. Inspections::claim is atomic and each opinion
        // action checks the claim itself.
        $needsOwner = null;
        foreach ($ownerCol as $prefix => $tc) {
            if (str_starts_with($do, $prefix)) { $needsOwner = $tc; break; }
        }
        // Only check existing-record actions (those carrying an id). Create actions
        // like appt_create have no id and set ownership themselves.
        if ($needsOwner !== null && $rid > 0) {
            [$table, $col] = $needsOwner;
            $owner = (int)$pdo->query("SELECT $col FROM $table WHERE id = $rid")->fetchColumn();
            if ($owner !== (int)($isAgent ? $scopeId : $uid)) {
                if ($ajax) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
                $flash = $t('not_allowed');
                $flashType = 'err';
                $do = '';
            }
        }
    }
    // A seller may only touch the documents of their own leads; a technician none.
    // (The 'lead_' prefix guard above already covers lead_docs_upload.)
    $finMay = function (int $leadId) use ($pdo, $isAgent, $isTech, $uid): bool {
        if ($isTech) { return false; }
        if (!$isAgent) { return true; }
        $q = $pdo->prepare('SELECT assigned_to FROM leads WHERE id = ?');
        $q->execute([$leadId]);
        return $uid && (int)$q->fetchColumn() === (int)$uid;
    };
    $finName = trim((string)($_SESSION['glue_user']['full_name'] ?? '')) ?: (string)($_SESSION['glue_user']['username'] ?? 'Staff');
    try {
        switch ($do) {
            // ---------- settings ----------
            case 'save_settings':
                $allowed = [
                    'app.company_name', 'app.default_lang', 'app.timezone', 'app.base_url', 'app.intake_secret',
                    'app.default_country_code', 'app.iban', 'app.bank_holder', 'app.bank_name',
                    'crm.currency', 'crm.deal_quote_stage',
                    'reminders.lead_inactivity_hours', 'reminders.deal_inactivity_hours',
                    'reminders.lead_nudge_repeat_hours', 'reminders.lead_customer_after_hours',
                    'reminders.sign_after_sent_days',
                    'reminders.sign_overdue_every_days', 'reminders.sign_overdue_max_days',
                    'reminders.sign_due_default_days',
                    'reminders.appointment_offsets_min', 'reminders.intervention_offsets_min',
                    'reminders.intervention_day_before_at',
                    'planning.prompt_at', 'planning.escalate_from',
                    'planning.escalate_every_min', 'planning.escalate_max',
                    'maintenance.followup_months',
                    'maintenance.max_per_day',
                    'notify.quiet_minutes',
                    'reminders.sign_before_due_days', 'reminders.offer_read_days',
                    'textmebot.api_key', 'mail.from_name', 'mail.from_email',
                    'mail.smtp.host', 'mail.smtp.port', 'mail.smtp.user', 'mail.smtp.pass', 'mail.smtp.secure',
                    'logistics.email', 'logistics.phone',
                    'bitrix.sync_enabled', 'bitrix.base_url', 'bitrix.outbound_secret',
                    'sibill.enabled', 'sibill.api_key', 'sibill.company_id',
                    'ai.api_key', 'ai.model', 'ai.usd_eur', 'finance.doc_types',
                    'sibill.sync_minutes', 'sibill.sync_months',
                    'sibill.chase_enabled', 'sibill.chase_from_date',
                    'sibill.chase_every_days', 'sibill.chase_min_days_late',
                    'sibill.chase_min_amount', 'sibill.chase_max_per_run', 'sibill.chase_channel',
                    'sibill.chase_hour_from', 'sibill.chase_hour_to',
                    'leads_mailbox.host', 'leads_mailbox.port', 'leads_mailbox.user',
                    'leads_mailbox.pass', 'leads_mailbox.poll_minutes',
                    'smallpay.enabled', 'smallpay.env', 'smallpay.id_merchant', 'smallpay.unique_id',
                    'smallpay.service_id', 'smallpay.service_id_sdd', 'smallpay.default_gateway',
                    'smallpay.domain', 'smallpay.reference_prefix',
                    'smallpay.sync_minutes', 'smallpay.modify_installments',
                    'smallpay.notify_customer_on_failure',
                    'support.amount', 'support.cycles', 'support.description', 'support.features',
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
                $pairs['sibill.chase_pay_link'] = $post('sibill.chase_pay_link') !== null ? 'true' : 'false';
                $pairs['ai.read_only'] = $post('ai.read_only') !== null ? 'true' : 'false';
                $pairs['leads_mailbox.enabled'] = $post('leads_mailbox.enabled') !== null ? 'true' : 'false';
                $pairs['planning.enabled'] = $post('planning.enabled') !== null ? 'true' : 'false';
                $pairs['maintenance.enabled']         = $post('maintenance.enabled') !== null ? 'true' : 'false';
                $pairs['maintenance.message_customer'] = $post('maintenance.message_customer') !== null ? 'true' : 'false';
                $pairs['maintenance.create_task']     = $post('maintenance.create_task') !== null ? 'true' : 'false';
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
                foreach (['reminders.appointment_offsets_min', 'reminders.intervention_offsets_min',
                          'reminders.sign_before_due_days', 'reminders.offer_read_days'] as $lk) {
                    if (array_key_exists($lk, $pairs)) {
                        $nums = array_values(array_filter(array_map(
                            'intval', preg_split('/[\s,]+/', (string)$pairs[$lk], -1, PREG_SPLIT_NO_EMPTY) ?: []
                        ), static fn($n) => $n > 0));
                        $pairs[$lk] = $nums ? json_encode($nums) : '';
                    }
                }
                $prevNudgeH = Scheduler::leadNudgeHours();
                Settings::setMany($pairs);
                // Config was overlaid once at boot; re-apply so the form below this
                // request reflects the values we just saved (not the pre-save snapshot).
                Config::applyOverlay(Settings::nested());
                $flash = $t('saved') . ' · ' . count($pairs) . ' ' . $t('settings_saved_n');
                // A new lead-nudge cadence must reach the chains already running,
                // or the leads being nudged today keep their old pace for good.
                if (Scheduler::leadNudgeHours() !== $prevNudgeH) {
                    $retimed = (new Scheduler())->retimeLeadNudges(Scheduler::leadNudgeHours());
                    if ($retimed > 0) {
                        $flash .= ' · ' . sprintf($t('lead_nudges_retimed'), $retimed);
                    }
                }
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
                // Who this lead is really being filed FOR. Read before the VAT is
                // claimed, because it decides who the claim belongs to: an owner
                // filing on behalf of a partner is recording that the PARTNER
                // brought the customer, so the partner holds the 90 days and the
                // partner is the one told about it. Claiming it for the office
                // account instead put the reservation on a login that has no
                // phone and no email, so the confirmation went nowhere at all.
                // Admins only — sellers post this form too, and who earns the
                // commission is not theirs to set.
                $onBehalfOf = (!$isAgent && !empty($_POST['partner_id'])) ? (int)$_POST['partner_id'] : 0;
                $vatKind = $onBehalfOf > 0 ? 'partner' : 'agent';
                $vatOwner = $onBehalfOf > 0 ? $onBehalfOf : (int)$uid;

                // The same idea for the phone number, and with no expiry: a number
                // that already belongs to a live lead or to a registry customer
                // blocks the entry outright, instead of being quietly merged or
                // attached — which is how a test lead typed with a number sitting
                // on a real customer's card got filed under that company, and its
                // quote mailed to them. Before the VAT claim, so a refused entry
                // never takes one. A seller is not told whose it is unless it is
                // their own lead — the same discretion as the VAT message.
                $phoneOwner = Leads::phoneOwner((string)($_POST['phone'] ?? ''));
                // A number on a CUSTOMER'S card is no longer refused: "possibility
                // of receiving a lead request even from an existing customer ...
                // and being able to assign an agent". Leads::create puts the lead
                // on that card. A number on another live LEAD still is.
                if ($phoneOwner !== null && $phoneOwner['kind'] === 'lead') {
                    $po = $phoneOwner;
                    if ($po['kind'] === 'lead') {
                        $flash = ($isAgent && (int)$po['agent_id'] !== (int)$uid)
                            ? sprintf($t('phone_taken_lead_other'), $po['phone'])
                            : sprintf($t('phone_taken_lead'), $po['phone'], $po['id'],
                                $po['name'] . (!$isAgent && $po['agent'] ? ' · ' . $po['agent'] : ''));
                    } else {
                        $flash = $isAgent
                            ? sprintf($t('phone_taken_customer_agent'), $po['phone'])
                            : sprintf($t('phone_taken_customer'), $po['phone'], $po['name'], $po['code'] ?: '—');
                    }
                    $flashType = 'err';
                    $tab = 'leads';
                    break;
                }

                // 90-day VAT exclusivity: the first enterer of a VAT number owns
                // it; someone else re-entering it is blocked and notified.
                $vat = \Glue\Crm\VatLock::normalize((string)($_POST['vat_number'] ?? ''));
                $vatWarn = '';
                if ($vat !== '') {
                    $vc = \Glue\Crm\VatLock::claim($vat, $vatKind, $vatOwner);
                    if (!$vc['ok']) {
                        \Glue\Crm\VatLock::notifyTaken($vatKind, $vatOwner, $vat, (string)$vc['available_at']);
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
                    // Where they are. Both entry forms send these; they land on
                    // the CONTACT, filling a blank and never overwriting.
                    'address' => $_POST['address'] ?? '', 'city' => $_POST['city'] ?? '',
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
                // ($onBehalfOf is read further up — the VAT claim needs it first.)
                if ($onBehalfOf > 0 && $dupLeadId === null) {
                    \Glue\Partner\Partners::setReferrer($newLeadId, $onBehalfOf, $uid);
                }
                if ($vat !== '' && !empty($vc['fresh'])) {
                    \Glue\Crm\VatLock::attachLead($vat, $newLeadId);
                    // $leadName, not $_POST['name'] — the form posts the customer in
                    // two boxes, so the old read handed the template an empty name.
                    if (!\Glue\Crm\VatLock::notifyThanks($vatKind, $vatOwner, $vat, $leadName)) {
                        // It could not be delivered — almost always because the
                        // account holding the claim has neither phone nor email.
                        // Silence here is what got reported as "the 90-day message
                        // never arrived", so it is said on screen now.
                        $vatWarn = ' ' . sprintf($t('vat_thanks_undeliverable'),
                            \Glue\Crm\VatLock::ownerLabel($vatKind, $vatOwner));
                    }
                }
                if ($dupLeadId !== null) {
                    // Two different stories for the seller: an OPEN twin means
                    // "someone is on this", a CONVERTED one means "this is
                    // already our customer" — the request was grouped either way.
                    $dupRow = Leads::find($dupLeadId);
                    $key = ($dupRow && (string)$dupRow['status'] === 'converted')
                        ? 'lead_dup_customer_flash' : 'lead_dup_flash';
                    $flash = sprintf($t($key), $dupLeadId) . $vatWarn;
                    // Amber, not red: the request was not thrown away, it was written
                    // onto the twin as a note. Red read as "refused" and sent sellers
                    // hunting for something the CRM had already filed.
                    $flashType = 'warn';
                } else {
                    $flash = $t('saved') . $vatWarn;
                }
                if ($vatWarn !== '' && $flashType === 'ok') {
                    $flashType = 'warn';   // the lead saved; the confirmation did not go out
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
            // An open lead that is an existing customer: its request goes into the
            // customer's messages and the lead closes as 'customer' (quiet — an old
            // request, not a new one). Admin only — not in the agent whitelist.
            case 'lead_close_customer': {
                $lc = \Glue\Crm\LeadCustomers::closeIntoCustomer((int)$_POST['id'], (int)($_POST['customer_id'] ?? 0), $uid);
                if (empty($lc['ok'])) {
                    $_SESSION['dash_flash'] = [$t('lead_link_err_' . ($lc['error'] ?? 'not_customer')), 'err'];
                    header('Location: ?tab=leads&lead=' . (int)$_POST['id']);
                } else {
                    $_SESSION['dash_flash'] = [$t('lead_closed_customer'), 'ok'];
                    header('Location: ?tab=tickets&tk=' . (int)$lc['ticket_id']);
                }
                exit;
            }
            // The office confirms a "forse già cliente" suggestion: the lead moves onto
            // the customer's card and its own contact is merged into it. Admin only —
            // not in the agent whitelist.
            case 'lead_link_customer': {
                $lk = \Glue\Crm\LeadCustomers::link((int)$_POST['id'], (int)($_POST['customer_id'] ?? 0), 'manual', $uid);
                $_SESSION['dash_flash'] = empty($lk['ok'])
                    ? [$t('lead_link_err_' . ($lk['error'] ?? 'not_customer')), 'err']
                    : [$t('lead_linked'), 'ok'];
                header('Location: ?tab=leads&lead=' . (int)$_POST['id']);
                exit;
            }
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

            // ---------- warehouse: products the CRM owns ----------
            // Admin only (the tab is in $agentViews so sellers can LOOK things
            // up, but the catalogue is not theirs to rewrite).
            case 'article_create': {
                $ar = \Glue\Crm\Articles::create($_POST, $uid);
                $_SESSION['dash_flash'] = empty($ar['ok'])
                    ? [$t('ar_err_' . ($ar['error'] ?? 'no_code')), 'err']
                    : [$t('ar_created_ok'), 'ok'];
                header('Location: ?tab=articles' . (!empty($ar['id']) ? '&id=' . (int)$ar['id'] : ''));
                exit;
            }
            case 'article_edit': {
                $ar = \Glue\Crm\Articles::update((int)($_POST['id'] ?? 0), $_POST, $uid);
                $_SESSION['dash_flash'] = empty($ar['ok'])
                    ? [$t('ar_err_' . ($ar['error'] ?? 'not_found')), 'err']
                    : [!empty($ar['detached']) ? $t('ar_saved_detached') : $t('ar_saved'), 'ok'];
                header('Location: ?tab=articles&id=' . (int)($_POST['id'] ?? 0));
                exit;
            }
            case 'article_delete': {
                $ar = \Glue\Crm\Articles::delete((int)($_POST['id'] ?? 0), $uid);
                if (empty($ar['ok'])) {
                    $_SESSION['dash_flash'] = [$t('ar_err_not_found'), 'err'];
                    header('Location: ?tab=articles');
                    exit;
                }
                // An archived gestionale article still exists — land on it, so it
                // is obvious it was hidden rather than destroyed.
                $_SESSION['dash_flash'] = [$ar['archived'] ? $t('ar_archived_ok') : $t('ar_deleted_ok'), 'ok'];
                header('Location: ?tab=articles' . ($ar['archived'] ? '&id=' . (int)$_POST['id'] : ''));
                exit;
            }
            case 'article_restore': {
                \Glue\Crm\Articles::restore((int)($_POST['id'] ?? 0), $uid);
                $_SESSION['dash_flash'] = [$t('ar_restored_ok'), 'ok'];
                header('Location: ?tab=articles&id=' . (int)($_POST['id'] ?? 0));
                exit;
            }
            case 'article_stock': { // admin only — load / unload / set what is on the shelf
                $ar = \Glue\Crm\Articles::moveStock((int)($_POST['id'] ?? 0),
                    (string)($_POST['mode'] ?? 'set'), (string)($_POST['qty'] ?? ''),
                    (string)($_POST['note'] ?? ''), $uid);
                $_SESSION['dash_flash'] = empty($ar['ok'])
                    ? [$t('ar_err_' . ($ar['error'] ?? 'no_qty')), 'err']
                    : [sprintf($t('ar_stock_ok'), rtrim(rtrim(number_format((float)$ar['stock'], 2, ',', '.'), '0'), ',')), 'ok'];
                header('Location: ?tab=articles&id=' . (int)($_POST['id'] ?? 0));
                exit;
            }
            case 'article_stock_release': {
                $_SESSION['dash_flash'] = \Glue\Crm\Articles::releaseStock((int)($_POST['id'] ?? 0), $uid)
                    ? [$t('ar_stock_released'), 'ok'] : [$t('not_allowed'), 'err'];
                header('Location: ?tab=articles&id=' . (int)($_POST['id'] ?? 0));
                exit;
            }
            case 'article_threshold': {
                \Glue\Crm\Articles::setThreshold((int)($_POST['id'] ?? 0),
                    (string)($_POST['reorder_threshold'] ?? ''), $uid);
                $_SESSION['dash_flash'] = [$t('ar_threshold_ok'), 'ok'];
                header('Location: ?tab=articles&id=' . (int)($_POST['id'] ?? 0));
                exit;
            }

            // ---------- a product's sheet and its price lists (office only) ----------
            case 'article_lists': { // the per-list flags on the product's record
                $ok = \Glue\Crm\PriceLists::setForArticle((int)($_POST['id'] ?? 0),
                    (array)($_POST['on'] ?? []), (array)($_POST['price'] ?? []), $uid);
                $_SESSION['dash_flash'] = $ok ? [$t('pl_article_lists_ok'), 'ok'] : [$t('ar_err_not_found'), 'err'];
                header('Location: ?tab=articles&id=' . (int)($_POST['id'] ?? 0) . '#pl-lists');
                exit;
            }
            case 'article_photos':
            case 'article_files': {
                $amId = (int)($_POST['id'] ?? 0);
                $amR  = $do === 'article_photos'
                    ? \Glue\Crm\ArticleMedia::addPhotos($amId, $_FILES['photos'] ?? null, $uid)
                    : \Glue\Crm\ArticleMedia::addFiles($amId, $_FILES['files'] ?? null, $uid);
                $amWhy = ['too_big' => $t('pl_err_too_big'), 'bad_type' => $t('pl_err_bad_type'),
                          'save_failed' => $t('pl_err_save_failed'), 'not_found' => $t('ar_err_not_found')];
                $amErr = array_map(static function (string $e) use ($amWhy): string {
                    $p = strrpos($e, ': ');
                    return $p === false ? ($amWhy[$e] ?? $e) : substr($e, 0, $p) . ': ' . ($amWhy[substr($e, $p + 2)] ?? substr($e, $p + 2));
                }, $amR['errors']);
                $amMsg = $amR['count'] > 0
                    ? sprintf($t($do === 'article_photos' ? 'pl_photos_ok' : 'pl_files_ok'), $amR['count']) : '';
                if (!$amR['count'] && !$amErr) {
                    $amMsg = $t('pl_err_no_file');
                }
                if ($amErr) {
                    $amMsg = trim($amMsg . ' ' . $t('pl_err_upload') . ' ' . implode('; ', $amErr));
                }
                $_SESSION['dash_flash'] = [$amMsg, $amErr || !$amR['count'] ? ($amR['count'] ? 'warn' : 'err') : 'ok'];
                header('Location: ?tab=articles&id=' . $amId . '#pl-sheet');
                exit;
            }
            case 'article_media_del':
            case 'article_media_cover': {
                $amId = (int)($_POST['id'] ?? 0);
                $am   = \Glue\Crm\ArticleMedia::find((int)($_POST['media'] ?? 0));
                // The media row must belong to the product the form came from.
                $ok = $am && (int)$am['article_id'] === $amId && ($do === 'article_media_del'
                    ? \Glue\Crm\ArticleMedia::delete((int)$am['id'], $uid)
                    : \Glue\Crm\ArticleMedia::makeCover((int)$am['id']));
                $_SESSION['dash_flash'] = $ok
                    ? [$t($do === 'article_media_del' ? 'pl_media_deleted' : 'pl_cover_ok'), 'ok']
                    : [$t('not_allowed'), 'err'];
                header('Location: ?tab=articles&id=' . $amId . '#pl-sheet');
                exit;
            }
            case 'article_sheet': {
                $amId = (int)($_POST['id'] ?? 0);
                $amR  = \Glue\Crm\ArticleMedia::saveSheet($amId, (string)($_POST['web_description'] ?? ''),
                    (string)($_POST['info_url'] ?? ''), $uid);
                $_SESSION['dash_flash'] = !empty($amR['ok']) ? [$t('pl_sheet_ok'), 'ok']
                    : [$t(($amR['error'] ?? '') === 'bad_url' ? 'pl_err_bad_url' : 'ar_err_not_found'), 'err'];
                header('Location: ?tab=articles&id=' . $amId . '#pl-sheet');
                exit;
            }

            // ---------- price lists (office only) ----------
            case 'pricelist_save': {
                $plId = (int)($_POST['id'] ?? 0);
                $plR  = \Glue\Crm\PriceLists::save($_POST, $plId ?: null, $uid);
                if (empty($plR['ok'])) {
                    $_SESSION['dash_flash'] = [$t('pl_err_' . ($plR['error'] ?? 'no_name')), 'err'];
                    header('Location: ?tab=pricelists');
                    exit;
                }
                $_SESSION['dash_flash'] = [$t($plId ? 'pl_saved' : 'pl_created'), 'ok'];
                // A new list is empty: go straight to choosing what goes in it.
                header('Location: ?tab=pricelists' . ($plId ? '' : '&list=' . (int)$plR['id'] . '&manage=1&mode=all'));
                exit;
            }
            case 'pricelist_delete': {
                $_SESSION['dash_flash'] = \Glue\Crm\PriceLists::delete((int)($_POST['id'] ?? 0), $uid)
                    ? [$t('pl_deleted'), 'ok'] : [$t('pl_err_not_found'), 'err'];
                header('Location: ?tab=pricelists');
                exit;
            }
            case 'pricelist_members':
            case 'pricelist_add_all': {
                $plId   = (int)($_POST['id'] ?? 0);
                $plBack = (string)($_POST['back'] ?? '');
                if (!str_starts_with($plBack, '?tab=pricelists&') || preg_match('/[\r\n]/', $plBack)) {
                    $plBack = '?tab=pricelists&list=' . $plId . '&manage=1';
                }
                if ($do === 'pricelist_members') {
                    $plR = \Glue\Crm\PriceLists::setMembers($plId, (array)($_POST['shown'] ?? []),
                        (array)($_POST['on'] ?? []), (array)($_POST['price'] ?? []), $uid);
                    $_SESSION['dash_flash'] = [sprintf($t('pl_members_ok'), $plR['added'], $plR['removed']), 'ok'];
                } else {
                    $plN = \Glue\Crm\PriceLists::addMatching($plId, [
                        'q' => (string)($_POST['q'] ?? ''), 'category' => (string)($_POST['category'] ?? ''),
                        'state' => !empty($_POST['stock']) ? 'in_stock' : 'all',
                    ], $uid);
                    $_SESSION['dash_flash'] = [sprintf($t('pl_added_all_ok'), $plN), 'ok'];
                }
                header('Location: ' . $plBack);
                exit;
            }

            // ---------- an appointment, booked inside the lead ----------
            // Named lead_* so the ownership guard above already applies: a seller
            // can only book on a lead that is theirs.
            case 'lead_appointment': {
                $apLead = Leads::find((int)$_POST['id']);
                $apWhen = trim((string)($_POST['starts_at'] ?? ''));
                if (!$apLead || strtotime($apWhen) === false) {
                    $_SESSION['dash_flash'] = [$t('ap_err_when'), 'err'];
                    header('Location: ?tab=leads&lead=' . (int)$_POST['id']);
                    exit;
                }
                // Whose diary it goes in: a seller books for themselves, an admin
                // picks, and failing that it falls to whoever owns the lead.
                $apAgent = $isAgent ? (int)$scopeId
                    : ((int)($_POST['agent_id'] ?? 0) ?: (int)($apLead['assigned_to'] ?? 0));
                if ($apAgent <= 0) {
                    $_SESSION['dash_flash'] = [$t('ap_err_agent'), 'err'];
                    header('Location: ?tab=leads&lead=' . (int)$apLead['id']);
                    exit;
                }
                $apId = Appointments::request([
                    'contact_id' => $apLead['contact_id'] ?: null,
                    'lead_id'    => $apLead['id'],
                    'agent_id'   => $apAgent,
                    'title'      => $_POST['title'] ?? '',
                    'location'   => $_POST['location'] ?? '',
                    'notes'      => $_POST['notes'] ?? '',
                    'name'       => $apLead['customer_name'],
                    'phone'      => $apLead['customer_phone'],
                    'email'      => $apLead['customer_email'],
                    'lang'       => $apLead['lang'] ?? null,
                ], $uid);
                // Booked, not merely requested: confirming it here is what sends
                // the customer their confirmation, the agent theirs, and queues
                // the run-up reminders for both.
                $apN = Appointments::schedule($apId, $apAgent, $apWhen, [
                    'title'    => $_POST['title'] ?? '',
                    'location' => $_POST['location'] ?? '',
                ], $uid);
                Activities::add('lead', (int)$apLead['id'], 'meeting',
                    'Appuntamento fissato per ' . date('d/m/Y H:i', (int)strtotime($apWhen))
                    . (trim((string)($_POST['location'] ?? '')) !== ''
                        ? ' — ' . trim((string)$_POST['location']) : ''), $uid);
                $_SESSION['dash_flash'] = [sprintf($t('ap_booked'),
                    date('d/m/Y H:i', (int)strtotime($apWhen)), $apN), 'ok'];
                header('Location: ?tab=leads&lead=' . (int)$apLead['id']);
                exit;
            }

            // ---------- quote requests ----------
            // The button inside the lead record. Named lead_* on purpose: that
            // prefix already carries the ownership guard above, so a seller can
            // only ask for a quote on a lead that is actually theirs.
            case 'lead_quote': {
                $qrLead = (int)$_POST['id'];
                $qr = \Glue\Crm\QuoteRequests::open($qrLead, $uid, (string)($_POST['notes'] ?? ''));
                $_SESSION['dash_flash'] = empty($qr['ok'])
                    ? [$t('qt_err_' . ($qr['error'] ?? 'no_lead')), 'err']
                    : [$t(!empty($qr['duplicate']) ? 'qt_already_sent' : 'qt_requested'), 'ok'];
                // Back on the same lead, opened, where the request now shows under
                // Preventivo. Landing on the closed board read as "nothing happened".
                header('Location: ?tab=leads&lead=' . $qrLead . '#lead-' . $qrLead);
                exit;
            }
            case 'quote_scratch': {
                $qr = \Glue\Crm\QuoteRequests::fromScratch([
                    'first_name' => $_POST['first_name'] ?? '', 'last_name' => $_POST['last_name'] ?? '',
                    'company'    => $_POST['company'] ?? '',    'vat_number' => $_POST['vat_number'] ?? '',
                    'phone'      => $_POST['phone'] ?? '',      'email'      => $_POST['email'] ?? '',
                    'zone'       => $_POST['zone'] ?? '',       'source'     => $_POST['source'] ?? '',
                    'lang'       => $_POST['lang'] ?? null,     'notes'      => $_POST['notes'] ?? '',
                    // A seller's own entry stays in their scope, as on the Leads
                    // tab; an office entry stays unassigned until someone takes it.
                    'assign_to'  => $isAgent ? (int)$scopeId : 0,
                ], $uid);
                if (empty($qr['ok'])) {
                    $_SESSION['dash_flash'] = [$t('qt_err_' . ($qr['error'] ?? 'no_identity')), 'err'];
                } else {
                    // Say which of the two happened — attached to a customer we
                    // already had, or filed on a lead this form just created.
                    $_SESSION['dash_flash'] = [sprintf(
                        $t(!empty($qr['created']) ? 'qt_scratch_new' : 'qt_scratch_matched'),
                        (int)$qr['lead_id']), 'ok'];
                }
                header('Location: ?tab=quotes');
                exit;
            }
            case 'quote_upload': { // office only — the finished quote comes back
                $qr = \Glue\Crm\QuoteRequests::attachQuote(
                    (int)$_POST['id'], $_FILES['quote'] ?? null, $uid);
                if (!empty($qr['ok'])) {
                    $_SESSION['dash_flash'] = [$t('qt_uploaded'), 'ok'];
                } else {
                    // The upload rejections (no_file / too_big / bad_type) are the
                    // signing flow's own and already have copy; the rest are ours.
                    $qe = (string)($qr['error'] ?? 'save_failed');
                    $qk = $t('qt_err_' . $qe) !== 'qt_err_' . $qe ? 'qt_err_' . $qe
                        : ($t('dc_err_' . $qe) !== 'dc_err_' . $qe ? 'dc_err_' . $qe : 'qt_err_save_failed');
                    $_SESSION['dash_flash'] = [$t($qk), 'err'];
                }
                header('Location: ?tab=quotes');
                exit;
            }
            case 'quote_send': {
                $qr = \Glue\Crm\QuoteRequests::sendToCustomer((int)$_POST['id'], $uid);
                $_SESSION['dash_flash'] = empty($qr['ok'])
                    ? [$t('qt_err_' . ($qr['error'] ?? 'no_quote')), 'err']
                    : [$t('qt_sent'), 'ok'];
                header('Location: ?tab=quotes');
                exit;
            }
            // The seller sends the generated quote back for changes. Owner-guarded
            // by the quote_ prefix above: only the seller who asked for it.
            case 'quote_revise': {
                $qr = \Glue\Crm\QuoteRequests::requestRevision((int)$_POST['id'], $uid, (string)($_POST['note'] ?? ''));
                $_SESSION['dash_flash'] = empty($qr['ok'])
                    ? [$t('qt_err_' . ($qr['error'] ?? 'not_ready')), 'err']
                    : [$t('qt_revised'), 'ok'];
                header('Location: ?tab=quotes');
                exit;
            }
            case 'quote_cancel': { // office only
                $_SESSION['dash_flash'] = \Glue\Crm\QuoteRequests::cancel((int)$_POST['id'], $uid)
                    ? [$t('qt_cancelled'), 'ok'] : [$t('not_allowed'), 'err'];
                header('Location: ?tab=quotes');
                exit;
            }
            case 'quote_lines_save': { // office only — the quote builder
                $qid = (int)($_POST['id'] ?? 0);
                $qs  = \Glue\Crm\QuoteRequests::saveLines($qid, (array)($_POST['lines'] ?? []), [
                    'discount_pct'   => $_POST['discount_pct'] ?? '',
                    'valid_until'    => $_POST['valid_until'] ?? '',
                    'customer_notes' => $_POST['customer_notes'] ?? '',
                    'price_list_id'  => $_POST['price_list_id'] ?? 0,
                ], $uid);
                if (empty($qs['ok'])) {
                    $_SESSION['dash_flash'] = [$t('qt_err_' . ($qs['error'] ?? 'not_found')), 'err'];
                    header('Location: ?tab=quotes&build=' . $qid);
                    exit;
                }
                if (($_POST['then'] ?? '') === 'generate') {
                    $qg = \Glue\Crm\QuoteRequests::generateDocument($qid, $uid);
                    if (!empty($qg['ok'])) {
                        $_SESSION['dash_flash'] = [sprintf($t('qt_generated'), (string)$qg['number']), 'ok'];
                        header('Location: ?tab=quotes');
                        exit;
                    }
                    $qe = (string)($qg['error'] ?? 'save_failed');
                    $_SESSION['dash_flash'] = [$t('qt_err_' . $qe) !== 'qt_err_' . $qe
                        ? $t('qt_err_' . $qe) : $t('qt_err_save_failed'), 'err'];
                } else {
                    $_SESSION['dash_flash'] = [$t('qt_lines_saved'), 'ok'];
                }
                header('Location: ?tab=quotes&build=' . $qid);
                exit;
            }

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

            // ---------- installation reports ----------
            // All PRG: the technician is on a phone in a shop with weak signal,
            // where a hung POST gets reloaded — and a reloaded POST re-sends
            // photos or re-messages the customer.
            case 'install_create': {
                $irContact = (int)($_POST['contact_id'] ?? 0);
                $irRow     = $irContact > 0 ? Contacts::find($irContact) : null;
                // An agent who installs reaches the two lists the picker shows
                // them — the registry and every lead, in any state — not any
                // contact id typed into a forged form.
                if ($irRow && $isAgent && empty($irRow['is_customer'])) {
                    $irOwn = $pdo->prepare('SELECT 1 FROM leads WHERE contact_id = ? LIMIT 1');
                    $irOwn->execute([$irContact]);
                    if (!$irOwn->fetchColumn()) {
                        $irRow = null;
                    }
                }
                if (!$irRow) {
                    $_SESSION['dash_flash'] = [$t('ir_need_customer'), 'err'];
                    header('Location: ?tab=installations');
                    exit;
                }
                $irId = InstallReports::create($irContact, $uid);
                $_SESSION['dash_flash'] = [$t('ir_created'), 'ok'];
                header('Location: ?tab=installations&id=' . $irId);
                exit;
            }
            case 'install_save': {
                $ok = InstallReports::update((int)$_POST['id'], $_POST);
                $_SESSION['dash_flash'] = [$ok ? $t('saved') : $t('ir_locked'), $ok ? 'ok' : 'err'];
                header('Location: ?tab=installations&id=' . (int)$_POST['id']);
                exit;
            }
            case 'install_photos': {
                $irRes = InstallReports::addPhotos(
                    (int)$_POST['id'], (string)($_POST['kind'] ?? 'final'), $_FILES['photos'] ?? null);
                if (in_array('not_draft', $irRes['errors'], true)) {
                    $_SESSION['dash_flash'] = [$t('ir_locked'), 'err'];
                } elseif ($irRes['errors']) {
                    $_SESSION['dash_flash'] = [sprintf($t('ir_photos_added'), $irRes['saved'])
                        . ' · ' . count($irRes['errors']) . ' ' . $t('ir_photos_failed'), 'warn'];
                } else {
                    $_SESSION['dash_flash'] = [sprintf($t('ir_photos_added'), $irRes['saved']),
                        $irRes['saved'] > 0 ? 'ok' : 'warn'];
                }
                header('Location: ?tab=installations&id=' . (int)$_POST['id']);
                exit;
            }
            case 'install_photo_del': {
                InstallReports::deletePhoto((int)($_POST['photo_id'] ?? 0), (int)$_POST['id']);
                $_SESSION['dash_flash'] = [$t('saved'), 'ok'];
                header('Location: ?tab=installations&id=' . (int)$_POST['id']);
                exit;
            }
            case 'install_send': {
                $irRes = InstallReports::send((int)$_POST['id'], $uid);
                if ($irRes['ok']) {
                    $_SESSION['dash_flash'] = [$t('ir_sent'), 'ok'];
                } else {
                    $irMsg = match ($irRes['error']) {
                        'no_channel'   => $t('ir_no_channel'),
                        'not_draft'    => $t('ir_locked'),
                        'no_test_date' => $t('ir_no_test_date'),
                        default        => $t('ir_send_failed') . ' (' . (string)$irRes['error'] . ')',
                    };
                    $_SESSION['dash_flash'] = [$irMsg, 'err'];
                }
                header('Location: ?tab=installations&id=' . (int)$_POST['id']);
                exit;
            }
            // ---------- surveys (sopralluoghi) ----------
            case 'insp_create': {
                $ispId = Inspections::create((int)($_POST['contact_id'] ?? 0), $uid);
                header('Location: ?tab=inspections&id=' . $ispId);
                exit;
            }
            case 'insp_save': {
                Inspections::update((int)$_POST['id'], $_POST);
                $_SESSION['dash_flash'] = [$t('isp_saved'), 'ok'];
                header('Location: ?tab=inspections&id=' . (int)$_POST['id']);
                exit;
            }
            case 'insp_photos': {
                $ispRes = Inspections::addPhotos((int)$_POST['id'], $_FILES['photos'] ?? null);
                if (in_array('dir_unwritable', $ispRes['errors'], true)) {
                    $_SESSION['dash_flash'] = [$t('ir_photo_dir'), 'err'];
                } elseif (in_array('not_draft', $ispRes['errors'], true)) {
                    $_SESSION['dash_flash'] = [$t('ir_locked'), 'err'];
                } elseif ($ispRes['errors']) {
                    $_SESSION['dash_flash'] = [sprintf($t('ir_photos_added'), $ispRes['saved'])
                        . ' · ' . count($ispRes['errors']) . ' ' . $t('ir_photos_failed'), 'warn'];
                } else {
                    $_SESSION['dash_flash'] = [sprintf($t('ir_photos_added'), $ispRes['saved']),
                        $ispRes['saved'] > 0 ? 'ok' : 'warn'];
                }
                header('Location: ?tab=inspections&id=' . (int)$_POST['id']);
                exit;
            }
            case 'insp_photo_del': {
                Inspections::deletePhoto((int)$_POST['id'], (int)($_POST['photo_id'] ?? 0));
                header('Location: ?tab=inspections&id=' . (int)$_POST['id']);
                exit;
            }
            case 'insp_send': {
                $ispRes = Inspections::send((int)$_POST['id'], $uid);
                $_SESSION['dash_flash'] = $ispRes['ok']
                    ? [$t('isp_sent'), 'ok']
                    : [match ($ispRes['error']) {
                        'no_channel' => $t('ir_no_channel'),
                        'not_draft'  => $t('ir_locked'),
                        default      => $t('ir_send_failed') . ' (' . (string)$ispRes['error'] . ')',
                      }, 'err'];
                header('Location: ?tab=inspections&id=' . (int)$_POST['id']);
                exit;
            }
            case 'insp_claim': { // a technician takes the opinion on — first press wins
                if (!$uid) {
                    $_SESSION['dash_flash'] = [$t('as_claim_needs_user'), 'warn'];
                } else {
                    $ok = Inspections::claim((int)$_POST['id'], (int)$uid);
                    $_SESSION['dash_flash'] = [$ok ? $t('isp_claimed') : $t('isp_claim_lost'), $ok ? 'ok' : 'warn'];
                }
                header('Location: ?tab=inspections&id=' . (int)$_POST['id']);
                exit;
            }
            case 'insp_opinion': { // save the opinion without sending it yet
                $ispId = (int)$_POST['id'];
                // Only whoever took it in charge writes it; the office may always
                // step in, because somebody has to when a technician is away.
                $ispRow = Inspections::find($ispId);
                if ($isTech && $ispRow && (int)($ispRow['claimed_by'] ?? 0) !== (int)$uid) {
                    $_SESSION['dash_flash'] = [$t('isp_not_yours'), 'err'];
                } else {
                    Inspections::saveOpinion($ispId, (string)($_POST['opinion_text'] ?? ''),
                        (int)($_POST['opinion_stars'] ?? 0), $uid);
                    $_SESSION['dash_flash'] = [$t('isp_opinion_saved'), 'ok'];
                }
                header('Location: ?tab=inspections&id=' . $ispId);
                exit;
            }
            case 'insp_opinion_send': {
                $ispId = (int)$_POST['id'];
                $ispRow = Inspections::find($ispId);
                if ($isTech && $ispRow && (int)($ispRow['claimed_by'] ?? 0) !== (int)$uid) {
                    $_SESSION['dash_flash'] = [$t('isp_not_yours'), 'err'];
                    header('Location: ?tab=inspections&id=' . $ispId);
                    exit;
                }
                // Save whatever is in the boxes first, so pressing "send" never
                // posts an older opinion than the one on screen.
                Inspections::saveOpinion($ispId, (string)($_POST['opinion_text'] ?? ''),
                    (int)($_POST['opinion_stars'] ?? 0), $uid);
                $ispRes = Inspections::sendOpinion($ispId, $uid);
                $_SESSION['dash_flash'] = $ispRes['ok']
                    ? [$t('isp_opinion_sent'), 'ok']
                    : [match ($ispRes['error']) {
                        'no_text'    => $t('isp_err_text'),
                        'no_stars'   => $t('isp_err_stars'),
                        'no_channel' => $t('ir_no_channel'),
                        default      => $t('isp_err_not_ready'),
                      }, 'err'];
                header('Location: ?tab=inspections&id=' . $ispId);
                exit;
            }

            case 'install_delete': // admin only (not in the tech/agent whitelists)
                $ok = InstallReports::delete((int)$_POST['id'], $uid);
                $_SESSION['dash_flash'] = [$ok ? $t('ir_deleted') : $t('not_allowed'), $ok ? 'ok' : 'err'];
                header('Location: ?tab=installations' . ($ok ? '' : '&id=' . (int)$_POST['id']));
                exit;

            // ---------- assistance requests ----------
            case 'assist_claim': // a technician takes charge — first press wins
                if (!$uid) {
                    // The master-password admin has no user row to own the ticket.
                    $_SESSION['dash_flash'] = [$t('as_claim_needs_user'), 'warn'];
                } else {
                    $ok = \Glue\Portal\AssistRequests::claim((int)$_POST['id'], (int)$uid);
                    $_SESSION['dash_flash'] = [$ok ? $t('as_claimed') : $t('as_claim_lost'), $ok ? 'ok' : 'warn'];
                }
                header('Location: ?tab=support');
                exit;
            case 'assist_forward': // the customer paid another way, or the admin waives the gate
                $ok = \Glue\Portal\AssistRequests::forward((int)$_POST['id']);
                $_SESSION['dash_flash'] = [$ok ? $t('as_forwarded') : $t('not_allowed'), $ok ? 'ok' : 'err'];
                header('Location: ?tab=support');
                exit;
            case 'assist_cancel':
                $ok = \Glue\Portal\AssistRequests::cancel((int)$_POST['id']);
                $_SESSION['dash_flash'] = [$ok ? $t('as_cancelled') : $t('not_allowed'), $ok ? 'ok' : 'err'];
                header('Location: ?tab=support');
                exit;

            // ---------- technical interventions ----------
            case 'interv_schedule': { // book, or move, the visit for a request
                $reqId = (int)($_POST['req_id'] ?? 0);
                $when  = trim((string)($_POST['starts_at'] ?? ''));
                $req   = \Glue\Portal\AssistRequests::find($reqId);
                // The office may book on anyone's behalf; a technician only on
                // the request they themselves took charge of.
                $techId = (!$isAgent && !$isTech && !empty($_POST['tech_id']))
                    ? (int)$_POST['tech_id']
                    : (int)($req['claimed_by'] ?? 0);
                if (!$req || $techId <= 0 || ($isTech && (int)$req['claimed_by'] !== (int)$uid)) {
                    $_SESSION['dash_flash'] = [$req ? $t('iv_err_tech') : $t('not_allowed'), 'err'];
                    header('Location: ?tab=support');
                    exit;
                }
                if ($when === '' || !strtotime($when)) {
                    $_SESSION['dash_flash'] = [$t('iv_err_when'), 'err'];
                    header('Location: ?tab=support');
                    exit;
                }
                $mins   = (int)($_POST['duration_min'] ?? \Glue\Crm\Interventions::DEFAULT_MIN);
                $prevId = (int)($req['appointment_id'] ?? 0);
                // Warn, do not refuse: the office sometimes double-books a
                // technician on purpose (two jobs in the same building), and
                // refusing would send them back to the phone.
                $clash = Interventions::clashes($techId, $when, $mins, $prevId);
                $apptId = Interventions::schedule($reqId, $techId, $when, [
                    'title'        => $_POST['title'] ?? '',
                    'location'     => $_POST['location'] ?? '',
                    'notes'        => $_POST['notes'] ?? '',
                    'duration_min' => $mins,
                ], $uid);
                if ($apptId <= 0) {
                    $_SESSION['dash_flash'] = [$t('iv_err_failed'), 'err'];
                } elseif ($clash) {
                    $who  = trim((string)($pdo->query('SELECT COALESCE(NULLIF(full_name, ""), username) FROM users WHERE id = ' . $techId)->fetchColumn() ?: ''));
                    $what = implode(', ', array_map(
                        fn($c) => short_time($c['starts_at']) . ' ' . ($c['customer_name'] ?: $c['title']),
                        $clash));
                    $_SESSION['dash_flash'] = [sprintf($t('iv_clash'), $who, $what), 'warn'];
                } else {
                    // Moved only when the same row was reused; a fresh id means
                    // this is a second visit, not a change of date.
                    $moved = $prevId > 0 && $apptId === $prevId;
                    $_SESSION['dash_flash'] = [
                        sprintf($t($moved ? 'iv_moved' : 'iv_saved'),
                            \Glue\Reminder\Templates::when((int)strtotime($when), $lang, true)),
                        'ok'];
                }
                header('Location: ?tab=support');
                exit;
            }
            case 'interv_status':
                Interventions::setStatus((int)$_POST['id'], (string)($_POST['status'] ?? ''), $uid);
                $_SESSION['dash_flash'] = [$t('iv_status_set'), 'ok'];
                header('Location: ?tab=' . (($_POST['back'] ?? '') === 'calendar' ? 'calendar' : 'support'));
                exit;

            // ---------- the calendar: book, assign, move ----------
            case 'cal_save': { // create or edit an appointment from the calendar
                $calId = (int)($_POST['id'] ?? 0);
                // EDITING one: a seller or a technician may only touch a visit
                // that is already theirs, or one sitting in the pool. Without
                // this they could post any appointment id and rewrite somebody
                // else's day — the generic owner guard skips cal_* because the
                // pool is owned by nobody, so the check belongs here.
                if ($calId > 0 && ($isAgent || $isTech)) {
                    $calRow = Appointments::find($calId);
                    $calMine = $calRow
                        && ((int)($calRow['agent_id'] ?? 0) === (int)$uid || empty($calRow['agent_id']));
                    if (!$calMine) {
                        $_SESSION['dash_flash'] = [$t('not_allowed'), 'err'];
                        header('Location: ?tab=calendar' . calBackQs());
                        exit;
                    }
                }
                // Who it belongs to afterwards. A technician handing an overloaded
                // day to a colleague is the point of the field — they reach it
                // only on a visit the check above already let through. Creating
                // one, they can only book it for themselves; the office may also
                // leave it in the pool (0) deliberately.
                if (!$isAgent && !$isTech) {
                    $agentId = (int)($_POST['agent_id'] ?? 0);
                } elseif ($calId > 0 && isset($_POST['agent_id'])) {
                    $agentId = (int)$_POST['agent_id'];
                } else {
                    $agentId = (int)$uid;
                }
                $res = \Glue\Crm\Booking::save([
                    'id'           => $calId,
                    'contact_id'   => (int)($_POST['contact_id'] ?? 0),
                    'type_code'    => (string)($_POST['type_code'] ?? ''),
                    'agent_id'     => $agentId,
                    'starts_at'    => (string)($_POST['starts_at'] ?? ''),
                    'duration_min' => (int)($_POST['duration_min'] ?? 60),
                    'zone'         => (string)($_POST['zone'] ?? ''),
                    'location'     => (string)($_POST['location'] ?? ''),
                    'title'        => (string)($_POST['title'] ?? ''),
                    'notes'        => (string)($_POST['notes'] ?? ''),
                ], $uid);
                $msg = match ($res['error']) {
                    null           => $res['clashes'] ? $t('cal_saved_clash') : $t('cal_saved'),
                    'no_customer'  => $t('cal_err_customer'),
                    'no_zone'      => $t('cal_err_zone'),
                    'bad_when'     => $t('iv_err_when'),
                    default        => $t('iv_err_failed'),
                };
                $_SESSION['dash_flash'] = [$msg,
                    $res['ok'] ? ($res['clashes'] ? 'warn' : 'ok') : 'err'];
                header('Location: ?tab=calendar' . calBackQs());
                exit;
            }
            case 'cal_assign': { // take one out of the pool, or hand it to a colleague
                $to = (int)($_POST['to_id'] ?? 0);
                // "Prendo io" is any technician's to press. Giving a job to a
                // NAMED colleague is theirs too — the client asked for exactly
                // that — but only on a job that is currently unowned or their own.
                $appt = Appointments::find((int)($_POST['id'] ?? 0));
                $mine = $appt && ((int)($appt['agent_id'] ?? 0) === (int)$uid || empty($appt['agent_id']));
                if (!$appt || (($isAgent || $isTech) && !$mine)) {
                    $_SESSION['dash_flash'] = [$t('not_allowed'), 'err'];
                } else {
                    \Glue\Crm\Booking::assign((int)$appt['id'], $to ?: null, $uid);
                    $_SESSION['dash_flash'] = [$to ? $t('cal_assigned') : $t('cal_pooled'), 'ok'];
                }
                header('Location: ?tab=calendar' . calBackQs());
                exit;
            }
            case 'cal_move': { // drag-and-drop, or a new time typed into the form
                $appt = Appointments::find((int)($_POST['id'] ?? 0));
                $mine = $appt && ((int)($appt['agent_id'] ?? 0) === (int)$uid || empty($appt['agent_id']));
                if (!$appt || (($isAgent || $isTech) && !$mine)) {
                    if ($ajax) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
                    $_SESSION['dash_flash'] = [$t('not_allowed'), 'err'];
                    header('Location: ?tab=calendar' . calBackQs());
                    exit;
                }
                $ok = \Glue\Crm\Booking::move((int)$appt['id'], (string)($_POST['starts_at'] ?? ''),
                    isset($_POST['duration_min']) ? (int)$_POST['duration_min'] : null, $uid);
                if ($ajax) { echo json_encode(['ok' => $ok]); exit; }
                $_SESSION['dash_flash'] = [$ok ? $t('cal_moved') : $t('iv_err_when'), $ok ? 'ok' : 'err'];
                header('Location: ?tab=calendar' . calBackQs());
                exit;
            }
            case 'cal_newcust': { // "+ nuovo cliente" from inside the booking form
                $res = \Glue\Crm\Customers::createManual([
                    'first_name' => $_POST['first_name'] ?? '', 'last_name' => $_POST['last_name'] ?? '',
                    'company'    => $_POST['company'] ?? '',
                    'phone'      => $_POST['phone'] ?? '',   'email'   => $_POST['email'] ?? '',
                    'vat_number' => $_POST['vat_number'] ?? '',
                    'address'    => $_POST['address'] ?? '', 'city'    => $_POST['city'] ?? '',
                    'province'   => $_POST['province'] ?? '', 'zip'    => $_POST['zip'] ?? '',
                ], $uid);
                if ($ajax) {
                    // The form stays open and the picker fills itself in, which
                    // is the whole point of creating from here.
                    $c = $res['ok'] ? Contacts::find((int)$res['id']) : null;
                    echo json_encode([
                        'ok'    => (bool)$res['ok'],
                        'id'    => (int)$res['id'],
                        'label' => $c ? (trim((string)$c['name']) ?: (string)$c['company']) : '',
                        'error' => $res['error'],
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $_SESSION['dash_flash'] = [$res['ok'] ? $t('cu_created') : $t('cal_err_customer'),
                    $res['ok'] ? 'ok' : 'err'];
                header('Location: ?tab=calendar' . calBackQs());
                exit;
            }
            case 'plan_confirm': // "ho pianificato" pressed inside the CRM
                if ($uid) {
                    \Glue\Crm\DayPlanner::confirmFor((int)$uid,
                        (string)($_POST['plan_date'] ?? date('Y-m-d', strtotime('+1 day'))));
                    $_SESSION['dash_flash'] = [$t('plan_confirmed'), 'ok'];
                }
                header('Location: ?tab=calendar' . calBackQs());
                exit;

            case 'cal_feed_reset': // the phone-subscription address leaked, or they want a new one
                if ($uid) {
                    CrmCalendar::resetToken((int)$uid);
                    $_SESSION['dash_flash'] = [$t('cal_feed_reset_ok'), 'ok'];
                }
                header('Location: ?tab=calendar');
                exit;

            // ---------- maintenance contract ----------
            case 'maint_save': { // the office types in a contract the CRM cannot derive
                $cid = (int)($_POST['id'] ?? 0);
                $fee = trim((string)($_POST['maint_fee'] ?? ''));
                Contacts::update($cid, [
                    'maint_type'      => trim((string)($_POST['maint_type'] ?? '')) ?: null,
                    // Money is stored in cents, like every other amount here.
                    'maint_fee_cents' => $fee === '' ? null : (int)round(((float)str_replace(',', '.', $fee)) * 100),
                    'maint_period'    => in_array($_POST['maint_period'] ?? '', ['monthly','quarterly','yearly'], true)
                        ? (string)$_POST['maint_period'] : null,
                    'maint_note'      => trim((string)($_POST['maint_note'] ?? '')) ?: null,
                ]);
                $_SESSION['dash_flash'] = [$t('mt_saved'), 'ok'];
                header('Location: ?tab=customers&id=' . $cid);
                exit;
            }

            // ---------- contacts ----------
            case 'contact_create':
                Contacts::create([
                    'first_name' => $_POST['first_name'] ?? '', 'last_name' => $_POST['last_name'] ?? '',
                    'company' => $_POST['company'] ?? '',
                    'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? '',
                    'lang' => $_POST['lang'] ?? null, 'notes' => $_POST['notes'] ?? '',
                ]);
                $flash = $t('saved');
                $tab = 'contacts';
                break;

            // The split of an older contact's name is a guess (see migration 036);
            // this is where a wrong one gets corrected.
            case 'contact_edit':
                Contacts::update((int)($_POST['id'] ?? 0), [
                    'first_name' => $_POST['first_name'] ?? '', 'last_name' => $_POST['last_name'] ?? '',
                    'company' => $_POST['company'] ?? '',
                    'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? '',
                    'lang' => $_POST['lang'] ?? null, 'notes' => $_POST['notes'] ?? '',
                ]);
                $flash = $t('saved');
                $tab = 'contacts';
                break;

            // ---------- customers ----------
            case 'customer_create': {
                $res = \Glue\Crm\Customers::createManual($_POST, $uid ?: null);
                if ($res['ok']) {
                    $_SESSION['dash_flash'] = [$t('cu_created'), 'ok'];
                    header('Location: ?tab=customers&id=' . (int)$res['id']);
                } elseif ($res['error'] === 'code_taken') {
                    // The code names an existing customer — go look at them
                    // instead of typing a twin.
                    $_SESSION['dash_flash'] = [$t('cu_code_taken'), 'err'];
                    header('Location: ?tab=customers&id=' . (int)$res['id']);
                } else {
                    $_SESSION['dash_flash'] = [$t('cu_need_name'), 'err'];
                    header('Location: ?tab=customers');
                }
                exit;
            }

            case 'customer_edit': {
                $cuId = (int)($_POST['id'] ?? 0);
                // Most gestionale customers are companies: first/last empty, the
                // whole name in name/company. Passing empty name parts through
                // Contacts::update would re-derive the name as 'Unknown' — so a
                // company row updates its name from the company box instead.
                $cuFirst = trim((string)($_POST['first_name'] ?? ''));
                $cuLast  = trim((string)($_POST['last_name'] ?? ''));
                $cuCo    = trim((string)($_POST['company'] ?? ''));
                $cuName  = ($cuFirst !== '' || $cuLast !== '')
                    ? ['first_name' => $cuFirst, 'last_name' => $cuLast]
                    : ($cuCo !== '' ? ['name' => $cuCo, 'first_name' => '', 'last_name' => ''] : []);
                Contacts::update($cuId, $cuName + [
                    'company' => $cuCo ?: null,
                    'phone'   => trim((string)($_POST['phone'] ?? '')) ?: null,
                    'phone2'  => trim((string)($_POST['phone2'] ?? '')) ?: null,
                    'email'   => trim((string)($_POST['email'] ?? '')) ?: null,
                    'pec'     => trim((string)($_POST['pec'] ?? '')) ?: null,
                    'notes'   => $_POST['notes'] ?? '',
                    'customer_code'    => trim((string)($_POST['customer_code'] ?? '')) ?: null,
                    'vat_number'       => \Glue\Crm\VatLock::normalize((string)($_POST['vat_number'] ?? '')) ?: null,
                    'address'          => trim((string)($_POST['address'] ?? '')) ?: null,
                    'city'             => trim((string)($_POST['city'] ?? '')) ?: null,
                    'province'         => mb_substr(trim((string)($_POST['province'] ?? '')), 0, 8) ?: null,
                    'zip'              => trim((string)($_POST['zip'] ?? '')) ?: null,
                    'contract_expiry'  => trim((string)($_POST['contract_expiry'] ?? '')) ?: null,
                    'gestionale_agent' => trim((string)($_POST['gestionale_agent'] ?? '')) ?: null,
                ]);
                $_SESSION['dash_flash'] = [$t('saved'), 'ok'];
                header('Location: ?tab=customers&id=' . $cuId);
                exit;
            }

            case 'customer_delete': { // admin only — the card and what lived only on it
                $cuId = (int)($_POST['id'] ?? 0);
                $cuDel = \Glue\Crm\Customers::delete($cuId, $uid ?: null);
                if ($cuDel['ok']) {
                    // A gestionale customer comes back with the next CLIENTI
                    // import as long as it is in the export — say so now, not
                    // in fifteen minutes when it reappears.
                    $_SESSION['dash_flash'] = [$t('cu_deleted') . ($cuDel['code'] !== null ? ' ' . $t('cu_deleted_reimport') : ''), 'ok'];
                    header('Location: ?tab=customers');
                } elseif ($cuDel['error'] === 'install_reports') {
                    $_SESSION['dash_flash'] = [sprintf($t('cu_del_reports'), (int)$cuDel['reports']), 'err'];
                    header('Location: ?tab=customers&id=' . $cuId);
                } else {
                    $_SESSION['dash_flash'] = [$t('not_allowed'), 'err'];
                    header('Location: ?tab=customers');
                }
                exit;
            }

            // The gestionale's CLIENTI export, uploaded by hand. The FTP drop
            // directory goes through bin/import-clienti.php instead.
            case 'customer_import': {
                if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                    $_SESSION['dash_flash'] = [$t('test_fail'), 'err'];
                    header('Location: ?tab=customers');
                    exit;
                }
                $r = \Glue\Crm\CustomerImport::run($_FILES['file']['tmp_name'], $uid ?: null);
                if ($r['already']) {
                    $_SESSION['dash_flash'] = [$t('cu_import_dup'), 'warn'];
                } else {
                    // New VAT numbers should claim their invoices right away.
                    SibillInvoices::relink();
                    SibillCustomers::rebuild();
                    $_SESSION['dash_flash'] = [
                        sprintf($t('cu_imported'), (string)$_FILES['file']['name'], $r['created'], $r['updated'], $r['skipped']), 'ok',
                    ];
                }
                header('Location: ?tab=customers');
                exit;
            }

            // Open a customer's portal: mint the magic link, show it to the
            // admin (so they can open the area themselves to test messaging) and
            // send it to the customer on WhatsApp + email.
            case 'customer_quote': { // ask the back office to price this customer
                $cuId = (int)($_POST['id'] ?? 0);
                $cq = \Glue\Crm\QuoteRequests::forCustomer($cuId, $uid, (string)($_POST['notes'] ?? ''));
                $_SESSION['dash_flash'] = empty($cq['ok'])
                    ? [$t('qt_err_' . ($cq['error'] ?? 'no_contact')), 'err']
                    : [$t('qt_requested'), 'ok'];
                header('Location: ?tab=customers&id=' . $cuId);
                exit;
            }

            case 'customer_portal_invite': {
                $cuId  = (int)($_POST['id'] ?? 0);
                $token = \Glue\Portal\Account::invite($cuId);
                if ($token !== '') {
                    \Glue\Portal\Account::sendInvite($cuId, $token);
                    Activities::add('contact', $cuId, 'system', 'Portal access link sent to customer', $uid);
                    $_SESSION['dash_flash'] = [$t('cu_portal_link') . ' ' . \Glue\Portal\Account::magicLink($token), 'ok'];
                } else {
                    $_SESSION['dash_flash'] = [$t('not_allowed'), 'err'];
                }
                header('Location: ?tab=customers&id=' . $cuId);
                exit;
            }

            case 'customer_area_link':
            case 'customer_area_unlink': {
                $cuId = (int)($_POST['cid'] ?? 0);
                \Glue\Crm\Customers::linkArea(
                    (int)($_POST['area_id'] ?? 0),
                    $do === 'customer_area_link' ? $cuId : null
                );
                $_SESSION['dash_flash'] = [$t('saved'), 'ok'];
                header('Location: ?tab=customers&id=' . $cuId);
                exit;
            }

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
                $senderRole = ($isAgent || $isTech) ? 'agent' : 'admin';
                $att = null;
                $attErr = null;
                $signDocId = null;
                if (!empty($_POST['sign_request'])) {
                    // The attached PDF becomes a signature request: filed with the
                    // in-house signing flow (which also messages the customer a
                    // link), and the chat message references the document instead
                    // of carrying its own copy of the file.
                    $tkRow = Tickets::find((int)$_POST['id']);
                    $tkBody = trim((string)($_POST['body'] ?? ''));
                    $res = $tkRow ? SignDocs::create([
                        'title'      => strtok($tkBody, "\n") ?: (string)($_FILES['attachment']['name'] ?? ''),
                        'contact_id' => (int)$tkRow['contact_id'],
                    ], $_FILES['attachment'] ?? null, $uid) : ['ok' => false, 'error' => 'no_contact'];
                    if ($res['ok']) {
                        SignDocs::send((int)$res['id'], $uid);
                        $signDocId = (int)$res['id'];
                    } else {
                        $attErr = 'sign_' . (string)$res['error'];
                    }
                } else {
                    $att = Tickets::storeUpload($_FILES['attachment'] ?? null, $attErr);
                }
                $ok = $attErr === null && Tickets::reply((int)$_POST['id'], $senderRole, $uid, $senderName,
                    (string)($_POST['body'] ?? ''), $att, $signDocId);
                $flash = $ok ? $t('saved')
                    : ($attErr === 'too_big' ? 'File too large (max 10 MB).'
                        : ($attErr === 'bad_type' ? 'File type not allowed.'
                            : (str_starts_with((string)$attErr, 'sign_')
                                ? ($t('dc_err_' . substr($attErr, 5)) !== 'dc_err_' . substr($attErr, 5)
                                    ? $t('dc_err_' . substr($attErr, 5)) : $t('dc_err_save_failed'))
                                : ($attErr === 'save_failed' ? 'Could not save the file.' : $t('test_fail')))));
                $flashType = $ok ? 'ok' : 'err';
                // Redirect (PRG) so a browser refresh can't re-send the reply.
                $tab = ($_POST['back'] ?? '') === 'messages' ? 'messages' : 'tickets';
                $_SESSION['dash_flash'] = [$flash, $flashType];
                header('Location: ?tab=' . $tab . '&tk=' . (int)$_POST['id']);
                exit;
            case 'ticket_open_staff':
                $contactId = (int)($_POST['contact_id'] ?? 0);
                // Agents may only message their own customers. Asked directly:
                // testing membership of the picker's first 500 rows refused most
                // of the registry, whoever was asking.
                $mayMessage = Tickets::mayMessage($isAgent ? $scopeId : null, $contactId);
                $att = Tickets::storeUpload($_FILES['attachment'] ?? null, $attErr);
                $tab = ($_POST['back'] ?? '') === 'messages' ? 'messages' : 'tickets';
                if ($attErr !== null) {
                    $_SESSION['dash_flash'] = [$attErr === 'too_big' ? 'File too large (max 10 MB).'
                        : ($attErr === 'bad_type' ? 'File type not allowed.' : 'Could not save the file.'), 'err'];
                    header('Location: ?tab=' . $tab);
                    exit;
                }
                if ($mayMessage
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

            // ---------- team chat + AI assistant ----------
            // Every case needs a real account — the master login has no user row
            // to be a member with — and membership of the chat, checked here:
            // the prefix→owner guard above knows nothing about team_ ids.
            case 'team_new': {
                if (!$uid) { $_SESSION['dash_flash'] = [$t('tm_need_account'), 'err']; header('Location: ?tab=team'); exit; }
                $tmUsers = array_values(array_filter(array_map('intval', (array)($_POST['users'] ?? [])), fn($i) => $i > 0 && $i !== (int)$uid));
                $tmName  = trim((string)($_POST['name'] ?? ''));
                $tmId = 0;
                if ($tmName !== '' || count($tmUsers) > 1) {
                    $tmId = TeamChat::group($tmName !== '' ? $tmName : $t('tm_group_default'), $tmUsers, (int)$uid);
                } elseif (count($tmUsers) === 1) {
                    $tmId = TeamChat::direct((int)$uid, $tmUsers[0], (int)$uid);
                }
                if ($tmId <= 0) {
                    $_SESSION['dash_flash'] = [$t('tm_pick_people'), 'err'];
                    header('Location: ?tab=team');
                    exit;
                }
                header('Location: ?tab=team&c=' . $tmId);
                exit;
            }
            case 'team_send': {
                $tmId = (int)($_POST['id'] ?? 0);
                if (!$uid || !TeamChat::isMember($tmId, (int)$uid)) {
                    $_SESSION['dash_flash'] = [$t('not_allowed'), 'err'];
                    header('Location: ?tab=team');
                    exit;
                }
                $tmWho = trim((string)($_SESSION['glue_user']['full_name'] ?? '')) ?: (string)($_SESSION['glue_user']['username'] ?? 'Staff');
                $tmAtt = TeamChat::storeUpload($_FILES['attachment'] ?? null, $tmErr);
                if ($tmErr !== null) {
                    $_SESSION['dash_flash'] = [$t('tm_upload_' . $tmErr), 'err'];
                } elseif (TeamChat::post($tmId, (int)$uid, $tmWho, (string)($_POST['body'] ?? ''), $tmAtt) <= 0) {
                    $_SESSION['dash_flash'] = [$t('tm_empty'), 'warn'];
                }
                header('Location: ?tab=team&c=' . $tmId);
                exit;
            }
            case 'team_add': {
                $tmId = (int)($_POST['id'] ?? 0);
                if ($uid && TeamChat::isMember($tmId, (int)$uid)) {
                    $tmWho = trim((string)($_SESSION['glue_user']['full_name'] ?? '')) ?: (string)($_SESSION['glue_user']['username'] ?? 'Staff');
                    TeamChat::addMembers($tmId, (array)($_POST['users'] ?? []), (int)$uid, $tmWho);
                }
                header('Location: ?tab=team&c=' . $tmId);
                exit;
            }
            case 'team_leave': {
                $tmId = (int)($_POST['id'] ?? 0);
                if ($uid) {
                    $tmWho = trim((string)($_SESSION['glue_user']['full_name'] ?? '')) ?: (string)($_SESSION['glue_user']['username'] ?? 'Staff');
                    TeamChat::leave($tmId, (int)$uid, $tmWho);
                }
                header('Location: ?tab=team');
                exit;
            }
            case 'team_rename': {
                $tmId = (int)($_POST['id'] ?? 0);
                if ($uid && TeamChat::isMember($tmId, (int)$uid)) {
                    TeamChat::rename($tmId, (string)($_POST['name'] ?? ''));
                }
                header('Location: ?tab=team&c=' . $tmId);
                exit;
            }
            case 'ai_ask': {
                // The assistant answers inside the request (10–60 s with tools);
                // the page asked over fetch and shows a typing bubble meanwhile.
                $tmId = (int)($_POST['id'] ?? 0);
                $tmChat = $uid ? TeamChat::find($tmId) : null;
                if (!$tmChat || $tmChat['kind'] !== 'ai' || !TeamChat::isMember($tmId, (int)$uid)) {
                    if ($ajax) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
                    header('Location: ?tab=team');
                    exit;
                }
                set_time_limit(180);
                $aiCtx = [
                    'uid'   => (int)$uid,
                    'role'  => $isAgent ? 'agent' : ($isTech ? 'tech' : 'admin'),
                    'name'  => trim((string)($_SESSION['glue_user']['full_name'] ?? '')) ?: (string)($_SESSION['glue_user']['username'] ?? 'Staff'),
                    'lang'  => $lang,
                    'today' => \Glue\Reminder\Templates::when(time(), $lang),
                ];
                $aiRes = Assistant::ask($tmId, $aiCtx, (string)($_POST['body'] ?? ''));
                if ($ajax) {
                    header('Content-Type: application/json');
                    $aiOut = [];
                    foreach ($aiRes['ids'] as $mid) {
                        $m = TeamChat::message((int)$mid);
                        if ($m) { $aiOut[] = ['id' => (int)$m['id'], 'html' => team_bubble($m, $t, $h, (int)$uid, !$isAgent && !$isTech)]; }
                    }
                    echo json_encode(['ok' => $aiRes['ok'], 'error' => $aiRes['error'], 'messages' => $aiOut]);
                    exit;
                }
                header('Location: ?tab=team&c=' . $tmId);
                exit;
            }
            case 'ai_confirm':
            case 'ai_cancel': {
                $tmMid = (int)($_POST['mid'] ?? 0);
                $tmAid = (string)($_POST['aid'] ?? '');
                $aiCtx = [
                    'uid'   => (int)$uid,
                    'role'  => $isAgent ? 'agent' : ($isTech ? 'tech' : 'admin'),
                    'name'  => trim((string)($_SESSION['glue_user']['full_name'] ?? '')) ?: (string)($_SESSION['glue_user']['username'] ?? 'Staff'),
                    'lang'  => $lang,
                    'today' => \Glue\Reminder\Templates::when(time(), $lang),
                ];
                $aiRes = $uid ? ($do === 'ai_confirm' ? Assistant::confirm($tmMid, $tmAid, $aiCtx) : Assistant::cancel($tmMid, $tmAid, $aiCtx))
                              : ['ok' => false, 'text' => $t('tm_need_account'), 'note_id' => 0];
                $tmMsg = TeamChat::message($tmMid);
                $tmCard = '';
                foreach ((array)($tmMsg['meta']['actions'] ?? []) as $a) {
                    if (($a['id'] ?? '') === $tmAid) { $tmCard = team_action_card($a, $tmMid, $t, $h); }
                }
                $tmNote = $aiRes['note_id'] ? TeamChat::message((int)$aiRes['note_id']) : null;
                if ($ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => $aiRes['ok'], 'text' => $aiRes['text'], 'card' => $tmCard,
                        'note' => $tmNote ? ['id' => (int)$tmNote['id'], 'html' => team_bubble($tmNote, $t, $h, (int)$uid)] : null]);
                    exit;
                }
                header('Location: ?tab=team&c=' . (int)($tmMsg['chat_id'] ?? 0));
                exit;
            }
            case 'test_ai': { // admin only: not in the agent/tech whitelists
                $aiT = Assistant::test();
                $flash = ($aiT['ok'] ? $t('test_ok') : $t('test_fail')) . ': ' . $aiT['text'];
                $flashType = $aiT['ok'] ? 'ok' : 'err';
                $tab = 'settings';
                break;
            }

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
                    'gateway'            => (string)($_POST['gateway'] ?? ''),
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
            case 'test_smallpay': {
                // checkSellConfigs validates merchant + service + gateway without
                // creating a position, so this is safe to press against the live
                // account. It is the only SmallPay call that is.
                //
                // Once per gateway: each is a different service, and a service
                // SmallPay has deactivated fails here and nowhere else until a
                // customer is already looking at a broken checkout.
                $ok = [];
                foreach (\Glue\Pay\SmallPay::gateways() as $gw) {
                    (new \Glue\Pay\SmallPay(null, $gw))->checkSellConfig();
                    $ok[] = $t('pay_gw_' . $gw);
                }
                $flash = $t('test_ok') . ' · ' . $t('pay_test_ok') . ': ' . implode(', ', $ok);
                $tab = 'settings';
                break;
            }

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
                    // Kept on agents only: techs and admins have Installations anyway.
                    'can_install' => ($_POST['role'] ?? 'agent') === 'agent' && ($_POST['can_install'] ?? '') === '1' ? 1 : 0,
                    // The verification group is open to every role.
                    'in_review_group' => ($_POST['in_review_group'] ?? '') === '1' ? 1 : 0,
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
                    'can_install' => ($_POST['role'] ?? 'agent') === 'agent' && ($_POST['can_install'] ?? '') === '1' ? 1 : 0,
                    // The verification group is open to every role.
                    'in_review_group' => ($_POST['in_review_group'] ?? '') === '1' ? 1 : 0,
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
                // Commission statements name their agent: deleting the account would
                // leave them pointing at nobody. Disabling it keeps the history.
                $cmHas = (int)$pdo->query("SELECT COUNT(*) FROM commission_statements WHERE payee_type = 'agent' AND payee_id = " . (int)$_POST['id'])->fetchColumn()
                       + \Glue\Commission\Plans::countFor('agent', (int)$_POST['id']);
                if ($cmHas > 0) {
                    $flash = sprintf($t('cm_user_has_statements'), $cmHas);
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
            // ---- commission statements (Provvigioni) — src/Commission/Statements.php ----
            // Every one lands back on a GET: a reload must not re-send a file.
            case 'cm_create': // office: a statement for a partner or an agent, with the calculation
                $cmUser = $uid ? (int)$uid : null;
                [$cmType, $cmPid] = array_pad(explode(':', (string)($_POST['payee'] ?? ''), 2), 2, '0');
                $cmRes = \Glue\Commission\Statements::create([
                    'payee_type' => $cmType, 'payee_id' => (int)$cmPid,
                    'title' => (string)($_POST['title'] ?? ''), 'period' => (string)($_POST['period'] ?? ''),
                    'notes' => (string)($_POST['notes'] ?? ''), 'amount' => (string)($_POST['amount'] ?? ''),
                    'accrual_ids' => (array)($_POST['accrual_ids'] ?? []),
                    'no_invoice' => !empty($_POST['no_invoice']),
                ], $_FILES['calc'] ?? null, $cmUser);
                $_SESSION['dash_flash'] = $cmRes['ok'] ? [$t('cm_created_flash'), 'ok'] : [$t('cm_err_' . $cmRes['error']), 'err'];
                header('Location: ?tab=commissions' . ($cmRes['ok']
                    ? '&st=' . $cmRes['id'] . '#cm-' . $cmRes['id']
                    : '&new=' . rawurlencode((string)($_POST['payee'] ?? '')) . '#cm-new'));
                exit;
            case 'cm_invoice':        // agent: the invoice for one of their own statements
            case 'cm_invoice_office': // office: an invoice that arrived by email
                $cmUser = $uid ? (int)$uid : null;
                $cmId = (int)($_POST['id'] ?? 0);
                $cmRes = $do === 'cm_invoice'
                    ? \Glue\Commission\Statements::submitInvoice($cmId, $_POST, $_FILES['invoice'] ?? null, 'payee', 'agent', (int)$uid, $cmUser)
                    : \Glue\Commission\Statements::submitInvoice($cmId, $_POST, $_FILES['invoice'] ?? null, 'office', null, null, $cmUser);
                $_SESSION['dash_flash'] = $cmRes['ok']
                    ? [$t($do === 'cm_invoice' ? 'cm_invoice_flash' : 'cm_invoice_office_flash'), 'ok']
                    : [$t('cm_err_' . $cmRes['error']), 'err'];
                header('Location: ?tab=' . ($do === 'cm_invoice' ? 'my_commissions' : 'commissions') . '&st=' . $cmId . '#cm-' . $cmId);
                exit;
            case 'cm_pay':    // office: paid
            case 'cm_reject': // office: the invoice goes back to the payee, with the reason
            case 'cm_cancel': // office: withdrawn before payment
                $cmUser = $uid ? (int)$uid : null;
                $cmId = (int)($_POST['id'] ?? 0);
                $cmRes = match ($do) {
                    'cm_pay'    => \Glue\Commission\Statements::markPaid($cmId, $_POST, $cmUser),
                    'cm_reject' => \Glue\Commission\Statements::reject($cmId, (string)($_POST['reason'] ?? ''), $cmUser),
                    default     => \Glue\Commission\Statements::cancel($cmId, (string)($_POST['note'] ?? ''), $cmUser),
                };
                $_SESSION['dash_flash'] = $cmRes['ok']
                    ? [$t(['cm_pay' => 'cm_paid_flash', 'cm_reject' => 'cm_rejected_flash', 'cm_cancel' => 'cm_cancelled_flash'][$do]), 'ok']
                    : [$t('cm_err_' . $cmRes['error']), 'err'];
                header('Location: ?tab=commissions&st=' . $cmId . '#cm-' . $cmId);
                exit;

            // ---- commissions paid in instalments, as the customer pays (office only) ----
            case 'cp_create': {
                $cpRes = \Glue\Commission\Plans::create($_POST, $_FILES['calc'] ?? null, $uid ? (int)$uid : null);
                if (!$cpRes['ok']) {
                    $_SESSION['dash_flash'] = [$t('cp_err_' . $cpRes['error']), 'err'];
                    $cpSrc = (int)($_POST['source_statement_id'] ?? 0);
                    header('Location: ?tab=commissions' . ($cpSrc > 0 ? '&split=' . $cpSrc : '&cp_new=1') . '#cp-new');
                    exit;
                }
                $_SESSION['dash_flash'] = [(int)($cpRes['earned'] ?? 0) > 0
                    ? sprintf($t('cp_created_earned_flash'), (int)$cpRes['earned'])
                    : $t('cp_created_flash'), 'ok'];
                header('Location: ?tab=commissions&cp_open=' . $cpRes['id'] . '#cp-' . $cpRes['id']);
                exit;
            }
            case 'cp_earn':
            case 'cp_undo': {
                $cpRate = $pdo->prepare('SELECT plan_id FROM commission_plan_rates WHERE id = ?');
                $cpRate->execute([(int)($_POST['id'] ?? 0)]);
                $cpPlanId = (int)$cpRate->fetchColumn();
                $cpRes = $do === 'cp_earn'
                    ? \Glue\Commission\Plans::earn((int)($_POST['id'] ?? 0), (string)($_POST['paid_on'] ?? ''), 'office', $uid ? (int)$uid : null)
                    : \Glue\Commission\Plans::undo((int)($_POST['id'] ?? 0), $uid ? (int)$uid : null);
                $_SESSION['dash_flash'] = $cpRes['ok']
                    ? [$t($do === 'cp_earn' ? 'cp_earned_flash' : 'cp_undone_flash'), 'ok']
                    : [$t('cp_err_' . $cpRes['error']), 'err'];
                header('Location: ?tab=commissions&cp_open=' . $cpPlanId . '#cp-' . $cpPlanId);
                exit;
            }
            case 'cp_cancel': {
                $cpId  = (int)($_POST['id'] ?? 0);
                $cpRes = \Glue\Commission\Plans::cancel($cpId, (string)($_POST['note'] ?? ''), $uid ? (int)$uid : null);
                $_SESSION['dash_flash'] = $cpRes['ok'] ? [$t('cp_cancelled_flash'), 'ok'] : [$t('cp_err_' . $cpRes['error']), 'err'];
                header('Location: ?tab=commissions&cp=all&cp_open=' . $cpId . '#cp-' . $cpId);
                exit;
            }
            // ---- lead documents and financing applications (src/Finance/Docs.php) ----
            case 'lead_docs_upload': { // the general documents of a customer, from the lead
                $fLead = (int)($_POST['id'] ?? 0);
                $fQ = $pdo->prepare('SELECT l.id, l.contact_id, l.customer_name, ct.company
                                       FROM leads l LEFT JOIN contacts ct ON ct.id = l.contact_id WHERE l.id = ?');
                $fQ->execute([$fLead]);
                $fL = $fQ->fetch();
                if (!$fL || !$finMay($fLead)) {
                    $_SESSION['dash_flash'] = [$t('not_allowed'), 'err'];
                    header('Location: ?tab=leads');
                    exit;
                }
                $fRes = \Glue\Finance\Docs::storeMany(['lead_id' => $fLead, 'contact_id' => $fL['contact_id'] ?? null,
                    'source' => $isAgent ? 'agent' : 'office', 'user_id' => $uid ?: null, 'user_name' => $finName], $_FILES['files'] ?? null);
                if ($fRes['count'] > 0) {
                    \Glue\Finance\Docs::alertOfficeFiles($fLead, $fRes['count'], (string)$fL['customer_name'], $fL['company'] ?? null);
                }
                $_SESSION['dash_flash'] = $fRes['count'] > 0
                    ? [sprintf($t('fin_ok_uploaded'), $fRes['count']), 'ok']
                    : [$t('fin_err_upload') . ($fRes['errors'] ? ' ' . implode('; ', array_slice($fRes['errors'], 0, 2)) : ''), 'err'];
                header('Location: ?tab=leads&lead=' . $fLead . '#lead-' . $fLead);
                exit;
            }
            case 'fin_open': { // open the financing application on a lead
                $fLead = (int)($_POST['lead_id'] ?? 0);
                if (!$finMay($fLead)) {
                    $_SESSION['dash_flash'] = [$t('not_allowed'), 'err'];
                    header('Location: ?tab=leads');
                    exit;
                }
                $fRes = \Glue\Finance\Docs::openApp($fLead, $uid ?: null);
                $_SESSION['dash_flash'] = !empty($fRes['ok']) ? [$t('fin_ok_opened'), 'ok'] : [$t('not_allowed'), 'err'];
                header('Location: ?tab=leads&lead=' . $fLead . '#lead-' . $fLead);
                exit;
            }
            case 'fin_save':
            case 'fin_upload':
            case 'fin_submit':
            case 'fin_review':
            case 'fin_reopen':
            case 'fin_close':
            case 'fin_share':
            case 'fin_share_revoke':
            case 'fin_file_del': {
                $fFile = $do === 'fin_file_del' ? \Glue\Finance\Docs::file((int)($_POST['file_id'] ?? 0)) : null;
                $fAppId = (int)($_POST['app_id'] ?? 0);
                if ($do === 'fin_share_revoke' && $fAppId === 0) { $fAppId = 0; }
                $fApp = $fAppId > 0 ? \Glue\Finance\Docs::app($fAppId) : ($fFile && $fFile['app_id'] ? \Glue\Finance\Docs::app((int)$fFile['app_id']) : null);
                $fLead = (int)($fApp['lead_id'] ?? ($fFile['lead_id'] ?? 0));
                $back = $fApp && !$isAgent ? '?tab=finance&app=' . (int)$fApp['id'] . '#app-' . (int)$fApp['id']
                                           : '?tab=leads&lead=' . $fLead . '#lead-' . $fLead;
                if ($fLead === 0 || !$finMay($fLead) || ($isAgent && in_array($do, ['fin_review', 'fin_reopen', 'fin_close', 'fin_share', 'fin_share_revoke'], true))) {
                    $_SESSION['dash_flash'] = [$t('not_allowed'), 'err'];
                    header('Location: ' . $back);
                    exit;
                }
                $msg = [$t('saved'), 'ok'];
                switch ($do) {
                    case 'fin_save':
                        \Glue\Finance\Docs::updateApp((int)$fApp['id'], $_POST, $uid ?: null);
                        break;
                    case 'fin_upload':
                        $fRes = \Glue\Finance\Docs::storeMany([
                            'lead_id' => $fLead, 'contact_id' => $fApp['contact_id'] ?? null, 'app_id' => (int)$fApp['id'],
                            'slot_code' => preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['slot'] ?? 'altro'))) ?: 'altro',
                            'lender_id' => (int)($_POST['lender_id'] ?? 0) ?: null,
                            'source' => $isAgent ? 'agent' : 'office', 'user_id' => $uid ?: null, 'user_name' => $finName,
                        ], $_FILES['files'] ?? null);
                        $msg = $fRes['count'] > 0
                            ? [sprintf($t('fin_ok_uploaded'), $fRes['count']), 'ok']
                            : [$t('fin_err_upload') . ($fRes['errors'] ? ' ' . implode('; ', array_slice($fRes['errors'], 0, 2)) : ''), 'err'];
                        break;
                    case 'fin_file_del':
                        if ($fFile) { \Glue\Finance\Docs::delete((int)$fFile['id'], $uid ?: null); }
                        $msg = [$t('fin_ok_deleted'), 'ok'];
                        break;
                    case 'fin_submit':
                        $fR = \Glue\Finance\Docs::submit((int)$fApp['id'], $uid ?: null);
                        $msg = !empty($fR['ok'])
                            ? [$fR['missing'] ? sprintf($t('fin_ok_submitted_partial'), implode(', ', array_slice($fR['missing'], 0, 4))) : $t('fin_ok_submitted'), 'ok']
                            : [$t('not_allowed'), 'err'];
                        break;
                    case 'fin_review':
                        \Glue\Finance\Docs::review((int)$fApp['id'], $uid ?: null);
                        $msg = [$t('fin_ok_reviewed'), 'ok'];
                        break;
                    case 'fin_reopen':
                        \Glue\Finance\Docs::reopen((int)$fApp['id'], $uid ?: null);
                        $msg = [$t('fin_ok_reopened'), 'ok'];
                        break;
                    case 'fin_close':
                        \Glue\Finance\Docs::close((int)$fApp['id'], $uid ?: null);
                        $msg = [$t('fin_ok_closed'), 'ok'];
                        break;
                    case 'fin_share':
                        $fR = \Glue\Finance\Docs::share((int)$fApp['id'], (array)($_POST['lender_ids'] ?? []), $uid ?: null);
                        $msg = !empty($fR['ok']) ? [sprintf($t('fin_ok_shared'), count($fR['shares'])), 'ok'] : [$t('fin_err_no_lender'), 'err'];
                        break;
                    case 'fin_share_revoke':
                        \Glue\Finance\Docs::revokeShare((int)($_POST['share_id'] ?? 0), $uid ?: null);
                        $msg = [$t('fin_ok_revoked'), 'ok'];
                        break;
                }
                $_SESSION['dash_flash'] = $msg;
                header('Location: ' . $back);
                exit;
            }
            case 'fin_share_send': { // office only: send a lender's link by email or WhatsApp
                $fApp = \Glue\Finance\Docs::app((int)($_POST['app_id'] ?? 0));
                if ($isAgent || $isTech || !$fApp) {
                    $_SESSION['dash_flash'] = [$t('not_allowed'), 'err'];
                    header('Location: ?tab=finance');
                    exit;
                }
                $fR = \Glue\Finance\Docs::sendShare((int)($_POST['share_id'] ?? 0), (string)($_POST['to'] ?? ''), $uid ?: null);
                $_SESSION['dash_flash'] = !empty($fR['ok'])
                    ? [sprintf($t('fin_ok_sent'), $fR['to']), 'ok']
                    : [$t('fin_err_' . ($fR['error'] ?? 'recipient')), 'err'];
                header('Location: ?tab=finance&app=' . (int)$fApp['id'] . '#app-' . (int)$fApp['id']);
                exit;
            }
            case 'fin_lender_save': { // admin only: the institutions and their privacy forms
                $fR = \Glue\Finance\Docs::saveLender($_POST, $_FILES['privacy'] ?? null, $uid ?: null);
                $_SESSION['dash_flash'] = !empty($fR['ok']) ? [$t('saved'), 'ok'] : [$t('fin_err_' . ($fR['error'] ?? 'name')), 'err'];
                header('Location: ?tab=finance');
                exit;
            }
            case 'fin_lender_del': {
                $fR = \Glue\Finance\Docs::deleteLender((int)($_POST['id'] ?? 0), $uid ?: null);
                $_SESSION['dash_flash'] = !empty($fR['ok']) ? [$t('deleted'), 'ok'] : [$t('fin_err_lender_in_use'), 'err'];
                header('Location: ?tab=finance');
                exit;
            }
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

$views = ['overview', 'calendar', 'leads', 'deals', 'quotes', 'customers', 'articles', 'pricelists', 'contacts', 'appointments', 'tasks', 'tickets', 'team', 'documents',
          'installations', 'inspections', 'support',
          'invoices', 'payments', 'campaigns', 'messages', 'outbound', 'reminders', 'templates', 'events', 'agents',
          'partners', 'commissions', 'my_commissions', 'finance', 'devices', 'network_areas', 'settings', 'instructions'];
$view = in_array($tab, $views, true) ? $tab : 'overview';
// Agents can't reach admin views, even by typing the URL.
if ($isAgent && !in_array($view, $agentViews, true)) {
    $view = 'overview';
    $tab  = 'overview';
}
// Technical-area users can only reach their own views. Default them to Devices.
// The office reaches everything except the Administrator's four tabs, even by
// typing the URL.
if ($isOffice && in_array($view, SYS_VIEWS, true)) {
    $view = 'overview';
    $tab  = 'overview';
}
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
    // A tech in the ticket inbox sees only the threads they claimed — the
    // same hard scope agents get, keyed on the same assigned_agent_id.
    if ($view === 'tickets') {
        $scopeId = (int)$uid;
    }
}

// The number on the Provvigioni entry: invoices waiting to be paid (the office),
// statements waiting for this agent's invoice (an agent).
$cmBadge = 0;
try {
    $cmBadge = $isTech ? 0 : ($isAgent
        ? ($uid ? \Glue\Commission\Statements::countToInvoice('agent', (int)$uid) : 0)
        : \Glue\Commission\Statements::countInvoiced());
} catch (Throwable $e) {
    $cmBadge = 0; // table not migrated yet
}
// Applications the office still has to check — the number on Finanziamenti.
$finBadge = 0;
try {
    $finBadge = ($isAgent || $isTech) ? 0 : \Glue\Finance\Docs::countInReview();
} catch (Throwable $e) {
    $finBadge = 0; // before migration 058
}render_head($t, $h, $lang, $tab, $flash, $flashType, $isAgent, $isTech, $agentInstalls, $uid ? TeamChat::unreadTotal((int)$uid) : 0, $cmBadge, $finBadge, $isOffice);

require dirname(__DIR__) . '/views/' . $view . '.php';

render_foot();


// ============================ shared chrome ============================

function render_login(callable $t, callable $h, string $lang, ?string $err, bool $forgot = false, ?string $notice = null): void { ?>
<!DOCTYPE html><html lang="<?= $h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($t('login_title')) ?></title><?php css(); ?></head>
<body class="center">
  <?php if ($notice !== null): ?>
    <div class="login">
      <div class="logo">C</div>
      <h1><?= $h($t('login_title')) ?></h1>
      <p class="muted"><?= $h($notice) ?></p>
      <p><a href="?"><?= $h($t('fp_back')) ?></a></p>
    </div>
  <?php elseif ($forgot): ?>
    <?php // One field: whatever they remember. The link only ever goes to the
          // address already on the account, so naming one buys nothing. ?>
    <form class="login" method="post">
      <input type="hidden" name="forgot" value="1">
      <div class="logo">C</div>
      <h1><?= $h($t('fp_title')) ?></h1>
      <p class="muted"><?= $h($t('fp_sub')) ?></p>
      <input type="text" name="identifier" placeholder="<?= $h($t('fp_ph')) ?>" autofocus>
      <button type="submit"><?= $h($t('fp_btn')) ?></button>
      <p style="margin-top:14px"><a href="?"><?= $h($t('fp_back')) ?></a></p>
    </form>
  <?php else: ?>
  <form class="login" method="post">
    <div class="logo">C</div>
    <h1><?= $h($t('login_title')) ?></h1>
    <p class="muted"><?= $h($t('login_sub')) ?></p>
    <?php if ($err): ?><p class="err"><?= $h($err) ?></p><?php endif; ?>
    <input type="text" name="username" placeholder="<?= $h($t('login_user_ph')) ?>" autofocus>
    <input type="password" name="password" placeholder="<?= $h($t('login_ph')) ?>">
    <button type="submit"><?= $h($t('login_btn')) ?></button>
    <p style="margin-top:14px"><a href="?forgot=1"><?= $h($t('fp_link')) ?></a></p>
  </form>
  <?php endif; ?>
</body></html>
<?php }

function render_head(callable $t, callable $h, string $lang, string $tab, ?string $flash, string $flashType, bool $isAgent = false, bool $isTech = false, bool $agentInstalls = false, int $teamUnread = 0, int $cmBadge = 0, int $finBadge = 0, bool $isOffice = false): void {
    $brand = (string)\Glue\Config::get('app.company_name', '') ?: $t('app_title');
    $nav = [
        'overview' => 'nav_overview', 'leads' => 'nav_leads', 'deals' => 'nav_deals',
        'quotes' => 'nav_quotes',
        'customers' => 'nav_customers', 'articles' => 'nav_articles', 'pricelists' => 'nav_pricelists',
        'contacts' => 'nav_contacts', 'appointments' => 'nav_appointments', 'calendar' => 'nav_calendar', 'tasks' => 'nav_tasks',
        'tickets' => 'nav_tickets', 'team' => 'nav_team', 'documents' => 'nav_documents', 'installations' => 'nav_installations', 'inspections' => 'nav_inspections',
        'support' => 'nav_support',
        'invoices' => 'nav_invoices',
        'payments' => 'nav_payments',
        'campaigns' => 'nav_campaigns', 'messages' => 'nav_messages', 'outbound' => 'nav_outbound',
        'reminders' => 'nav_reminders', 'templates' => 'nav_templates',
        'devices' => 'nav_devices', 'network_areas' => 'nav_network_areas',
        'events' => 'nav_events', 'agents' => 'nav_agents', 'partners' => 'nav_partners', 'commissions' => 'nav_commissions', 'my_commissions' => 'nav_my_commissions', 'finance' => 'nav_finance', 'instructions' => 'nav_instr', 'settings' => 'nav_settings',
    ];
    if ($isAgent) { // agents only see their own work — plus Installations when they also install
        $nav = array_intersect_key($nav, array_flip(array_merge(
            ['overview', 'leads', 'deals', 'quotes', 'articles', 'pricelists', 'appointments', 'calendar', 'tasks', 'messages', 'team', 'documents', 'my_commissions', 'instructions'],
            $agentInstalls ? ['installations'] : []
        )));
    } elseif ($isTech) { // technical-area users: devices, install reports, support queue, own tickets
        $nav = array_intersect_key($nav, array_flip(['devices', 'installations', 'inspections', 'support', 'calendar', 'tickets', 'team']));
    } else { // the office files statements; it is not paid by them
        unset($nav['my_commissions']);
        // Amministrazione: the same menu as the Administrator, without the four
        // tabs that configure the system rather than run it. Hidden as well as
        // blocked — a tab that answers "non consentito" is worse than no tab.
        if ($isOffice) {
            foreach (SYS_VIEWS as $sv) {
                unset($nav[$sv]);
            }
        }
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
        <a class="<?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= $h($key) ?>"><?= svg($key) ?><span><?= $h($t($label)) ?></span><?php if ($key === 'team' && $teamUnread > 0): ?><span class="nav-n"><?= $teamUnread ?></span><?php endif; ?><?php if (($key === 'commissions' || $key === 'my_commissions') && $cmBadge > 0): ?><span class="nav-n"><?= $cmBadge ?></span><?php endif; ?><?php if ($key === 'finance' && $finBadge > 0): ?><span class="nav-n"><?= $finBadge ?></span><?php endif; ?></a>
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
  if(b){setTimeout(function(){b.disabled=true;b.style.opacity='0.6';b.dataset.guarded='1';},0);}
});
// Safari and Chrome keep a page in the back-forward cache with that button still
// disabled, so going back to it showed a button that would not press. Give it back.
window.addEventListener('pageshow',function(e){
  if(!e.persisted) return;
  document.querySelectorAll('[data-guarded]').forEach(function(b){b.disabled=false;b.style.opacity='';delete b.dataset.guarded;});
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
/**
 * "10 set, 18:41" / "Sep 10, 18:41" — the reader's language, read from the
 * page's $lang (set once at the top of dashboard.php; the ~40 call sites all
 * predate the Italian month names and none of them has a $t to hand over).
 */
function short_time(?string $dt): string {
    $ts = $dt ? strtotime($dt) : false;
    if (!$ts) {
        return (string)$dt;
    }
    if (($GLOBALS['lang'] ?? 'en') === 'it') {
        static $mesi = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
        return date('j', $ts) . ' ' . $mesi[(int)date('n', $ts) - 1] . ', ' . date('H:i', $ts);
    }
    return date('M j, H:i', $ts);
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
/**
 * The calendar's view state, carried back through a POST redirect.
 *
 * Booking a job from the week of 12 October, on the "Napoli" filter, has to
 * land back on the week of 12 October with that filter still on — a redirect
 * to a bare ?tab=calendar drops the planner back on today and they lose their
 * place mid-round. The form posts what it was showing in `back`; only the keys
 * the calendar understands are echoed, so nothing arbitrary reaches the URL.
 */
function calBackQs(): string {
    parse_str((string)($_POST['back'] ?? ''), $b);
    $out = [];
    foreach (['m', 'd', 'v', 'k', 'u', 'z', 'ty'] as $key) {
        if (isset($b[$key]) && $b[$key] !== '' && is_scalar($b[$key])) {
            $out[$key] = mb_substr((string)$b[$key], 0, 40);
        }
    }
    return $out ? '&' . http_build_query($out) : '';
}
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
                         array $partners = [], ?int $selPartner = null, array $keep = []): void {
    $sty = 'padding:7px 10px;border-radius:8px;border:1px solid var(--line);background:var(--surface2);color:var(--txt);font-size:13px';
    echo '<form method="get" class="agent-filter" style="margin:0 0 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
    echo '<input type="hidden" name="tab" value="' . $h($tab) . '">';
    // Whatever else is on the page that picking a seller must not throw away —
    // the leads search box.
    foreach ($keep as $k => $v) {
        if ((string)$v !== '') {
            echo '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
        }
    }
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
/**
 * A lead's or contact's source ("website", "manual", "cashmatic") for display.
 * Generic sources have a name in the lang files (src_website = "Sito web");
 * a partner brand or a source someone typed has none and is shown with a
 * capital letter. Values stay codes everywhere else — filters, exports and
 * the datalist a source is typed into all use the code itself.
 */
function source_label(callable $t, ?string $code): string {
    $code = (string)$code;
    $l = code_label($t, 'src_', $code);
    return $l === $code ? ucfirst($code) : $l;
}
/**
 * A lead/deal title in the reader's language. Titles are stored as the intake
 * wrote them ("Request: Mario Rossi", "New request"); the shape is recognised
 * and rebuilt, anything else (a title someone typed) is shown as it is.
 */
function record_title(callable $t, ?string $title): string {
    $title = (string)$title;
    if (preg_match('/^Request: (.+)$/u', $title, $m)) {
        return sprintf($t('title_request'), $m[1]);
    }
    if ($title === 'New request') {
        return $t('title_new_request');
    }
    return $title;
}
/**
 * A timeline line in the reader's language. The lines the system writes are
 * stored in English, the language of the code; the Cronologia shows them in
 * the language of the page. Each known line is recognised by its shape and
 * rebuilt from lang keys, the variable parts (names, numbers, dates, the note
 * text) carried over; a note, or a line this table does not know, is shown
 * as stored. Stage names inside "Stage: A → B" go through stage_label(), so a
 * seeded stage reads "Preventivo inviato" and a renamed one keeps its name.
 */
function activity_text(callable $t, string $body): string {
    static $seedCode = ['New' => 'NEW', 'In Contact' => 'CONTACTED', 'Qualified' => 'QUALIFIED',
        'Converted' => 'CONVERTED', 'Junk' => 'JUNK', 'Quote sent' => 'QUOTE', 'Negotiation' => 'NEGOTIATION',
        'Signature' => 'SIGNATURE', 'Won' => 'WON', 'Lost' => 'LOST'];
    $stage = fn(string $name): string => stage_label($t, $seedCode[trim($name)] ?? '', trim($name));
    $was   = fn(?string $w): string => ($w ?? '') !== '' ? ' ' . sprintf($t('act_was'), $w) : '';
    $body  = trim($body);
    $rules = [
        ['/^Deal created$/', fn($m) => $t('act_deal_created')],
        ['/^Lead details edited$/', fn($m) => $t('act_lead_edited')],
        ['/^Portal access (?:link )?sent to customer$/', fn($m) => $t('act_portal_sent')],
        ['/^Appointment requested$/', fn($m) => $t('act_appt_requested')],
        ['/^Contract signed by customer \(OTP\)$/', fn($m) => $t('act_contract_signed')],
        ['/^Stage: (.+?) → (.+)$/u', fn($m) => sprintf($t('act_stage'), $stage($m[1]), $stage($m[2]))],
        ['/^Assigned to (.+)$/u', fn($m) => sprintf($t('act_assigned'), $m[1])],
        ['/^Converted to deal #(\d+)$/', fn($m) => sprintf($t('act_converted'), $m[1])],
        ['/^Lead created from (.+?)(?: \((\S+)\))?( \(internal — no welcome message sent\))?$/u',
            fn($m) => sprintf($t('act_lead_created'), $m[1]) . (($m[2] ?? '') !== '' ? " ({$m[2]})" : '')
                . (($m[3] ?? '') !== '' ? ' ' . $t('act_lead_internal') : '')],
        ['/^New request from (.+?) grouped onto this lead(?::\n([\s\S]*))?$/u',
            fn($m) => sprintf($t('act_grouped'), $m[1]) . (($m[2] ?? '') !== '' ? ":\n" . $m[2] : '')],
        ['/^Quote requested from the back office \(#(\d+)\):\n([\s\S]*)$/u',
            fn($m) => sprintf($t('act_quote_requested'), $m[1]) . ":\n" . $m[2]],
        ['/^Quote uploaded by the back office for request #(\d+)$/', fn($m) => sprintf($t('act_quote_uploaded'), $m[1])],
        ['/^Quote #(\d+) sent to the customer for review and signature$/', fn($m) => sprintf($t('act_quote_sent'), $m[1])],
        ['/^Quote request #(\d+) cancelled$/', fn($m) => sprintf($t('act_quote_cancelled'), $m[1])],
        ['/^Lead attributed to partner (.+?)(?: \(was (.+)\))?$/u', fn($m) => sprintf($t('act_partner_set'), $m[1]) . $was($m[2] ?? null)],
        ['/^Partner attribution removed(?: \(was (.+)\))?$/u', fn($m) => $t('act_partner_removed') . $was($m[1] ?? null)],
        ['/^Blocked duplicate entry of VAT (\S+)(?: via partner (.+?))? \(locked until (.+)\)$/u',
            fn($m) => ($m[2] ?? '') !== ''
                ? sprintf($t('act_vat_blocked_partner'), $m[1], $m[2], $m[3])
                : sprintf($t('act_vat_blocked'), $m[1], $m[3])],
        ['/^Payment contract opened: (.+) — (.+)$/u', fn($m) => sprintf($t('act_pay_opened'), $m[1], $m[2])],
        ['/^Payment contract active — first payment collected$/u', fn($m) => $t('act_pay_active')],
        ['/^SmallPay refused the first payment$/', fn($m) => $t('act_pay_refused')],
        ['/^A payment came back unpaid$/', fn($m) => $t('act_pay_unpaid_1')],
        ['/^(\d+) payments came back unpaid$/', fn($m) => sprintf($t('act_pay_unpaid_n'), (int)$m[1])],
        ['/^Payment contract cancelled$/', fn($m) => $t('act_pay_cancelled')],
        ['/^A new first-payment link was sent by SmallPay$/', fn($m) => $t('act_pay_newlink')],
        ['/^(\d+) unpaid payment\(s\) retried$/', fn($m) => sprintf($t('act_pay_retried'), (int)$m[1])],
        ['/^(\d+) payment\(s\) marked settled in cash$/', fn($m) => sprintf($t('act_pay_cash'), (int)$m[1])],
        ['/^Payment link sent to the customer$/', fn($m) => $t('act_pay_link')],
        ['/^Confirmed for (.+)$/u', fn($m) => sprintf($t('act_appt_confirmed'), $m[1])],
        ['/^Status: (\S+)$/', fn($m) => sprintf($t('act_status'), code_label($t, 'stt_', $m[1]))],
    ];
    foreach ($rules as [$re, $fn]) {
        if (preg_match($re, $body, $m)) {
            return $fn($m);
        }
    }
    return $body;
}

