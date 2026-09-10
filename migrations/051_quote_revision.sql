-- 051_quote_revision: the seller can send a generated quote back for changes.
--
-- "Perhaps a 'view file and request modification' form could be inserted in
--  addition to sending it to the customer." Once the office has generated the
-- quote, the seller who asked for it gets three moves instead of one: look at
-- the file, send it to the customer, or send it BACK to the office saying what
-- should change. The office edits it in the builder and regenerates, which
-- puts it back to 'ready' and tells the seller again.
--
-- The current request lives on the row — what to change, when, and who asked —
-- because that is what the office needs in front of them; every round is also
-- written to the lead's timeline, so the history is not lost when the next one
-- overwrites it. While a change is pending the quote's status is 'revision' and
-- the file on it cannot be sent: it is the version somebody asked to have fixed.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'revision_note');
SET @sql := IF(@add = 0, 'ALTER TABLE quote_requests ADD COLUMN revision_note TEXT NULL AFTER customer_notes', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'revision_requested_at');
SET @sql := IF(@add = 0, 'ALTER TABLE quote_requests ADD COLUMN revision_requested_at DATETIME NULL AFTER revision_note', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'revision_by');
SET @sql := IF(@add = 0, 'ALTER TABLE quote_requests ADD COLUMN revision_by INT UNSIGNED NULL AFTER revision_requested_at', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
