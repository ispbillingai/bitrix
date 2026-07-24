-- 027_sibill_invoices: local mirror of the invoices held in Sibill, so the CRM
-- can answer "has this customer paid?" without calling the API on every page.
--
-- Sibill models an invoice's money as one or more "flows" (scadenze): each is an
-- instalment with its own due date, method and PAID/TO_PAY status. An invoice is
-- paid when every flow is PAID, part-paid when only some are. We keep both the
-- per-flow rows (the detail an agent needs when chasing) and the rolled-up state
-- on the invoice (what every list and filter reads).
--
-- Nothing here is a source of truth: the sync rewrites it from Sibill, and a row
-- disappears only when it is gone upstream too.
-- (MySQL: no ADD COLUMN IF NOT EXISTS — the migrations table keeps this re-run safe.)

CREATE TABLE IF NOT EXISTS sibill_invoices (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sibill_id         CHAR(36) NOT NULL,                  -- document uuid upstream
    company_id        CHAR(36) NOT NULL,                  -- Sibill company the doc belongs to
    direction         ENUM('ISSUED','RECEIVED') NOT NULL DEFAULT 'ISSUED',
    doc_type          VARCHAR(32) NOT NULL DEFAULT 'INVOICE', -- INVOICE | CREDIT_NOTE | OTHER
    number            VARCHAR(64) NULL,                   -- invoice number as printed
    creation_date     DATE NULL,                          -- invoice date
    counterpart_name  VARCHAR(190) NULL,
    counterpart_vat   VARCHAR(32) NULL,                   -- normalized: uppercase alphanumeric
    currency          VARCHAR(8) NOT NULL DEFAULT 'EUR',
    gross_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,   -- invoice total
    paid_amount       DECIMAL(14,2) NOT NULL DEFAULT 0,   -- sum of PAID flows
    open_amount       DECIMAL(14,2) NOT NULL DEFAULT 0,   -- sum of TO_PAY flows (what is still owed)
    pay_state         ENUM('paid','partial','unpaid','unknown') NOT NULL DEFAULT 'unknown',
    due_date          DATE NULL,                          -- earliest unpaid instalment; NULL once paid
    last_paid_date    DATE NULL,                          -- latest settlement date among PAID flows
    flows_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    flows_paid        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- Resolved CRM links (best-effort, by VAT then by company name). Nullable:
    -- most invoices belong to customers that never passed through the pipeline.
    contact_id        BIGINT UNSIGNED NULL,
    deal_id           BIGINT UNSIGNED NULL,
    lead_id           BIGINT UNSIGNED NULL,
    first_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_doc (sibill_id),
    KEY idx_state (pay_state, due_date),
    KEY idx_vat (counterpart_vat),
    KEY idx_contact (contact_id),
    KEY idx_deal (deal_id),
    KEY idx_created (creation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per instalment. Replaced wholesale on each sync of its invoice, so
-- there is no merge logic to get wrong when Sibill re-plans a payment schedule.
CREATE TABLE IF NOT EXISTS sibill_flows (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id     BIGINT UNSIGNED NOT NULL,              -- sibill_invoices.id
    sibill_id      CHAR(36) NOT NULL,                     -- flow uuid upstream
    amount         DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency       VARCHAR(8) NOT NULL DEFAULT 'EUR',
    payment_status ENUM('PAID','TO_PAY') NOT NULL DEFAULT 'TO_PAY',
    payment_method VARCHAR(32) NULL,                      -- TRANSFER | SDD | RIBA | CHECK | CASH | ...
    -- Sibill's own naming is the wrong way round for our reading: payment_date is
    -- when the instalment falls DUE, expected_payment_date is when it actually
    -- settled once the flow is reconciled. Stored under names that say so.
    due_date       DATE NULL,                             -- <- payment_date
    settled_date   DATE NULL,                             -- <- expected_payment_date, only meaningful when PAID
    UNIQUE KEY uniq_flow (sibill_id),
    KEY idx_invoice (invoice_id),
    KEY idx_due (payment_status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
