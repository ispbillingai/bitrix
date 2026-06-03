<?php
declare(strict_types=1);

// Health check / landing. Confirms the app boots and the DB is reachable.
require __DIR__ . '/../src/Bootstrap.php';
\Glue\Bootstrap::init();

header('Content-Type: application/json');

try {
    \Glue\Db::pdo()->query('SELECT 1');
    echo json_encode(['ok' => true, 'service' => 'bitrix24-glue', 'db' => 'up']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_down']);
}
