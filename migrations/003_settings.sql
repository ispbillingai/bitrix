-- 003_settings: key/value store for settings editable from the dashboard.
-- Overlays the file config at runtime (see src/Settings.php + Config::applyOverlay),
-- so operators can configure Bitrix, TextMeBot, mail, stages and cadences from the
-- web UI without SFTP-editing config.php. Only DB credentials stay in config.php.
-- CREATE TABLE IF NOT EXISTS is portable across MySQL and MariaDB.

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(120) NOT NULL PRIMARY KEY,
    `value` TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
