-- 064_appointment_types_zones: the calendar grows a pool, a zone and a label.
--
-- Three things the client asked for that the appointments table could not say:
--
--   * "Generic" appointments — booked before anyone is sent. agent_id was
--     already NULL-able, so the pool is simply "agent_id IS NULL"; no status
--     invented for it, because such a visit is confirmed with the customer,
--     it just has nobody driving to it yet.
--   * zone — the area or postcode, so whoever plans the round can group a
--     day's jobs by where they are instead of by when they came in.
--   * type_code — INSTALLATION / SUPPORT / SURVEY…, the label the calendar
--     colours by and filters on. It sits ALONGSIDE kind rather than replacing
--     it: kind ('sales' | 'intervention') decides which messages a visit
--     sends, the type decides what it is called, what colour it wears and
--     which form it opens. Each type declares its own kind, so choosing
--     "INSTALLAZIONE" sets both.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'zone');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD COLUMN `zone` VARCHAR(120) NULL AFTER location', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'type_code');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD COLUMN type_code VARCHAR(32) NULL AFTER kind', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The install report a visit of type INSTALLATION opened, so the technician
-- returns to the same draft instead of starting a second one.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'install_report_id');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD COLUMN install_report_id BIGINT UNSIGNED NULL AFTER ticket_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Who booked it — the pool needs to show where an unassigned job came from.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'created_by');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD COLUMN created_by INT UNSIGNED NULL AFTER lang', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The pool query ("everything with nobody on it, soonest first") and the zone
-- grouping are both read on every calendar open.
SET @add := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_pool');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD KEY idx_pool (agent_id, starts_at)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_zone');
SET @sql := IF(@add = 0, 'ALTER TABLE appointments ADD KEY idx_zone (`zone`, starts_at)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- The label list. A table, not an enum: the client named three types and said
-- "etc.", so the office adds its own from Settings without a migration.
--
-- opens_install_form = 1 makes the appointment carry an installation report:
-- the technician opens the job and the checklist is already there, which is the
-- "dynamic behaviour" asked for.
CREATE TABLE IF NOT EXISTS appointment_types (
    code VARCHAR(32) NOT NULL PRIMARY KEY,
    name_it VARCHAR(80) NOT NULL,
    name_en VARCHAR(80) NOT NULL,
    -- Which family of messages this type sends: a technician's visit
    -- ('intervention') or a seller's meeting ('sales').
    kind VARCHAR(16) NOT NULL DEFAULT 'intervention',
    color VARCHAR(16) NOT NULL DEFAULT '#6b7280',
    opens_install_form TINYINT(1) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_active (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The three the client named, plus the sales meeting the CRM already books.
-- Flat colours, no gradients. INSERT IGNORE so a re-run never overwrites a
-- colour or a name the office has since changed.
INSERT IGNORE INTO appointment_types (code, name_it, name_en, kind, color, opens_install_form, sort) VALUES
    ('INSTALLATION', 'Installazione',   'Installation',  'intervention', '#2563eb', 1, 10),
    ('SUPPORT',      'Assistenza',      'Support',       'intervention', '#16a34a', 0, 20),
    ('SURVEY',       'Sopralluogo',     'Site survey',   'intervention', '#d97706', 0, 30),
    ('MEETING',      'Appuntamento commerciale', 'Sales appointment', 'sales', '#7c3aed', 0, 40);

-- Everything booked before today keeps its meaning: a support visit came from
-- an assistance request, anything else was a sales appointment.
UPDATE appointments SET type_code = 'SUPPORT'
 WHERE type_code IS NULL AND kind = 'intervention';
UPDATE appointments SET type_code = 'MEETING'
 WHERE type_code IS NULL AND kind = 'sales';

-- ---------------------------------------------------------------------------
-- "Tomorrow's schedule is planned" — the confirmation the technician clicks
-- from the 17:00 WhatsApp/email. One row per technician per planned day; the
-- token is the whole credential in the link, so it is unguessable and single-
-- purpose. Escalation stops the moment confirmed_at is set.
CREATE TABLE IF NOT EXISTS planning_confirmations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    -- The day being PLANNED (tomorrow, when the 17:00 message goes out).
    plan_date DATE NOT NULL,
    token VARCHAR(64) NOT NULL,
    confirmed_at DATETIME NULL,
    -- How many escalation messages have gone out for this day, so the cron can
    -- space them and stop at the cap.
    nudges INT NOT NULL DEFAULT 0,
    last_nudge_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_user_day (user_id, plan_date),
    UNIQUE KEY idx_token (token),
    KEY idx_open (confirmed_at, plan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
