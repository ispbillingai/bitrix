-- 020_reminder_repeat: make reminders repeatable.
--
-- Some reminders should keep firing on a cadence until the record is acted on —
-- e.g. an uncontacted lead is nudged twice a day (to the agent, and after a day
-- also to the customer) until it leaves the NEW stage. When repeat_every_hours
-- is > 0, the Scheduler re-enqueues the next occurrence after each send, and the
-- existing skip_if_stage_changed_from guard stops the chain once the lead moves.
-- Guarded so re-runs are no-ops on MySQL (no MariaDB-only IF NOT EXISTS).

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reminders'
                   AND COLUMN_NAME = 'repeat_every_hours');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE reminders ADD COLUMN repeat_every_hours INT UNSIGNED NULL AFTER skip_if_stage_changed_from',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
