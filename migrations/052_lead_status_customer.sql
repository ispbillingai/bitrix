-- 052_lead_status_customer: a lead can be closed as "already a customer".
--
-- "If you find a lead and a customer you must delete the lead, take only the
--  information he asked for and insert it in the customer message area." What
-- the person asked for goes into the customer's messages (LeadCustomers); the
-- lead is CLOSED, not deleted — deals, invoices, partner commissions and quote
-- requests point at leads, and deleting them would break all four. 'customer'
-- takes the lead off the board and out of the open counts, keeps it on record,
-- and can be undone.
--
-- Appending a value to an ENUM leaves every stored value as it is.
-- MySQL has no conditional MODIFY, so the change is guarded via information_schema.

SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'status');
SET @sql := IF(@t LIKE '%''customer''%', 'DO 0',
  'ALTER TABLE leads MODIFY COLUMN status ENUM(''open'',''converted'',''junk'',''customer'') NOT NULL DEFAULT ''open''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
