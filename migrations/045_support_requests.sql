-- 045_support_requests: the assistance request grows into the client's full
-- spec (2026-09-07 call):
--
--   · a PUBLIC form (crm.../support.php) with the VAT number as the FIRST
--     field, customer info, and a callback number that must be WhatsApp-able
--     (+39 by default) — stored per request, because it can differ from the
--     registry number;
--   · the only contract on offer is "Helpdesk", EUR 9.90/month;
--   · declining the contract no longer blocks the request — it goes through
--     flagged for BUSINESS-HOURS handling (priority column);
--   · the request reaches all technicians and exactly ONE takes charge:
--     claimed_by/claimed_at, set by an atomic claim so two techs pressing
--     together cannot both win.
--
-- Status meaning shifts: awaiting_payment (held for the Helpdesk payment) →
-- open (ticketized, waiting for a technician) → taken (claimed). 'forwarded'
-- is retired (no live rows carry it — test data was cleaned).
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assist_requests' AND COLUMN_NAME = 'vat_number');
SET @sql := IF(@add = 0, 'ALTER TABLE assist_requests ADD COLUMN vat_number VARCHAR(32) NULL AFTER contact_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assist_requests' AND COLUMN_NAME = 'contact_phone');
SET @sql := IF(@add = 0, 'ALTER TABLE assist_requests ADD COLUMN contact_phone VARCHAR(40) NULL AFTER vat_number', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assist_requests' AND COLUMN_NAME = 'priority');
SET @sql := IF(@add = 0, 'ALTER TABLE assist_requests ADD COLUMN priority VARCHAR(16) NOT NULL DEFAULT ''priority'' AFTER status', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assist_requests' AND COLUMN_NAME = 'claimed_by');
SET @sql := IF(@add = 0, 'ALTER TABLE assist_requests ADD COLUMN claimed_by INT UNSIGNED NULL AFTER ticket_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assist_requests' AND COLUMN_NAME = 'claimed_at');
SET @sql := IF(@add = 0, 'ALTER TABLE assist_requests ADD COLUMN claimed_at DATETIME NULL AFTER claimed_by', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assist_requests' AND COLUMN_NAME = 'source');
SET @sql := IF(@add = 0, 'ALTER TABLE assist_requests ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT ''portal'' AFTER priority', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
