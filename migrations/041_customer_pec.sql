-- 041_customer_pec: the certified-mail address (PEC).
--
-- The gestionale's newer export layout (Sep 2026) carries "Email Pec" as its
-- own column — 1,116 customers have one. In Italian B2B the PEC is the legally
-- binding address, distinct from the ordinary email the CRM chases and chats
-- through, so it gets its own column rather than a line in the notes.
-- Guarded via information_schema; re-run safe.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'pec');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN pec VARCHAR(190) NULL AFTER email', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
