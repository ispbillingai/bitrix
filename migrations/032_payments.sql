-- 032_payments: card payments and recurring support contracts, via SmallPay.
--
-- SmallPay (Lynx) holds the mandate and the money: it charges the customer's
-- card on a schedule it owns in the cloud, and its cashier page is where the
-- customer chooses how to pay (card, PayPal, whatever SmallPay offers that
-- day). None of that is our business. What IS our business is the question an
-- agent asks — "is this customer still paying?" — and these tables exist so
-- that is a lookup rather than an API call.
--
-- So this is a read model, not a ledger. It is kept current two ways: the
-- status callback SmallPay POSTs on every change, and a periodic pull, because
-- a callback that never arrives must not leave a customer looking paid.
--
-- The unit of work is a CONTRACT: "this customer pays this amount, this often,
-- for this reason". A one-off sale is the same thing with no recurrence.
--
-- Money is stored in cents as an integer, because that is what SmallPay takes
-- on the wire (literally: 15,00 => 1500). A DECIMAL round-trip is exactly where
-- a rate ends up a cent off from what the customer was actually charged.
-- (MySQL: no ADD COLUMN IF NOT EXISTS — the migrations table keeps this re-run safe.)

CREATE TABLE IF NOT EXISTS payment_contracts (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- subscription  = charge until someone cancels (a support contract).
    --                 SmallPay calls this FlexPay and it is the one the client
    --                 actually wants: monthly, open-ended, cancel to stop.
    -- installments  = a fixed number of rates, then it is finished
    -- one_off       = a single payment link
    kind               ENUM('subscription','installments','one_off') NOT NULL DEFAULT 'subscription',
    -- OUR id for this position, unique and never reused: the paymentId SmallPay
    -- files the debt under. Every callback and every status read is keyed on it,
    -- which is what makes a replayed callback findable.
    reference          VARCHAR(64) NOT NULL,
    -- SmallPay's own id for the same position (operationId).
    operation_id       VARCHAR(128) NULL,
    -- CRM links. contact_id is the customer; the deal is what was sold.
    contact_id         BIGINT UNSIGNED NULL,
    deal_id            BIGINT UNSIGNED NULL,
    lead_id            BIGINT UNSIGNED NULL,
    assigned_to        INT UNSIGNED NULL,
    -- Denormalised recipient, under the same names leads/deals use, so the
    -- reminder Scheduler can address a contract without a special case.
    customer_name      VARCHAR(190) NULL,
    customer_phone     VARCHAR(32) NULL,
    customer_email     VARCHAR(190) NULL,
    lang               CHAR(2) NOT NULL DEFAULT 'it',
    description        VARCHAR(190) NOT NULL,
    currency           VARCHAR(8) NOT NULL DEFAULT 'EUR',
    amount_cents       INT UNSIGNED NOT NULL DEFAULT 0,  -- the recurring charge
    first_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,  -- up-front / deposit, 0 if none
    total_cycles       SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- 0 = until cancelled
    -- draft             created here, not yet filed with SmallPay
    -- awaiting_customer filed; the customer has not completed the first payment
    -- active            mandate live, rates are being collected
    -- past_due          a rate came back INSOLUTO and is not recovered yet
    -- cancelled         ended deliberately (FlexPay unsubscribed)
    -- failed            the first payment was attempted and refused
    -- completed         a fixed plan that has run all its rates
    status             ENUM('draft','awaiting_customer','active','past_due','cancelled','failed','completed')
                       NOT NULL DEFAULT 'draft',
    checkout_url       TEXT NULL,                    -- SmallPay cashier page for the customer
    contract_url       TEXT NULL,                    -- SmallPay's signed mandate (urlContract)
    cycles_paid        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    paid_cents         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    failed_cycles      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_paid_at       DATETIME NULL,
    next_charge_date   DATE NULL,                    -- earliest rate still to pay
    activated_at       DATETIME NULL,
    cancelled_at       DATETIME NULL,
    last_sync_at       DATETIME NULL,
    last_error         TEXT NULL,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reference (reference),
    KEY idx_operation (operation_id),
    KEY idx_status (status, next_charge_date),
    KEY idx_contact (contact_id),
    KEY idx_deal (deal_id),
    KEY idx_sync (last_sync_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per rate SmallPay knows about, keyed on its installmentId. Rewritten
-- from SmallPay's answer on every sync, so a re-planned schedule converges
-- instead of accumulating ghosts. SmallPay's own vocabulary maps as:
--   DA PAGARE -> pending, IN ELABORAZIONE -> processing, PAGATO -> paid,
--   INSOLUTO  -> failed,  ELIMINATO       -> deleted
CREATE TABLE IF NOT EXISTS payment_charges (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id  BIGINT UNSIGNED NOT NULL,
    external_id  VARCHAR(128) NOT NULL,             -- SmallPay installmentId
    seq          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    currency     VARCHAR(8) NOT NULL DEFAULT 'EUR',
    status       ENUM('pending','processing','paid','failed','deleted') NOT NULL DEFAULT 'pending',
    due_date     DATE NULL,                         -- payableBy
    paid_date    DATE NULL,                         -- transactionDate
    -- Settled at the desk rather than on the card. SmallPay reports this and it
    -- must not read as a card collection when someone reconciles the month.
    paid_in_cash TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_charge (contract_id, external_id),
    KEY idx_contract (contract_id, due_date),
    KEY idx_state (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every inbound callback, verified or not, stored before it is acted on.
-- event_key is UNIQUE and that is the whole idempotency story: SmallPay retries,
-- and a replayed "rate unpaid" must not chase the customer twice.
CREATE TABLE IF NOT EXISTS payment_events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key   VARCHAR(190) NOT NULL,
    contract_id BIGINT UNSIGNED NULL,
    reference   VARCHAR(64) NULL,       -- kept even when no contract matches
    status      VARCHAR(32) NULL,       -- the position status SmallPay reported
    verified    TINYINT(1) NOT NULL DEFAULT 0,  -- did hashPass check out?
    payload     MEDIUMTEXT NULL,
    remote_ip   VARCHAR(45) NULL,
    note        VARCHAR(190) NULL,      -- why it was rejected, if it was
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event (event_key),
    KEY idx_contract (contract_id, received_at),
    KEY idx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
