-- 056_commission_statements: commission statements for partners and agents.
--
-- "A way to upload commission calculations for partners and agents so that
--  invoices can be generated for payment. The secretary selects the partner's
--  area and uploads a calculation of €900; the partner receives it, submits the
--  invoice, and the secretary then processes the payment. The partner area
--  should also display a history of commissions and invoices, paid and unpaid."
--
-- One row per statement (conteggio provvigioni), for a partner or an agent:
--   sent      filed by the office, waiting for the payee's invoice
--   invoiced  the payee (or the office for them) attached the invoice
--   paid      the office paid it
--   cancelled withdrawn before payment
-- An invoice sent back by the office returns the row to 'sent' with the reason.
--
-- The automatic accruals the CRM already makes for a partner's won deals
-- (partner_accruals) can be covered by a statement: statement_id ties them to
-- it, and they are paid with it or released when it is cancelled.
--
-- CREATE TABLE IF NOT EXISTS / columns guarded via information_schema: re-run
-- safe on MySQL and MariaDB alike.

CREATE TABLE IF NOT EXISTS commission_statements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payee_type ENUM('partner','agent') NOT NULL,
    payee_id INT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    period VARCHAR(60) NULL,
    notes TEXT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    calc_path VARCHAR(255) NULL,
    calc_name VARCHAR(190) NULL,
    status ENUM('sent','invoiced','paid','cancelled') NOT NULL DEFAULT 'sent',
    invoice_path VARCHAR(255) NULL,
    invoice_name VARCHAR(190) NULL,
    invoice_number VARCHAR(60) NULL,
    invoice_date DATE NULL,
    invoice_amount DECIMAL(12,2) NULL,
    invoice_note VARCHAR(500) NULL,
    invoice_by VARCHAR(8) NULL,
    invoiced_at DATETIME NULL,
    rejected_note VARCHAR(500) NULL,
    rejected_at DATETIME NULL,
    paid_on DATE NULL,
    paid_amount DECIMAL(12,2) NULL,
    payment_ref VARCHAR(190) NULL,
    paid_by INT UNSIGNED NULL,
    paid_at DATETIME NULL,
    cancel_note VARCHAR(255) NULL,
    cancelled_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cm_payee (payee_type, payee_id, status),
    KEY idx_cm_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_accruals' AND COLUMN_NAME = 'statement_id');
SET @sql := IF(@add = 0, 'ALTER TABLE partner_accruals ADD COLUMN statement_id BIGINT UNSIGNED NULL AFTER status', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_accruals' AND INDEX_NAME = 'idx_accrual_statement');
SET @sql := IF(@idx = 0, 'ALTER TABLE partner_accruals ADD KEY idx_accrual_statement (statement_id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
