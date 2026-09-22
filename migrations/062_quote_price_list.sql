-- 062_quote_price_list: which price list a quote was priced from, and which
-- VERSION of it — so a hard copy says what it was printed from.
--
-- "The quote printing function should also include the date and price list
--  version to highlight changes on the hard copy."
--
-- Two printed quotes for the same customer are otherwise impossible to tell
-- apart: same number, same layout, different prices. So the PDF now carries
-- the printing date AND time, a revision number, and the price list with its
-- version. What is stored here is the SNAPSHOT taken when the PDF was made —
-- the list can be renamed or re-priced afterwards and the paper stays true.
--
--   price_lists.version      goes up by one whenever the prices in the list
--                            actually change: its own settings, what is in it,
--                            an own price, or a gestionale import moving the
--                            price of an article it contains.
--   price_lists.price_hash   fingerprint of the list's live prices; the version
--                            moves only when this differs, so re-saving a list
--                            without touching a price does not invent a version.
--   price_lists.version_at   when that last happened — the date on the paper.
--
--   quote_requests.price_list_*  the snapshot printed on the quote (id, name,
--                            version and its date). NULL price_list_id = priced
--                            from the gestionale catalogue, as before.
--   quote_requests.revision  1 for the first generated PDF, +1 for every
--                            regeneration after a change. Printed as "Rev. N".
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'price_lists' AND COLUMN_NAME = 'version');
SET @s := IF(@c = 0, 'ALTER TABLE price_lists ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER visible', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'price_lists' AND COLUMN_NAME = 'version_at');
SET @s := IF(@c = 0, 'ALTER TABLE price_lists ADD COLUMN version_at DATETIME NULL AFTER version', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'price_lists' AND COLUMN_NAME = 'price_hash');
SET @s := IF(@c = 0, 'ALTER TABLE price_lists ADD COLUMN price_hash CHAR(32) NULL AFTER version_at', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

UPDATE price_lists SET version_at = COALESCE(version_at, updated_at, created_at) WHERE version_at IS NULL;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'price_list_id');
SET @s := IF(@c = 0, 'ALTER TABLE quote_requests ADD COLUMN price_list_id INT UNSIGNED NULL AFTER discount_pct', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'price_list_name');
SET @s := IF(@c = 0, 'ALTER TABLE quote_requests ADD COLUMN price_list_name VARCHAR(120) NULL AFTER price_list_id', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'price_list_version');
SET @s := IF(@c = 0, 'ALTER TABLE quote_requests ADD COLUMN price_list_version INT UNSIGNED NULL AFTER price_list_name', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'price_list_version_at');
SET @s := IF(@c = 0, 'ALTER TABLE quote_requests ADD COLUMN price_list_version_at DATETIME NULL AFTER price_list_version', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'revision');
SET @s := IF(@c = 0, 'ALTER TABLE quote_requests ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER number', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

-- Quotes whose PDF was already generated count as their first revision.
UPDATE quote_requests SET revision = 1 WHERE revision = 0 AND document_id IS NOT NULL;
