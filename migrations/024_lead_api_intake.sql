-- 024_lead_api_intake: fields the partner lead API needs.
--
-- external_id  the sender's own id for the request. A repeated POST with the
--              same (source, external_id) returns the lead created the first
--              time instead of a second copy, so a partner can safely retry a
--              request that timed out. The UNIQUE key enforces it in the DB too
--              (MySQL allows many NULLs in a UNIQUE key, so leads created by
--              hand/the public form are unaffected).
-- source_url   the site or page the request came from — "the original website".
--              Kept next to `source` so a partner who sends from several of
--              their sites is still one source in the monthly report.
-- (MySQL: no ADD COLUMN IF NOT EXISTS — the migrations table keeps this re-run safe.)

ALTER TABLE leads
    ADD COLUMN external_id VARCHAR(64) NULL DEFAULT NULL AFTER source,
    ADD COLUMN source_url VARCHAR(255) NULL DEFAULT NULL AFTER external_id,
    ADD UNIQUE KEY uniq_source_external (source, external_id);
