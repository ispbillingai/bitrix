<?php
declare(strict_types=1);

/**
 * Applies pending /migrations/*.sql in ascending order, recording each in a
 * `migrations` table so re-runs skip what's already applied. Mirrors the order
 * app's migrate.php.
 *
 *   php migrate.php            apply pending
 *   php migrate.php --dry-run  list pending without applying
 *   https://host/migrate.php?key=SECRET   one-off web run (prefer CLI in prod)
 */
require __DIR__ . '/src/Bootstrap.php';

use Glue\Bootstrap;
use Glue\Config;
use Glue\Db;

Bootstrap::init();

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain');
    $key = $_GET['key'] ?? '';
    if (!hash_equals((string)Config::get('app.migrate_key', ''), (string)$key)) {
        http_response_code(401);
        echo "unauthorized\n";
        exit;
    }
}
$dryRun = $isCli && in_array('--dry-run', $argv ?? [], true);

$pdo = Db::pdo();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(190) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);

$out = [];
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }
    if ($dryRun) {
        $out[] = "PENDING $name";
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        $out[] = "SKIP (empty) $name";
        continue;
    }
    try {
        $pdo->exec($sql);
        $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute([$name]);
        $out[] = "APPLIED $name";
    } catch (Throwable $e) {
        $out[] = "FAILED $name: " . $e->getMessage();
        echo implode("\n", $out) . "\n";
        exit(1);
    }
}

echo ($out ? implode("\n", $out) : 'Nothing to apply — up to date.') . "\n";
