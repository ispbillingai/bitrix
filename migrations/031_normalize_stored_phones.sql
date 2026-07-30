-- Duplicate matching compares phones with plain equality against the
-- normalized form Notifier::normalizePhone produces (+39..., digits only).
-- Rows written before that normalization existed still hold whatever was
-- typed ("3281548550", "081 123 45 67"), so re-entering the same customer
-- with the same number sails past the filter and files a second lead —
-- seen live on leads 98 and 101. Rewrite stored phones to the shape the
-- code writes today, mirroring normalizePhone step for step: keep a
-- leading +, drop everything non-digit, turn 00 into +, and give bare
-- national numbers the default country code with leading trunk zeros
-- dropped. 39 is hardcoded on purpose: it is app.default_country_code's
-- default and every number this CRM has ever stored. A number stored with
-- its country code but no + ("39328...") becomes +3939328... — exactly
-- what normalizePhone returns when the same thing is typed again, and it
-- is the match that matters, not beauty.

UPDATE leads SET customer_phone = NULL WHERE customer_phone = '';
UPDATE leads
   SET customer_phone = CONCAT(IF(LEFT(customer_phone, 1) = '+', '+', ''),
                               REGEXP_REPLACE(customer_phone, '[^0-9]', ''))
 WHERE customer_phone IS NOT NULL;
UPDATE leads SET customer_phone = CONCAT('+', SUBSTRING(customer_phone, 3))
 WHERE customer_phone LIKE '00%';
UPDATE leads SET customer_phone = CONCAT('+39', TRIM(LEADING '0' FROM customer_phone))
 WHERE customer_phone IS NOT NULL AND customer_phone NOT LIKE '+%';
UPDATE leads SET customer_phone = NULL WHERE customer_phone IN ('+', '+39');

-- Contacts::findOrCreate compares with the same equality; same guarantee.
UPDATE contacts SET phone = NULL WHERE phone = '';
UPDATE contacts
   SET phone = CONCAT(IF(LEFT(phone, 1) = '+', '+', ''),
                      REGEXP_REPLACE(phone, '[^0-9]', ''))
 WHERE phone IS NOT NULL;
UPDATE contacts SET phone = CONCAT('+', SUBSTRING(phone, 3))
 WHERE phone LIKE '00%';
UPDATE contacts SET phone = CONCAT('+39', TRIM(LEADING '0' FROM phone))
 WHERE phone IS NOT NULL AND phone NOT LIKE '+%';
UPDATE contacts SET phone = NULL WHERE phone IN ('+', '+39');
