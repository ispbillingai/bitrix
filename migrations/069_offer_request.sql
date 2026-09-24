-- 069_offer_request: the customer can ask for a quote off the back of the
-- survey opinion, and the verification group is told.
--
-- The opinion now carries a link. The customer reads "il tuo impianto merita
-- questi lavori" and, if they want them, presses one button — the request lands
-- on the survey it came from and notifies the people who can act on it.
--
-- The GROUP is a flag on the account, not a role. Whoever verifies a system may
-- be a technician, somebody in the office, or an administrator; making it a role
-- would force them to give up the one they have. Same shape as can_install.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

-- The token in the link. Minted when the opinion is SENT (not before: a survey
-- with no opinion has nothing to quote for), unguessable, one per survey.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'offer_token');
SET @sql := IF(@c = 0, 'ALTER TABLE inspections ADD COLUMN offer_token VARCHAR(64) NULL AFTER opinion_channel', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND INDEX_NAME = 'idx_offer_token');
SET @sql := IF(@c = 0, 'ALTER TABLE inspections ADD UNIQUE KEY idx_offer_token (offer_token)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'offer_requested_at');
SET @sql := IF(@c = 0, 'ALTER TABLE inspections ADD COLUMN offer_requested_at DATETIME NULL AFTER offer_token', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- What the customer typed when they asked. Optional: the press of the button is
-- the request, the note is whatever they wanted to add to it.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections' AND COLUMN_NAME = 'offer_note');
SET @sql := IF(@c = 0, 'ALTER TABLE inspections ADD COLUMN offer_note TEXT NULL AFTER offer_requested_at', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The verification group: "gruppo di verifica", ticked per account on Utenti.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'in_review_group');
SET @sql := IF(@c = 0, 'ALTER TABLE users ADD COLUMN in_review_group TINYINT(1) NOT NULL DEFAULT 0 AFTER can_install', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_review_group');
SET @sql := IF(@c = 0, 'ALTER TABLE users ADD KEY idx_review_group (in_review_group, active)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
