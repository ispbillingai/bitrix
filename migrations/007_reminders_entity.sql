-- 007_reminders_entity: repoint the reminder queue at LOCAL records.
--
-- The queue used to key reminders by Bitrix entity id (`bitrix_id`). Now that the
-- CRM owns leads/deals/appointments locally, the column becomes a generic local
-- `entity_id`. Rename only if it hasn't been renamed yet (idempotent). The CHANGE
-- keeps the existing idx_entity index, which auto-updates to the new column name.

SET @has_new := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'entity_id');
SET @has_old := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'bitrix_id');
SET @sql := IF(@has_new = 0 AND @has_old = 1,
    'ALTER TABLE reminders CHANGE bitrix_id entity_id BIGINT UNSIGNED NULL',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Maps a local record to its counterpart in Bitrix24, used only by the optional
-- sync module (Glue\Sync\BitrixSync). Absent/empty when sync is disabled.
CREATE TABLE IF NOT EXISTS sync_map (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    local_type ENUM('lead','deal','contact') NOT NULL,
    local_id BIGINT UNSIGNED NOT NULL,
    bitrix_id BIGINT UNSIGNED NULL,
    last_pushed_at DATETIME NULL,
    last_pulled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_local (local_type, local_id),
    KEY idx_bitrix (local_type, bitrix_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
