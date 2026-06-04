-- 002_entity_lang: per-customer language so messages go out in EN or IT.
--
-- MySQL has no `ADD COLUMN IF NOT EXISTS` (that's MariaDB-only), so each column
-- is guarded against information_schema and added only when missing. This makes
-- the migration idempotent and safe whether the DB was built from schema.sql
-- (columns already present -> no-op) or grown via migrations (columns added).
-- `DO 0` is the no-op branch — it returns no result set, keeping PDO::exec happy.

-- tracked_entities.lang
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tracked_entities' AND COLUMN_NAME = 'lang');
SET @sql := IF(@add = 0,
    'ALTER TABLE tracked_entities ADD COLUMN lang CHAR(2) NOT NULL DEFAULT ''it'' AFTER customer_name',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- reminders.lang
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'lang');
SET @sql := IF(@add = 0,
    'ALTER TABLE reminders ADD COLUMN lang CHAR(2) NULL AFTER payload',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- campaigns.lang
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'lang');
SET @sql := IF(@add = 0,
    'ALTER TABLE campaigns ADD COLUMN lang CHAR(2) NOT NULL DEFAULT ''it'' AFTER channel',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
