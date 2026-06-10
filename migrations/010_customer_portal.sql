-- 010_customer_portal: customer-facing accounts, portal access and OTP signing.
--
-- Phase 2: the agent creates a customer account (magic link + optional password);
-- the customer logs into public/portal.php to see their estimate (deal) and order
-- status, and signs the contract with a one-time code (OTP). MySQL has no ADD
-- COLUMN IF NOT EXISTS, so each column is guarded against information_schema.

-- ---- contacts become login-able customer accounts ----
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'password_hash');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN password_hash VARCHAR(255) NULL AFTER notes', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'portal_token');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN portal_token VARCHAR(64) NULL AFTER password_hash', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'portal_token_expires');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN portal_token_expires DATETIME NULL AFTER portal_token', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'portal_enabled');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN portal_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_token_expires', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'last_login_at');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN last_login_at DATETIME NULL AFTER portal_enabled', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND INDEX_NAME = 'idx_portal_token');
SET @sql := IF(@idx = 0, 'ALTER TABLE contacts ADD KEY idx_portal_token (portal_token)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- deals record the electronic signature ----
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deals' AND COLUMN_NAME = 'signed_at');
SET @sql := IF(@add = 0, 'ALTER TABLE deals ADD COLUMN signed_at DATETIME NULL AFTER sign_due_date', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deals' AND COLUMN_NAME = 'signed_name');
SET @sql := IF(@add = 0, 'ALTER TABLE deals ADD COLUMN signed_name VARCHAR(190) NULL AFTER signed_at', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deals' AND COLUMN_NAME = 'signed_ip');
SET @sql := IF(@add = 0, 'ALTER TABLE deals ADD COLUMN signed_ip VARCHAR(45) NULL AFTER signed_name', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- one-time codes for portal signing (and future verifications) ----
CREATE TABLE IF NOT EXISTS otp_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    deal_id BIGINT UNSIGNED NULL,
    purpose VARCHAR(24) NOT NULL DEFAULT 'sign',
    code VARCHAR(10) NOT NULL,
    channel VARCHAR(16) NOT NULL DEFAULT 'both',
    attempts INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lookup (contact_id, deal_id, purpose, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
