-- 036_contact_name_split: Nome and Cognome become two stored columns.
--
-- The forms have asked for the two parts since 2ca39cf/4c93b35, but they joined
-- them straight back into the single contacts.name, so a surname was only ever
-- typed, never kept apart. The Contatti list could not show a Cognome column and
-- nothing could sort or search on a surname.
--
-- name STAYS, and stays authoritative for everything that reads it -- leads,
-- documents, signatures, message templates all render contacts.name today and
-- keep doing so. first_name/last_name are the parts it was built from; the app
-- writes all three together on every insert.
--
-- The backfill is a GUESS on rows entered before this: cut at the first space,
-- because an Italian surname carries a particle ("De Luca", "Lo Russo") far more
-- often than a first name is compound -- the same rule Contacts::splitName()
-- already uses to prefill the lead edit form. It is wrong for the rows that hold
-- a business rather than a person ("Bar Parisi napoli"), which is why the list
-- got an edit form in the same commit. name itself is NOT rewritten, so a wrong
-- guess costs a correction, never data.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'first_name');
SET @sql := IF(@add = 0,
    'ALTER TABLE contacts ADD COLUMN first_name VARCHAR(190) NULL AFTER name', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'contacts' AND COLUMN_NAME = 'last_name');
SET @sql := IF(@add = 0,
    'ALTER TABLE contacts ADD COLUMN last_name VARCHAR(190) NULL AFTER first_name', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill only rows never split before, so a re-run cannot undo a correction.
UPDATE contacts
   SET first_name = TRIM(SUBSTRING_INDEX(TRIM(name), ' ', 1)),
       last_name  = TRIM(SUBSTRING(TRIM(name), LOCATE(' ', TRIM(name)) + 1))
 WHERE first_name IS NULL AND last_name IS NULL
   AND LOCATE(' ', TRIM(name)) > 0;

-- A one-word name is a first name with no surname, not a surname of "".
UPDATE contacts
   SET first_name = TRIM(name), last_name = ''
 WHERE first_name IS NULL AND last_name IS NULL;

-- Surname-first is the useful order for a people list.
SET @add := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'contacts' AND INDEX_NAME = 'idx_last_name');
SET @sql := IF(@add = 0,
    'ALTER TABLE contacts ADD KEY idx_last_name (last_name)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
