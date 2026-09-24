-- 071_inspection_archive: put a finished survey away.
--
-- There is no delete for a survey — one the customer has signed is evidence —
-- so the working list only ever grew. Archiving takes it out of the way and
-- into its own tab, and is reversible: nothing is destroyed, the row simply
-- carries the date it was put away and by whom.
--
-- Deliberately NOT wired into the offer queue. An archived survey whose
-- customer has asked for a quote keeps that request in Richieste offerta: a
-- live ask from a customer must not disappear because somebody tidied a list.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'archived_at');
SET @sql := IF(@c = 0, 'ALTER TABLE inspections ADD COLUMN archived_at DATETIME NULL AFTER offer_handled_by', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'archived_by');
SET @sql := IF(@c = 0, 'ALTER TABLE inspections ADD COLUMN archived_by INT UNSIGNED NULL AFTER archived_at', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Both lists read "archived or not, newest first" on every open.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND INDEX_NAME = 'idx_archived');
SET @sql := IF(@c = 0, 'ALTER TABLE inspections ADD KEY idx_archived (archived_at, id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
