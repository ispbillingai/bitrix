-- 042_install_reports: the technician's installation report — the Jotform
-- "Installazione Cashmatic" form brought inside the CRM.
--
-- Flow: the technician opens a report on a customer, fills the machine data on
-- site (model, serial, grounding value, IPs, VPN, remote-assistance id, UPS,
-- cash collected, notes) and attaches the photos. "Send for signature" then
-- renders the whole report as a PDF and files it through the EXISTING in-house
-- signing flow (sign_documents + OTP + sealed CAdES PDF), so the customer signs
-- it from their own phone and the sealed copy lands in their portal and on the
-- customer page with zero new plumbing. The report row keeps only draft|sent:
-- everything after "sent" (viewed/signed/declined) is the sign document's story
-- and is read from there.
--
-- Photos are one row each because a report carries several "end of install"
-- shots plus the grounding-measurement one, and the PDF builder walks them in
-- order. Files live in storage/uploads/install (fallback public/uploads/install,
-- which .htaccess already denies) and leave only via the ?ipf= endpoint.

CREATE TABLE IF NOT EXISTS install_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    -- Who did the installation. The id is the login that filed it; the name is
    -- copied at send time so the PDF keeps what it printed even if the user row
    -- is later renamed or removed.
    technician_id INT UNSIGNED NULL,
    technician_name VARCHAR(190) NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    machine_model VARCHAR(80) NULL,
    serial_number VARCHAR(80) NULL,
    -- The grounding measurement as the meter shows it ("01.7") — text, not a
    -- number, because losing the leading zero changes what the tech wrote down.
    ground_value VARCHAR(40) NULL,
    local_ip VARCHAR(64) NULL,
    public_ip VARCHAR(64) NULL,
    adsl_provider VARCHAR(80) NULL,
    vpn_address VARCHAR(80) NULL,
    remote_assist_id VARCHAR(190) NULL,
    ups_installed VARCHAR(12) NOT NULL DEFAULT 'absent',   -- present | absent
    cash_collected VARCHAR(12) NOT NULL DEFAULT 'none',    -- none | checks | card | cash
    notes TEXT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'draft',           -- draft | sent
    sign_document_id BIGINT UNSIGNED NULL,
    sent_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ir_contact (contact_id, id),
    KEY idx_ir_owner (created_by, id),
    KEY idx_ir_status (status, id),
    KEY idx_ir_sign_doc (sign_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS install_report_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(12) NOT NULL DEFAULT 'final',             -- ground | final
    path VARCHAR(190) NOT NULL,                            -- stored filename only
    orig_name VARCHAR(190) NULL,
    bytes INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_irp_report (report_id, kind, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
