-- 009_deal_sign_due: per-deal signature due date.
--
-- The signing cadence (Phase 4 "Quote sent") now anchors on an explicit due date
-- the agent sets when sending the quote: R1 fires N days after the quote is sent,
-- R2/R3 fire a number of days BEFORE this date (see Crm\Automation::signCadence).
-- Guarded so re-runs are no-ops on MySQL (no MariaDB-only IF NOT EXISTS).

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deals'
                   AND COLUMN_NAME = 'sign_due_date');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE deals ADD COLUMN sign_due_date DATE NULL AFTER expected_close_date',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
