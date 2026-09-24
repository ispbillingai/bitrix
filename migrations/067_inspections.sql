-- 067_inspections: the survey (sopralluogo) and the opinion that follows it.
--
-- The shape is the installation report's, because the first half of the job is
-- the same: a technician fills a sheet on site, it becomes a PDF, and the
-- customer signs it with a one-time code through the existing Sign\Documents
-- flow. Everything after "sent" is the sign document's story and is read from
-- there, exactly as install_reports does.
--
-- What is new is the second half. A signed survey is not finished: it goes to
-- the technical group, one of them takes it in charge and writes an opinion on
-- the state of the system — a text and a rating out of five — and that opinion
-- is then sent to the customer. So the status runs one step further than an
-- installation report ever does:
--
--   draft     the technician is filling it in
--   sent      PDF built, waiting for the customer's signature
--   signed    the customer signed; it is now the technical group's to answer
--   reviewed  the opinion is written and has gone to the customer
--
-- 'signed' is set by Inspect\Inspections::onSigned, called when the sign
-- document seals. It is also recoverable without that hook: the sign document
-- knows it is signed, so a survey left in 'sent' whose document says otherwise
-- is repairable by reading sign_documents, which is why the id is kept here.

CREATE TABLE IF NOT EXISTS inspections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    -- Who carried out the survey. The name is copied at send time so the PDF
    -- keeps what it printed even if the user row is later renamed or removed.
    technician_id INT UNSIGNED NULL,
    technician_name VARCHAR(190) NULL,
    inspected_at DATETIME NULL,
    -- What was looked at. Free text throughout: a survey meets whatever is
    -- already on site, and a dropdown of models would be wrong on the first
    -- visit to a system somebody else installed.
    system_type VARCHAR(120) NULL,
    machine_model VARCHAR(80) NULL,
    serial_number VARCHAR(80) NULL,
    site_address VARCHAR(190) NULL,
    -- The body of the survey: what is there, what is wrong, what it would take.
    findings TEXT NULL,
    works_needed TEXT NULL,
    notes TEXT NULL,

    status VARCHAR(12) NOT NULL DEFAULT 'draft',  -- draft | sent | signed | reviewed
    sign_document_id BIGINT UNSIGNED NULL,
    sent_at DATETIME NULL,
    signed_at DATETIME NULL,

    -- ---- the technical group's opinion -------------------------------------
    -- Who picked it up. Set when a technician takes it in charge, so two of
    -- them do not write an opinion over each other.
    claimed_by INT UNSIGNED NULL,
    claimed_at DATETIME NULL,
    opinion_text TEXT NULL,
    -- 1..5. Nullable because an opinion in progress has no rating yet, and the
    -- range is enforced in PHP rather than by an ENUM so the scale can change
    -- without a migration.
    opinion_stars TINYINT UNSIGNED NULL,
    opinion_by INT UNSIGNED NULL,
    opinion_at DATETIME NULL,
    -- When the opinion actually went to the customer, and on what.
    opinion_sent_at DATETIME NULL,
    opinion_channel VARCHAR(16) NULL,

    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_in_contact (contact_id, id),
    KEY idx_in_owner (created_by, id),
    KEY idx_in_status (status, id),
    KEY idx_in_claimed (claimed_by, id),
    KEY idx_in_sign_doc (sign_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photos of the site, walked in order by the PDF builder. Same storage and the
-- same download door as the installation report's (?ipf=), a different folder.
CREATE TABLE IF NOT EXISTS inspection_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id BIGINT UNSIGNED NOT NULL,
    path VARCHAR(190) NOT NULL,          -- stored filename only
    orig_name VARCHAR(190) NULL,
    bytes INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_report (inspection_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
