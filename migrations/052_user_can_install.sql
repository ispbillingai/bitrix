-- 052_user_can_install: agents who also do installations.
--
-- "There are agents who also perform installation — a check mark in the agent
--  accounts that, if checked, makes the installation submenu appear in their
--  menu." One flag per account, ticked on the Agents page. It only means
-- something on an agent: technicians and admins already have the Installations
-- tab. An agent with it gets that tab and the installation-report flow, scoped
-- the way a technician's is — only the reports they opened themselves.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'can_install');
SET @sql := IF(@add = 0, 'ALTER TABLE users ADD COLUMN can_install TINYINT(1) NOT NULL DEFAULT 0 AFTER role', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
