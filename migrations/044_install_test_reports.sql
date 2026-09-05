-- 044_install_test_reports: test installations (noleggio di prova).
--
-- The client's words: "create a new technical report the same as the previous
-- one with the only difference that the installation is a test installation so
-- you need to add a test end date. 4 days before the end of the test, two
-- notices must arrive: one to the customer that the test is about to end and
-- one to the company."
--
-- Same install_reports row, two more columns. The two notices are ordinary
-- reminders rows (rule keys test_end_customer / test_end_company) enqueued at
-- send time with due_at = test_end_date - 4 days — the existing scheduler cron
-- delivers them, and deleting the report cancels them by dedupe key.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'install_reports' AND COLUMN_NAME = 'report_type');
SET @sql := IF(@add = 0, 'ALTER TABLE install_reports ADD COLUMN report_type VARCHAR(16) NOT NULL DEFAULT ''installation'' AFTER technician_name', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'install_reports' AND COLUMN_NAME = 'test_end_date');
SET @sql := IF(@add = 0, 'ALTER TABLE install_reports ADD COLUMN test_end_date DATE NULL AFTER report_type', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
