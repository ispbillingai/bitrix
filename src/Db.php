<?php
declare(strict_types=1);

namespace Glue;

use PDO;

/**
 * Shared PDO. Same singleton pattern as the parking app's Db::pdo(), but it
 * reads its connection settings from Config so endpoints just call Db::pdo().
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = Config::section('db');
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $cfg['host'] ?? '127.0.0.1',
                $cfg['name'] ?? 'bitrix_glue',
                $cfg['charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }
}
