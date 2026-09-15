-- 057_commission_cash: a commission statement can be paid without an invoice.
-- "You can pay the balance in cash; this agent does not issue invoices" (2026-09-15).
--
--   invoice_required  1 = the payee is asked for an invoice (as before).
--                     0 = the payee issues none: nothing is asked of them and the
--                     statement is filed ready to pay (status 'invoiced' = "Da pagare").
--   payment_method    how it was paid: transfer | cash | other. The office can also
--                     pay a statement still waiting for its invoice (e.g. in cash).
--
-- Columns guarded via information_schema: re-run safe on MySQL and MariaDB alike.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commission_statements' AND COLUMN_NAME = 'invoice_required');
SET @s := IF(@c = 0, 'ALTER TABLE commission_statements ADD COLUMN invoice_required TINYINT(1) NOT NULL DEFAULT 1 AFTER calc_name', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commission_statements' AND COLUMN_NAME = 'payment_method');
SET @s := IF(@c = 0, 'ALTER TABLE commission_statements ADD COLUMN payment_method VARCHAR(16) NULL AFTER paid_amount', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
