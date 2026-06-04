-- 002_entity_lang: per-customer language so messages go out in EN or IT.
-- Idempotent: ADD COLUMN IF NOT EXISTS (MySQL 8 / MariaDB 10.4+).

ALTER TABLE tracked_entities
    ADD COLUMN IF NOT EXISTS lang CHAR(2) NOT NULL DEFAULT 'it' AFTER customer_name;

ALTER TABLE reminders
    ADD COLUMN IF NOT EXISTS lang CHAR(2) NULL AFTER payload;

ALTER TABLE campaigns
    ADD COLUMN IF NOT EXISTS lang CHAR(2) NOT NULL DEFAULT 'it' AFTER channel;
