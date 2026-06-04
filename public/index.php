<?php
declare(strict_types=1);

// Browsers land on the dashboard; monitoring/curl gets the JSON health check.
require __DIR__ . '/../src/Bootstrap.php';
\Glue\Bootstrap::init();

$wantsHtml = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html');
if ($wantsHtml) {
    header('Location: dashboard.php');
    exit;
}

header('Content-Type: application/json');
try {
    \Glue\Db::pdo()->query('SELECT 1');
    echo json_encode(['ok' => true, 'service' => 'bitrix24-glue', 'db' => 'up']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_down']);
}
