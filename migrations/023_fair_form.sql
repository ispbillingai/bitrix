-- 023_fair_form: trade-fair (fiera) lead capture.
--
-- The fair form is the public request form with the fair as a reference: which
-- fair, and in which city. Both are stored on the lead so a fair's leads export
-- in the same file as every other lead (one consistent export).
--
-- form_views counts how many times a PUBLIC form was opened, so the office can
-- see the reach of a fair link vs. how many leads it produced. One row per view,
-- keyed by form (e.g. 'fair'), so totals and per-month counts are both available.
-- (MySQL: no ADD COLUMN IF NOT EXISTS — the migrations table keeps this re-run safe.)

ALTER TABLE leads
    ADD COLUMN fair_name VARCHAR(120) NULL DEFAULT NULL AFTER zone,
    ADD COLUMN fair_city VARCHAR(120) NULL DEFAULT NULL AFTER fair_name,
    ADD KEY idx_fair (fair_name);

CREATE TABLE IF NOT EXISTS form_views (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_key   VARCHAR(32) NOT NULL,          -- 'fair', 'request', ...
    ref        VARCHAR(120) NULL,             -- optional context (e.g. the fair name)
    ip         VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_form_time (form_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
