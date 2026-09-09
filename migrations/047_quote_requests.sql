-- 047_quote_requests: the agent asks the back office for a quote, and the
-- finished quote comes back down the same wire.
--
-- The client's spec, in two doors onto one table:
--
--   1. From INSIDE a lead ("Richiedi preventivo"): the lead already carries the
--      company name and the legal details, so the agent only writes what the
--      quote has to contain. The office is notified, and the request is filed on
--      that lead — the CRM already knows who it is for.
--   2. From SCRATCH (the Jotform-style form): the agent types the customer in.
--      Company/VAT or phone/name are matched against what is already in the CRM;
--      an existing lead takes the request, and when there is none a NEW lead is
--      created and the request attached to it. Either way the request ends up on
--      a lead — there is no such thing as a floating quote request.
--
-- The quote itself is NOT stored here: the office uploads it through the
-- existing in-house signing flow (sign_documents), so the agent can send it to
-- the customer for review and — the point of it — signature, with the OTP and
-- the sealed PDF the CRM already does. document_id is that row.
--
-- status: open (waiting on the office) → ready (quote uploaded, agent can send)
--         → sent (it is with the customer) | cancelled.

CREATE TABLE IF NOT EXISTS quote_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NULL,          -- the agent who asked (NULL = office)
    notes TEXT NULL,                         -- what the quote must contain
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    document_id BIGINT UNSIGNED NULL,        -- sign_documents row: the quote itself
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ready_at DATETIME NULL,
    sent_at DATETIME NULL,
    KEY idx_qr_lead (lead_id, id),
    KEY idx_qr_status (status, id),
    KEY idx_qr_doc (document_id),
    KEY idx_qr_by (requested_by, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
