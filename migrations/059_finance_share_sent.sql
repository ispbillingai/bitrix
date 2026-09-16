-- 059_finance_share_sent: who a lender's link was sent to, and when.
-- "Could you create a button with a text box where you could enter your email
--  or phone number and send it?" (2026-09-16) — the office types an address or
--  a number next to the link and the CRM sends it, by email or WhatsApp.
-- Columns guarded via information_schema: re-run safe on MySQL and MariaDB alike.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_shares' AND COLUMN_NAME = 'sent_to');
SET @s := IF(@c = 0, 'ALTER TABLE finance_shares ADD COLUMN sent_to VARCHAR(190) NULL AFTER created_at', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_shares' AND COLUMN_NAME = 'sent_at');
SET @s := IF(@c = 0, 'ALTER TABLE finance_shares ADD COLUMN sent_at DATETIME NULL AFTER sent_to', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
