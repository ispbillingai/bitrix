-- 048_article_ownership: the warehouse becomes editable, which first requires
-- deciding who owns a row.
--
-- Until now the answer was simple: the gestionale owned everything, the CRM
-- only read. The client now wants to add products, edit them and delete them
-- from the CRM — and that collides head-on with the import, which converges
-- EVERY column of every row from a full snapshot every fifteen minutes, and
-- whose --prune deletes anything the file no longer carries. Added naively,
-- a product typed in here would be reverted or deleted within the quarter
-- hour, silently. So:
--
--   origin = 'gestionale'  the export owns it. The import keeps converging it.
--   origin = 'crm'         the CRM owns it. The import never touches it and
--                          prune never deletes it.
--
-- Editing a gestionale article DETACHES it (origin becomes 'crm'), because an
-- edit that the next import quietly undoes is worse than no edit at all. The
-- UI says so before it happens.
--
-- archived: "delete" for a gestionale article. A hard delete would be undone
-- by the next import — the code is still in the file — so it is hidden
-- instead, and the import keeps its data fresh underneath without resurrecting
-- it on screen. CRM-owned articles delete for real.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'origin');
SET @sql := IF(@add = 0,
  'ALTER TABLE articles ADD COLUMN origin ENUM(''gestionale'',''crm'') NOT NULL DEFAULT ''gestionale'' AFTER code',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'archived');
SET @sql := IF(@add = 0,
  'ALTER TABLE articles ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER origin', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'updated_by');
SET @sql := IF(@add = 0,
  'ALTER TABLE articles ADD COLUMN updated_by INT UNSIGNED NULL AFTER last_movement', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'crm_edited_at');
SET @sql := IF(@add = 0,
  'ALTER TABLE articles ADD COLUMN crm_edited_at DATETIME NULL AFTER updated_by', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND INDEX_NAME = 'idx_article_origin');
SET @sql := IF(@idx = 0, 'ALTER TABLE articles ADD KEY idx_article_origin (origin, archived)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
