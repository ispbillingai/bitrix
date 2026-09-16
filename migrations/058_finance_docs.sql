-- 058_finance_docs: the document area on a lead, and financing applications.
--
-- "Within a lead the agent finds a button to upload general client documents and
--  another for a financing application. The administration receives them grouped
--  in that client's folder, reachable by a link. For a financing application each
--  required document has its own row with an upload button; the agent can upload
--  part of the list and finish later through the same link. Once administration
--  has checked them, a button forwards the application to the lender, generating
--  a link to send to one or more institutions. The documents are the same for
--  every lender, except the privacy form, which differs for each."
--
--   lead_files       every file filed on a lead: general ones (app_id NULL) and
--                    the ones belonging to a financing application, each in its
--                    checklist row (slot_code), the privacy form per lender.
--   finance_apps     one application per lead: collecting -> review -> sent.
--                    `token` is the upload link the agent keeps using.
--   finance_lenders  the institutions, each with its own blank privacy form.
--   finance_shares   one link per lender, revocable, counted when opened.
--
-- CREATE TABLE IF NOT EXISTS everywhere: re-run safe on MySQL and MariaDB alike.

CREATE TABLE IF NOT EXISTS finance_lenders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(32) NULL,
    notes VARCHAR(500) NULL,
    privacy_path VARCHAR(255) NULL,
    privacy_name VARCHAR(190) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_fl_active (active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS finance_apps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    status ENUM('collecting','review','sent','closed') NOT NULL DEFAULT 'collecting',
    amount DECIMAL(12,2) NULL,
    purpose VARCHAR(190) NULL,
    notes TEXT NULL,
    lender_ids VARCHAR(190) NULL,
    token VARCHAR(64) NOT NULL,
    created_by INT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    reviewed_by INT UNSIGNED NULL,
    sent_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fa_token (token),
    KEY idx_fa_lead (lead_id),
    KEY idx_fa_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    app_id BIGINT UNSIGNED NULL,
    slot_code VARCHAR(40) NULL,
    lender_id INT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    path VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source ENUM('agent','office','link') NOT NULL DEFAULT 'agent',
    uploaded_by INT UNSIGNED NULL,
    uploader_name VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lf_lead (lead_id, id),
    KEY idx_lf_app (app_id, slot_code),
    KEY idx_lf_lender (lender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS finance_shares (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id BIGINT UNSIGNED NOT NULL,
    lender_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    opens INT UNSIGNED NOT NULL DEFAULT 0,
    last_opened_at DATETIME NULL,
    UNIQUE KEY uniq_fs_token (token),
    UNIQUE KEY uniq_fs_app_lender (app_id, lender_id),
    KEY idx_fs_app (app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
