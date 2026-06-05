-- 006_users_agents: turn dashboard users into full agent/seller profiles.
--
-- Requirement #3 (agent assignment -> send the agent's profile to the customer)
-- needs a name, phone and email per seller. We also keep an optional Bitrix user
-- id for the optional sync mapping. MySQL has no ADD COLUMN IF NOT EXISTS, so each
-- column is guarded against information_schema (see migrations/README.md).

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'full_name');
SET @sql := IF(@add = 0, 'ALTER TABLE users ADD COLUMN full_name VARCHAR(190) NULL AFTER username', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email');
SET @sql := IF(@add = 0, 'ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL AFTER full_name', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone');
SET @sql := IF(@add = 0, 'ALTER TABLE users ADD COLUMN phone VARCHAR(32) NULL AFTER email', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'title');
SET @sql := IF(@add = 0, 'ALTER TABLE users ADD COLUMN title VARCHAR(120) NULL AFTER phone', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'photo_url');
SET @sql := IF(@add = 0, 'ALTER TABLE users ADD COLUMN photo_url VARCHAR(255) NULL AFTER title', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'lang');
SET @sql := IF(@add = 0, 'ALTER TABLE users ADD COLUMN lang CHAR(2) NOT NULL DEFAULT ''it'' AFTER photo_url', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'bitrix_user_id');
SET @sql := IF(@add = 0, 'ALTER TABLE users ADD COLUMN bitrix_user_id BIGINT UNSIGNED NULL AFTER lang', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
