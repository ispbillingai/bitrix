-- 043_assist_requests: the portal assistance request, gated on a support
-- contract — the second Jotform-replacement step, in the client's own words:
--
--   "First condition you have signed a support contract → complete the request
--    procedure and the system will send it to a technician. But if the customer
--    does not have the support contract, he can sign it directly in the form
--    and also proceed with the payment through SmallPay, and once the payment
--    has been made it will be forwarded."
--
-- A covered customer's request becomes a ticket immediately (Tickets::open —
-- the existing chat thread — plus a WhatsApp/email to the technicians). An
-- uncovered one is HELD here: a SmallPay support subscription is opened for
-- the customer, and the moment its first payment lands (contract status →
-- active, via webhook or sync) the held request is forwarded automatically.
-- Admin can also forward one by hand (customer paid in cash / edge cases).
--
-- The attachment columns mirror ticket storage: the file is stored through
-- Tickets::storeUpload at submit time, so forwarding just hands the same
-- path/name pair to Tickets::open.

CREATE TABLE IF NOT EXISTS assist_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(190) NOT NULL,
    body TEXT NULL,
    attachment_path VARCHAR(190) NULL,
    attachment_name VARCHAR(190) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'awaiting_payment', -- awaiting_payment | forwarded | cancelled
    pay_contract_id BIGINT UNSIGNED NULL,   -- the support contract opened to unlock it
    ticket_id BIGINT UNSIGNED NULL,         -- the chat thread it became when forwarded
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    forwarded_at DATETIME NULL,
    KEY idx_ar_contact (contact_id, id),
    KEY idx_ar_status (status, id),
    KEY idx_ar_contract (pay_contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
