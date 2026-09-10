-- 049_article_stock: stock the CRM can change, and a restock alert.
--
-- Migration 048 gave a row ONE owner. That is too coarse now: the client wants
-- to correct stock on a product whose description and prices should keep coming
-- from the gestionale. Detaching the whole row to fix a quantity would freeze
-- its prices too, which nobody asked for.
--
-- So ownership splits in two:
--
--   origin       who owns the descriptive and price columns
--   stock_owner  who owns stock / stock_available / stock_ordered / stock_initial
--
-- Touch the stock in the CRM and stock_owner becomes 'crm' for that row alone:
-- the import keeps refreshing its prices and description, and stops overwriting
-- its quantities. Handing stock back to the gestionale is one button, and the
-- next import re-converges it.
--
-- reorder_threshold is CRM-owned on EVERY row, gestionale ones included — it is
-- our reordering policy, not the export's data, and it is deliberately absent
-- from the importer's column list so no snapshot can clear it.
--
-- low_alert_at stops the restock alert repeating: stock is refreshed every
-- fifteen minutes, so "below threshold" is true continuously, and alerting on
-- the state rather than on CROSSING it would mean 96 messages a day per article.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'stock_owner');
SET @sql := IF(@add = 0,
  'ALTER TABLE articles ADD COLUMN stock_owner ENUM(''gestionale'',''crm'') NOT NULL DEFAULT ''gestionale'' AFTER origin',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'reorder_threshold');
SET @sql := IF(@add = 0,
  'ALTER TABLE articles ADD COLUMN reorder_threshold DECIMAL(12,2) NULL AFTER stock_available', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'low_alert_at');
SET @sql := IF(@add = 0,
  'ALTER TABLE articles ADD COLUMN low_alert_at DATETIME NULL AFTER reorder_threshold', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND INDEX_NAME = 'idx_article_reorder');
SET @sql := IF(@idx = 0, 'ALTER TABLE articles ADD KEY idx_article_reorder (reorder_threshold, archived)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Every stock change the CRM makes, and why. A bare quantity nobody can explain
-- is worth very little in a warehouse: this is what makes "it says 6, it should
-- be 4" answerable. The gestionale's own movements are NOT in here — those live
-- in the management software; this ledger only records what the CRM did.
CREATE TABLE IF NOT EXISTS article_movements (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id  BIGINT UNSIGNED NOT NULL,
    delta       DECIMAL(12,2) NOT NULL,          -- signed: +load, -unload
    stock_after DECIMAL(12,2) NOT NULL,          -- what it read once applied
    reason      VARCHAR(24) NOT NULL,            -- load | unload | correction | initial
    note        VARCHAR(255) NULL,
    user_id     INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mov_article (article_id, id),
    KEY idx_mov_when (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
