-- 066_maintenance: the maintenance contract on a customer's record, and the
-- quarterly chase for the ones who have none.
--
-- The CRM already knew two half-answers to "is this customer covered?" — a live
-- SmallPay subscription (payment_contracts, kind='subscription') and the
-- gestionale's own expiry date (contacts.contract_expiry) — and neither was
-- shown on the record. Crm\Maintenance resolves them into one answer; what is
-- added here is the third case the client named: a contract the CRM cannot
-- derive, typed in by the office, and the A CHIAMATA fallback when there is
-- nothing at all.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

-- The office's own override. Set, it wins over anything derived: a paper
-- contract, a customer billed outside SmallPay, a special arrangement.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'maint_type');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN maint_type VARCHAR(32) NULL AFTER contract_expiry', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Cents, like payment_contracts.amount_cents — never a float for money.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'maint_fee_cents');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN maint_fee_cents INT UNSIGNED NULL AFTER maint_type', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- How often that fee is charged: monthly | quarterly | yearly.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'maint_period');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN maint_period VARCHAR(16) NULL AFTER maint_fee_cents', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'maint_note');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN maint_note VARCHAR(190) NULL AFTER maint_period', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- The quarterly chase.
--
-- One row per chase actually sent. The next one is due three months after the
-- LATER of (last service visit, last chase) — which is what makes "every three
-- months following the last visit" repeat on its own until a visit happens, and
-- stop the moment one does, without a scheduled job holding any state.
CREATE TABLE IF NOT EXISTS maintenance_followups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    -- The visit this chase was counted from, for the audit trail: "we wrote to
    -- them because the last time anyone went was this date".
    last_service_at DATETIME NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    channel VARCHAR(16) NOT NULL DEFAULT 'both',
    -- What it produced: a message to the customer, a task for the office, or both.
    task_id BIGINT UNSIGNED NULL,
    messaged TINYINT(1) NOT NULL DEFAULT 0,
    -- Set when a visit is later booked, so the office can see which chases worked.
    booked_appointment_id BIGINT UNSIGNED NULL,
    KEY idx_contact (contact_id, sent_at),
    KEY idx_recent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
