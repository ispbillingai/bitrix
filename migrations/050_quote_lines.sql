-- 050_quote_lines: a quote the office BUILDS, instead of one it uploads.
--
-- The client's flow, as described with the Preventivi tab on screen:
--
--   "the agent requests a quote for a client, specifying the various products
--    and services. The administrative staff member must generate a file listing
--    the items to be drawn from inventory, add any relevant services (such as a
--    support contract), and include any percentage discounts. Once the file is
--    complete, it is attached to the quote request. Crucially, inventory levels
--    must be updated whenever a client accepts a quote."
--
-- So a quote request grows LINES. A line is either an ARTICLE — it points at
-- the warehouse and will be drawn from stock — or a SERVICE, which is not stock
-- at all (a Helpdesk contract, an installation, a leasing fee). Code, description
-- and price are SNAPSHOTS: a later catalogue edit must not silently change a
-- quote the customer has already been sent, let alone one they have signed.
--
-- Discounts sit at two levels, because that is how the seller asked for them:
-- per line ("sconto 15% sul cassetto") and on the whole quote.
--
-- Stock is drawn when the customer SIGNS, not when the quote is built or sent:
-- a quote that is never accepted must not empty the shelves. stock_applied_at
-- is the once-only guard for that — a document signed twice, a webhook
-- replayed, a retry after a timeout: none of them may draw the stock again.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

CREATE TABLE IF NOT EXISTS quote_lines (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_request_id BIGINT UNSIGNED NOT NULL,
    sort             INT NOT NULL DEFAULT 0,
    kind             ENUM('article','service') NOT NULL DEFAULT 'article',
    article_id       BIGINT UNSIGNED NULL,               -- set for kind=article
    code             VARCHAR(64) NULL,                   -- snapshot
    description      VARCHAR(255) NOT NULL,              -- snapshot, editable
    qty              DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit_price       DECIMAL(18,4) NOT NULL DEFAULT 0,   -- net, before discounts
    discount_pct     DECIMAL(5,2) NOT NULL DEFAULT 0,
    vat_rate         DECIMAL(5,2) NOT NULL DEFAULT 22,
    KEY idx_ql_request (quote_request_id, sort),
    KEY idx_ql_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'number');
SET @sql := IF(@add = 0, 'ALTER TABLE quote_requests ADD COLUMN number VARCHAR(32) NULL AFTER id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'discount_pct');
SET @sql := IF(@add = 0, 'ALTER TABLE quote_requests ADD COLUMN discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER notes', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'valid_until');
SET @sql := IF(@add = 0, 'ALTER TABLE quote_requests ADD COLUMN valid_until DATE NULL AFTER discount_pct', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'customer_notes');
SET @sql := IF(@add = 0, 'ALTER TABLE quote_requests ADD COLUMN customer_notes TEXT NULL AFTER valid_until', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'generated_at');
SET @sql := IF(@add = 0, 'ALTER TABLE quote_requests ADD COLUMN generated_at DATETIME NULL AFTER sent_at', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'accepted_at');
SET @sql := IF(@add = 0, 'ALTER TABLE quote_requests ADD COLUMN accepted_at DATETIME NULL AFTER generated_at', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND COLUMN_NAME = 'stock_applied_at');
SET @sql := IF(@add = 0, 'ALTER TABLE quote_requests ADD COLUMN stock_applied_at DATETIME NULL AFTER accepted_at', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_requests' AND INDEX_NAME = 'idx_qr_number');
SET @sql := IF(@idx = 0, 'ALTER TABLE quote_requests ADD KEY idx_qr_number (number)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
