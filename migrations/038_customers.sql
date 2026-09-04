-- 038_customers: the customer registry — the ~10,000 clienti the management
-- software (gestionale) exports, plus every deal the CRM wins from now on.
--
-- There is deliberately NO new customers table. Everything a customer page must
-- show already hangs off contacts.id: tickets (the internal chat), sign
-- documents, payment contracts, portal access, leads and deals — and the Sibill
-- mirror resolves to contact_id too. So a customer IS a contact, flagged
-- is_customer and carrying the registry fields the gestionale owns: its own
-- code, the VAT number, address, balance. A separate table would have meant
-- re-linking six features to a second identity for nothing.
--
-- Identity is two-layered on purpose:
--   customer_code  the gestionale's "Cod." — unique, present on every exported
--                  row, the upsert key for the FTP import (re-import = update).
--   vat_number     present on ~60% of rows, NOT unique (one company, several
--                  locations), the key that matches Sibill invoices and future
--                  SmallPay positions to a customer.
--
-- MySQL has no ADD COLUMN IF NOT EXISTS, so every column is guarded against
-- information_schema (same pattern as 010). Re-run safe.

-- ---- contacts carry the registry ----
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'customer_code');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN customer_code VARCHAR(32) NULL AFTER source', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'vat_number');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN vat_number VARCHAR(32) NULL AFTER customer_code', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'is_customer');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN is_customer TINYINT(1) NOT NULL DEFAULT 0 AFTER vat_number', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'customer_since');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN customer_since DATETIME NULL AFTER is_customer', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- A second number, because the gestionale exports up to three (Telefono, Altro
-- Telefono, Cellulare) and collapsing them to one loses the landline the shop
-- actually answers. phone stays the primary (mobile preferred — WhatsApp).
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'phone2');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN phone2 VARCHAR(32) NULL AFTER phone', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'address');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN address VARCHAR(190) NULL AFTER customer_since', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'city');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN city VARCHAR(120) NULL AFTER address', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'province');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN province VARCHAR(8) NULL AFTER city', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'zip');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN zip VARCHAR(12) NULL AFTER province', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The gestionale's running balance (Saldo). Refreshed on every import; it is
-- their number, shown for orientation — Sibill remains the truth on invoices.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'balance');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN balance DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER zip', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'contract_expiry');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN contract_expiry DATE NULL AFTER balance', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The agent name as the gestionale spells it. Free text, NOT users.id — those
-- names ("DI MASSA VINCENZO") predate the CRM and map to no login.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'gestionale_agent');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN gestionale_agent VARCHAR(120) NULL AFTER contract_expiry', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND INDEX_NAME = 'uniq_customer_code');
SET @sql := IF(@idx = 0, 'ALTER TABLE contacts ADD UNIQUE KEY uniq_customer_code (customer_code)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND INDEX_NAME = 'idx_contact_vat');
SET @sql := IF(@idx = 0, 'ALTER TABLE contacts ADD KEY idx_contact_vat (vat_number)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND INDEX_NAME = 'idx_is_customer');
SET @sql := IF(@idx = 0, 'ALTER TABLE contacts ADD KEY idx_is_customer (is_customer, name)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- a router (network area) can belong to a customer ----
-- "The routers we will add": each customer site gets a MikroTik reachable over
-- WireGuard; the area row is that router. Linking it here is what puts the
-- device list and up/down state on the customer page.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'network_areas' AND COLUMN_NAME = 'contact_id');
SET @sql := IF(@add = 0, 'ALTER TABLE network_areas ADD COLUMN contact_id BIGINT UNSIGNED NULL AFTER name', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'network_areas' AND INDEX_NAME = 'idx_area_contact');
SET @sql := IF(@idx = 0, 'ALTER TABLE network_areas ADD KEY idx_area_contact (contact_id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- import ledger ----
-- One row per gestionale export file ever ingested, keyed by content hash: the
-- FTP watcher can rescan the drop directory forever and a file already taken is
-- simply skipped. Also the audit answer to "when did these numbers last move".
CREATE TABLE IF NOT EXISTS customer_imports (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename    VARCHAR(190) NOT NULL,
    sha256      CHAR(64) NOT NULL,
    rows_total  INT UNSIGNED NOT NULL DEFAULT 0,
    created_n   INT UNSIGNED NOT NULL DEFAULT 0,
    updated_n   INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_n   INT UNSIGNED NOT NULL DEFAULT 0,
    note        VARCHAR(255) NULL,
    imported_by INT UNSIGNED NULL,               -- users.id; NULL = CLI/cron
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_file_hash (sha256),
    KEY idx_imported (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
