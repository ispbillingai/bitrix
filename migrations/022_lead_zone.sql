-- 022_lead_zone: geographic zone/area on a lead.
--
-- Lets leads be filtered by zone (e.g. "Lombardia", "Roma Nord", a sales area).
-- Free text so it fits whatever zoning the office already uses; a datalist on the
-- form suggests the zones already in the table.
-- (MySQL: no ADD COLUMN IF NOT EXISTS — the migrations table keeps this re-run safe.)

ALTER TABLE leads
    ADD COLUMN zone VARCHAR(80) NULL DEFAULT NULL AFTER source,
    ADD KEY idx_zone (zone);
