-- 019_partners: referrers managed as PARTNERS (not agents).
--
-- A partner refers customers via a personal link (request.php?ref=CODE). Leads
-- that come in through that link are attributed to the partner. When a referred
-- lead becomes a WON deal, a commission accrual is created (percentage of the
-- deal value) in state 'pending'; an admin approves then marks it paid. Partners
-- log into their own area (partner.php) to see their referrals, each one's
-- progress, and their accrual totals. Partners are NOT users/agents — separate
-- table, separate login, no access to the CRM dashboard.

CREATE TABLE IF NOT EXISTS partners (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    email          VARCHAR(190) NULL,
    phone          VARCHAR(32) NULL,
    ref_code       VARCHAR(32) NOT NULL,            -- goes in ?ref=
    commission_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    password_hash  VARCHAR(255) NULL,               -- for partner-area login
    active         TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ref (ref_code),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attribute a lead (and later its deal) to the referring partner.
ALTER TABLE leads
    ADD COLUMN referred_by_partner_id INT UNSIGNED NULL DEFAULT NULL AFTER assigned_to,
    ADD KEY idx_referred_partner (referred_by_partner_id);

-- Commission accruals. One row per won referred deal.
CREATE TABLE IF NOT EXISTS partner_accruals (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id   INT UNSIGNED NOT NULL,
    lead_id      BIGINT UNSIGNED NULL,
    deal_id      BIGINT UNSIGNED NULL,
    base_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,   -- the deal value the % is taken on
    commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    amount       DECIMAL(12,2) NOT NULL DEFAULT 0,   -- base_amount * pct/100
    status       ENUM('pending','approved','paid','cancelled') NOT NULL DEFAULT 'pending',
    note         VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at  DATETIME NULL,
    paid_at      DATETIME NULL,
    UNIQUE KEY uniq_deal (deal_id),                  -- one accrual per deal (idempotent)
    KEY idx_partner_status (partner_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
