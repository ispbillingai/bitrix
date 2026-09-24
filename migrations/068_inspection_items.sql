-- 068_inspection_items: the survey sheet becomes a checklist of what is on site.
--
-- The client's list: armadio rack, UPS, router, switch, access point — each a
-- tick ("is it there?") and a line of description. That replaces the free-text
-- system type, machine model and serial number, which described ONE machine and
-- a survey looks at an installation.
--
-- A child table rather than ten columns. Each item is the same two fields, the
-- list is named in PHP (Inspect\Inspections::ITEMS), and a sixth item is then a
-- one-line change instead of a migration. It also keeps the useful question
-- answerable — "which sites have no UPS?" is a WHERE, not a JSON search.
--
-- MySQL: no ADD/DROP COLUMN IF EXISTS — guarded via information_schema.

CREATE TABLE IF NOT EXISTS inspection_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id BIGINT UNSIGNED NOT NULL,
    -- RACK | UPS | ROUTER | SWITCH | AP — the code, not the label: the wording
    -- is translated in PHP and may change without touching stored rows.
    code VARCHAR(24) NOT NULL,
    present TINYINT(1) NOT NULL DEFAULT 0,
    note VARCHAR(190) NULL,
    -- One row per item per survey: saving the sheet twice must not double it.
    UNIQUE KEY idx_ii_one (inspection_id, code),
    KEY idx_ii_missing (code, present)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The three fields the checklist replaces. Dropped rather than left dead: the
-- feature is a day old and no survey on file has ever carried a value in any of
-- them (checked before writing this), so there is nothing to preserve and a
-- column nobody fills is a column somebody later mistakes for data.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'system_type');
SET @sql := IF(@c = 1, 'ALTER TABLE inspections DROP COLUMN system_type', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'machine_model');
SET @sql := IF(@c = 1, 'ALTER TABLE inspections DROP COLUMN machine_model', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'serial_number');
SET @sql := IF(@c = 1, 'ALTER TABLE inspections DROP COLUMN serial_number', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
