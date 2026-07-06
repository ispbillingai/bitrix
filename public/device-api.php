<?php
declare(strict_types=1);

/**
 * Device monitoring API for the dashboard (admin + technical-area users).
 *
 *   GET  ?what=status         -> current status of all devices
 *   GET  ?what=log&limit=100  -> disconnection log (device_events, newest first)
 *   POST {action:"poll"}      -> poll all routers now, then return status
 *   POST {action:"test", area_id:N} -> test-connect one router
 *   POST {action:"save_area", ...}  -> add/edit a router (admin only)
 *   POST {action:"delete_area", id:N} -> remove a router (admin only)
 *
 * Auth: reuses the dashboard session. Read + poll/test are open to admin and
 * tech roles; area create/edit/delete are admin-only.
 */
require __DIR__ . '/../src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Db;
use Glue\Devices\Monitor;

Bootstrap::init();

session_set_cookie_params(31536000, '/', '', false, true);
session_start();
header('Content-Type: application/json');

$user = $_SESSION['glue_user'] ?? null;
$role = (string)($user['role'] ?? '');
$isAuthed = isset($_SESSION['glue_auth']) && in_array($role, ['admin', 'tech'], true);
if (!$isAuthed) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
$isAdmin = $role === 'admin';
$pdo = Db::pdo();

/** Current device status rows. */
$statusRows = static function () use ($pdo): array {
    return $pdo->query(
        "SELECT d.name, d.ip, d.status, d.latency_ms, d.last_seen_at, d.last_checked_at, a.name AS area_name
           FROM devices d LEFT JOIN network_areas a ON a.id = d.area_id
          ORDER BY d.sort_order, d.id"
    )->fetchAll();
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $what = $_GET['what'] ?? 'status';
    if ($what === 'log') {
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        $rows = $pdo->query(
            "SELECT e.created_at, e.event_type, e.latency_ms, d.name, d.ip
               FROM device_events e JOIN devices d ON d.id = e.device_id
              ORDER BY e.id DESC LIMIT $limit"
        )->fetchAll();
        echo json_encode(['ok' => true, 'log' => $rows]);
        exit;
    }
    echo json_encode(['ok' => true, 'devices' => $statusRows(), 'timestamp' => time()]);
    exit;
}

// ---- POST actions ----
$input  = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    $input = $_POST ?: [];
}
$action = (string)($input['action'] ?? '');

if ($action === 'poll') {
    $res = Monitor::poll();
    echo json_encode(['ok' => true, 'poll' => $res, 'devices' => $statusRows()]);
    exit;
}

if ($action === 'test') {
    $areaId = (int)($input['area_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM network_areas WHERE id = ?");
    $stmt->execute([$areaId]);
    $a = $stmt->fetch();
    if (!$a) {
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    try {
        $api = Monitor::connect([
            'host' => $a['host'], 'port' => (int)$a['api_port'],
            'user' => $a['api_user'], 'pass' => $a['api_pass'],
        ]);
        [$up, $ms] = $api->ping('127.0.0.1', 1);
        $api->close();
        echo json_encode(['ok' => true, 'latency_ms' => $ms]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---- admin-only: manage routers ----
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if ($action === 'save_area') {
    $id    = (int)($input['id'] ?? 0);
    $name  = trim((string)($input['name'] ?? ''));
    $host  = trim((string)($input['host'] ?? ''));
    $port  = (int)($input['api_port'] ?? 8728);
    $userN = trim((string)($input['api_user'] ?? 'admin')) ?: 'admin';
    $pass  = (string)($input['api_pass'] ?? '');
    $count = max(1, (int)($input['ping_count'] ?? 2));
    $sort  = (int)($input['sort_order'] ?? 0);
    $active = !empty($input['active']) ? 1 : 0;

    if ($name === '' || $host === '') {
        echo json_encode(['ok' => false, 'error' => 'required']);
        exit;
    }
    if ($port < 1 || $port > 65535) {
        $port = 8728;
    }

    if ($id > 0) {
        if ($pass === '') { // keep stored password when left blank
            $pdo->prepare("UPDATE network_areas SET name=?, host=?, api_port=?, api_user=?, ping_count=?, active=?, sort_order=? WHERE id=?")
                ->execute([$name, $host, $port, $userN, $count, $active, $sort, $id]);
        } else {
            $pdo->prepare("UPDATE network_areas SET name=?, host=?, api_port=?, api_user=?, api_pass=?, ping_count=?, active=?, sort_order=? WHERE id=?")
                ->execute([$name, $host, $port, $userN, $pass, $count, $active, $sort, $id]);
        }
    } else {
        $pdo->prepare("INSERT INTO network_areas (name, host, api_port, api_user, api_pass, ping_count, active, sort_order) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$name, $host, $port, $userN, $pass, $count, $active, $sort]);
        $id = (int)$pdo->lastInsertId();
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

if ($action === 'delete_area') {
    $id = (int)($input['id'] ?? 0);
    $pdo->prepare("UPDATE devices SET area_id = NULL WHERE area_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM network_areas WHERE id = ?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_action']);
