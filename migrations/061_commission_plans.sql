-- 061_commission_plans: commissions paid in instalments, as the customer pays.
--
-- "We need the ability to split commissions based on customer payments: if a
--  customer pays in three instalments and the agent is due a 10% commission,
--  we pay out that 10% in three corresponding instalments after receiving
--  payment from the customer."
--
--   commission_plans       one commission on one sale, for a partner or an
--                          agent: the total (typed, or a % of the sale) and
--                          the customer it depends on. It can follow a Sibill
--                          invoice, whose instalments (flows) then say when the
--                          customer has paid.
--   commission_plan_rates  one row per customer instalment: what the customer
--                          pays, and the payee's share of it.
--                            waiting    the customer has not paid it yet
--                            earned     the customer paid: the share became a
--                                       commission statement (statement_id),
--                                       which then runs the usual flow —
--                                       invoice, payment, notices
--                            cancelled  the plan was withdrawn before it
--   commission_statements.plan_id / rate_seq
--                          the statement a rate became, and which rate.
--
-- A Sibill flow is followed by its OWN id (sibill_flows.sibill_id): the sync
-- deletes and re-inserts every invoice's flows, so the local row id changes on
-- every run.
--
-- CREATE TABLE IF NOT EXISTS / columns guarded via information_schema: re-run
-- safe on MySQL and MariaDB alike.

CREATE TABLE IF NOT EXISTS commission_plans (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payee_type        ENUM('partner','agent') NOT NULL,
    payee_id          INT UNSIGNED NOT NULL,
    title             VARCHAR(190) NOT NULL,
    customer_name     VARCHAR(190) NULL,
    contact_id        BIGINT UNSIGNED NULL,
    sibill_invoice_id BIGINT UNSIGNED NULL,          -- sibill_invoices.id (stable across syncs)
    sale_amount       DECIMAL(12,2) NOT NULL,        -- what the customer pays in all = the rates' sum
    commission_pct    DECIMAL(6,3) NULL,             -- NULL when a fixed total was typed
    pct_base          ENUM('net','gross') NULL,      -- the % is on the taxable amount, or on the total
    vat_rate          DECIMAL(5,2) NULL,             -- to take the taxable amount out of the total
    commission_total  DECIMAL(12,2) NOT NULL,
    invoice_required  TINYINT(1) NOT NULL DEFAULT 1,
    notes             TEXT NULL,
    calc_path         VARCHAR(255) NULL,
    calc_name         VARCHAR(190) NULL,
    status            ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    source_statement_id BIGINT UNSIGNED NULL,        -- the single statement this plan replaced
    sync_note         VARCHAR(255) NULL,             -- the Sibill invoice changed under the plan
    cancel_note       VARCHAR(255) NULL,
    cancelled_at      DATETIME NULL,
    completed_at      DATETIME NULL,
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cp_payee (payee_type, payee_id, status),
    KEY idx_cp_status (status, id),
    KEY idx_cp_sibill (sibill_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commission_plan_rates (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id              BIGINT UNSIGNED NOT NULL,
    seq                  SMALLINT UNSIGNED NOT NULL,
    due_date             DATE NULL,                  -- when the customer is due to pay it
    customer_amount      DECIMAL(12,2) NOT NULL,
    commission_amount    DECIMAL(12,2) NOT NULL,
    sibill_flow_ref      VARCHAR(64) NULL,           -- sibill_flows.sibill_id
    status               ENUM('waiting','earned','cancelled') NOT NULL DEFAULT 'waiting',
    customer_paid_on     DATE NULL,
    paid_source          VARCHAR(16) NULL,           -- office | sibill
    earned_at            DATETIME NULL,
    earned_by            INT UNSIGNED NULL,
    statement_id         BIGINT UNSIGNED NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cpr_seq (plan_id, seq),
    KEY idx_cpr_flow (sibill_flow_ref),
    KEY idx_cpr_status (status, plan_id),
    KEY idx_cpr_statement (statement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commission_statements' AND COLUMN_NAME = 'plan_id');
SET @s := IF(@c = 0, 'ALTER TABLE commission_statements ADD COLUMN plan_id BIGINT UNSIGNED NULL AFTER payee_id', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commission_statements' AND COLUMN_NAME = 'rate_seq');
SET @s := IF(@c = 0, 'ALTER TABLE commission_statements ADD COLUMN rate_seq SMALLINT UNSIGNED NULL AFTER plan_id', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commission_statements' AND INDEX_NAME = 'idx_cm_plan');
SET @s := IF(@c = 0, 'ALTER TABLE commission_statements ADD KEY idx_cm_plan (plan_id)', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
