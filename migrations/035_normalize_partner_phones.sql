-- 035_normalize_partner_phones: store partner phones in the shape the code compares.
--
-- Migration 031 did this for leads and contacts; partners were left storing
-- whatever was typed, so "3331110001" and "+393331110001" are two different
-- strings for one number. Partners::duplicateId() normalises both sides before
-- comparing, so detection already holds — but login() matches the stored value
-- with plain equality, and create()/update() now write the normalised form, so
-- the rows written earlier have to be brought to the same shape or an existing
-- partner could stop matching his own number.
--
-- Mirrors Notifier::normalizePhone step for step, exactly as 031 did: keep a
-- leading +, drop everything non-digit, turn 00 into +, and give a bare national
-- number the default country code with leading trunk zeros dropped.

UPDATE partners SET phone = NULL WHERE phone = '';
UPDATE partners
   SET phone = CONCAT(IF(LEFT(phone, 1) = '+', '+', ''), REGEXP_REPLACE(phone, '[^0-9]', ''))
 WHERE phone IS NOT NULL;
UPDATE partners SET phone = CONCAT('+', SUBSTRING(phone, 3)) WHERE phone LIKE '00%';
UPDATE partners SET phone = CONCAT('+39', TRIM(LEADING '0' FROM phone))
 WHERE phone IS NOT NULL AND phone NOT LIKE '+%';
UPDATE partners SET phone = NULL WHERE phone IN ('+', '+39');
