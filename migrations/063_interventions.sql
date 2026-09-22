-- 063_interventions: a technical support intervention has a date.
--
-- Until now an assistance request ended at "presa in carico": a technician
-- claimed it and the conversation continued in the ticket thread. Nobody ever
-- wrote down "Thursday 15:00, at the customer's site", so there was nothing to
-- coordinate between technicians — and nothing a calendar could show.
--
-- Rather than a second table with its own reminders, an intervention is an
-- appointment with kind = 'intervention': the appointments table already holds
-- starts_at/ends_at/location/agent_id and drives reminders to BOTH parties, and
-- EntityResolver already reads the recipient off agent_id. The technician goes
-- in agent_id — for the scheduler a technician and a seller are the same thing,
-- a staff member with a phone and an email.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'kind');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT ''sales'' AFTER id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'assist_request_id');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD COLUMN assist_request_id BIGINT UNSIGNED NULL AFTER lead_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'ticket_id');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD COLUMN ticket_id BIGINT UNSIGNED NULL AFTER assist_request_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The intervention grid reads "everything of this kind, by date" on every open
-- of the Support tab; without this it is a filesort over the whole table.
SET @add := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_kind_starts');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD KEY idx_kind_starts (kind, starts_at)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_assist_req');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD KEY idx_assist_req (assist_request_id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The request points back at the visit currently booked for it, the way it
-- already points at its ticket. A second visit repoints it; the older
-- appointment row stays, linked by assist_request_id, so the history survives.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assist_requests' AND COLUMN_NAME = 'appointment_id');
SET @sql := IF(@add = 0, 'ALTER TABLE assist_requests ADD COLUMN appointment_id BIGINT UNSIGNED NULL AFTER ticket_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Everything booked before this migration was a sales appointment.
UPDATE appointments SET kind = 'sales' WHERE kind IS NULL OR kind = '';

-- The subscribable calendar feed. Each staff member gets an unguessable token;
-- the .ics URL built from it is the whole credential, so it is per-user and
-- regenerable from the Calendar tab. NULL until they first ask for the link.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'calendar_token');
SET @sql := IF(@add = 0, 'ALTER TABLE users ADD COLUMN calendar_token VARCHAR(64) NULL AFTER lang', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_cal_token');
SET @sql := IF(@add = 0, 'ALTER TABLE users ADD UNIQUE KEY idx_cal_token (calendar_token)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
