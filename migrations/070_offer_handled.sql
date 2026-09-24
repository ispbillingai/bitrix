-- 070_offer_handled: a queue you can empty.
--
-- The offer requests now have their own tab. A list that only ever grows is not
-- a queue — the verification group gets a WhatsApp per request and needs to see
-- which ones are still outstanding — so a request can be marked as dealt with,
-- and who did it is recorded.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'offer_handled_at');
SET @sql := IF(@c = 0, 'ALTER TABLE inspections ADD COLUMN offer_handled_at DATETIME NULL AFTER offer_note', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'offer_handled_by');
SET @sql := IF(@c = 0, 'ALTER TABLE inspections ADD COLUMN offer_handled_by INT UNSIGNED NULL AFTER offer_handled_at', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The queue reads "requested, not yet handled, oldest first" on every open of
-- the tab and for the menu badge.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND INDEX_NAME = 'idx_offer_queue');
SET @sql := IF(@c = 0, 'ALTER TABLE inspections ADD KEY idx_offer_queue (offer_handled_at, offer_requested_at)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
