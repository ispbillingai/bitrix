-- 053_contact_import_channels: remember what the gestionale import last wrote
-- into a customer card's phone, second phone and email.
--
-- "Some customers have phone numbers of other customers." The CLIENTI import
-- keyed cards on the gestionale code and never overwrote a filled phone or
-- email, to keep numbers staff corrected by hand. But the gestionale reuses
-- codes — 637 changed hands between the 3 and 5 Sep 2026 exports (9705 went
-- from LUCIANO PITTALUGA to SINERGIA MAXIMO SRL) — and changes numbers, so
-- 466 cards kept another customer's phone or email. With the value the import
-- last wrote on record, the next import can tell a gestionale value (it follows
-- the file) from one typed in the CRM (it stays). See CustomerImport::contactFields.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'gest_phone');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN gest_phone VARCHAR(32) NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'gest_phone2');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN gest_phone2 VARCHAR(32) NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'gest_email');
SET @sql := IF(@add = 0, 'ALTER TABLE contacts ADD COLUMN gest_email VARCHAR(190) NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
