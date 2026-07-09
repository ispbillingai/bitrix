-- 021_vat_lock: 90-day VAT-number (partita IVA) exclusivity.
--
-- When an agent or partner enters a lead with a VAT number, that VAT number is
-- "claimed" by them for crm.vat_lock_days (default 90) days: nobody else can
-- enter/work a lead with the same VAT until the claim expires. One row per VAT
-- number; an expired claim is overwritten by the next enterer.
-- (MySQL: no ADD COLUMN IF NOT EXISTS — the migrations table keeps this re-run safe.)

ALTER TABLE leads
    ADD COLUMN vat_number VARCHAR(32) NULL DEFAULT NULL AFTER customer_email,
    ADD KEY idx_vat (vat_number);

CREATE TABLE IF NOT EXISTS vat_claims (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vat_number  VARCHAR(32) NOT NULL,               -- normalized: uppercase, alphanumeric only
    owner_kind  ENUM('agent','partner') NOT NULL,   -- who holds the exclusivity
    owner_id    INT UNSIGNED NOT NULL,              -- users.id or partners.id
    lead_id     BIGINT UNSIGNED NULL,               -- the lead that created the claim
    claimed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    UNIQUE KEY uniq_vat (vat_number),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
