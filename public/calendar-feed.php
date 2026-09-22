<?php
declare(strict_types=1);

/**
 * The subscribable calendar feed — crm.../calendar.ics?k=<token>  (calendar-feed.php)
 *
 * This is what "put it in my Google Calendar" turns into without a Google
 * account, an OAuth consent screen or a stored password anywhere: a staff
 * member adds this URL once as a subscribed calendar and their own phone —
 * Google Calendar, Apple Calendar, Outlook, all of them speak iCalendar —
 * shows their round beside the rest of their life, refreshed on its own.
 *
 * No session: a calendar app cannot log in. The token in the URL IS the
 * credential, so it is long, random, unique per user and regenerable from the
 * Calendar tab if it ever leaks. Read-only by construction: this file has no
 * write path at all, and a subscribed calendar cannot post back.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Crm\Calendar;

Bootstrap::init();

$token = (string)($_GET['k'] ?? '');
$user  = Calendar::userForToken($token);

if (!$user) {
    // 404, not 403: an unauthenticated guess learns nothing about whether a
    // token exists, and calendar apps stop retrying a 404 politely.
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

$who  = trim((string)($user['full_name'] ?? '')) ?: (string)$user['username'];
$name = (string)Config::get('app.company_name', 'CRM') . ' — ' . $who;
$body = Calendar::ics((int)$user['id'], $name);

// text/calendar is what makes a phone offer to subscribe rather than download.
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="calendar.ics"');
// The feed reflects a booking made a moment ago; a cached copy would show a
// technician an address that has since moved.
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
echo $body;
