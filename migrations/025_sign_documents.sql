-- 025_sign_documents: in-house certification body for customer documents.
--
-- The agent uploads (or generates) a PDF, the CRM hashes it, sends the customer
-- a one-time code, and on a correct code seals everything into a signed PDF:
-- the certificate page + the original file embedded verbatim, covered by one
-- CAdES/PAdES signature made with our own key (src/Sign).
--
-- Three tables:
--   sign_documents   one row per document put up for signature
--   sign_signatures  the signature act itself (signer, OTP evidence, IP/UA)
--   sign_audit       the operation log — hash-chained AND append-only at the
--                    database level (triggers below), because "the log must not
--                    be editable" is the whole point of doing this in-house.
--
-- MySQL only (no MariaDB-only SQL); columns are guarded via information_schema.

CREATE TABLE IF NOT EXISTS sign_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Public reference printed on the certificate and used by public/verify.php.
    uid CHAR(32) NOT NULL,
    title VARCHAR(190) NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    deal_id BIGINT UNSIGNED NULL,
    -- Who we asked to sign. Copied from the contact at send time so the evidence
    -- keeps what we actually used, even if the contact is edited later.
    signer_name VARCHAR(190) NULL,
    signer_email VARCHAR(190) NULL,
    signer_phone VARCHAR(40) NULL,
    lang VARCHAR(5) NULL,
    -- The original file, byte-for-byte, plus its digest at ingest.
    orig_name VARCHAR(190) NOT NULL,
    orig_path VARCHAR(190) NOT NULL,
    orig_sha256 CHAR(64) NOT NULL,
    orig_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    orig_mime VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    -- The sealed artifact produced at signing time.
    signed_path VARCHAR(190) NULL,
    signed_sha256 CHAR(64) NULL,
    signed_bytes BIGINT UNSIGNED NULL,
    -- draft | sent | viewed | signed | declined | expired | void
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    -- Tokenised signing link, so a customer with no portal account can sign.
    access_token CHAR(48) NULL,
    token_expires DATETIME NULL,
    sent_at DATETIME NULL,
    viewed_at DATETIME NULL,
    signed_at DATETIME NULL,
    declined_at DATETIME NULL,
    decline_reason VARCHAR(255) NULL,
    -- Signing certificate actually used, and the RFC 3161 token time if any.
    cert_subject VARCHAR(255) NULL,
    cert_serial VARCHAR(64) NULL,
    cert_fingerprint CHAR(64) NULL,
    tsa_time DATETIME NULL,
    tsa_url VARCHAR(190) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sign_uid (uid),
    KEY idx_sign_token (access_token),
    KEY idx_sign_contact (contact_id),
    KEY idx_sign_deal (deal_id),
    KEY idx_sign_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sign_signatures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    signer_name VARCHAR(190) NOT NULL,
    signer_email VARCHAR(190) NULL,
    signer_phone VARCHAR(40) NULL,
    -- How identity was proven. 'otp' today; leaves room for future methods.
    method VARCHAR(24) NOT NULL DEFAULT 'otp',
    otp_channel VARCHAR(16) NULL,
    otp_sent_to VARCHAR(190) NULL,   -- masked destination, e.g. +3933****4977
    otp_sent_at DATETIME NULL,
    -- SHA-256 of the code (salted with the document uid). Proves which code was
    -- accepted without keeping the code itself readable in the database.
    otp_hash CHAR(64) NULL,
    otp_attempts INT NOT NULL DEFAULT 0,
    consent TINYINT(1) NOT NULL DEFAULT 0,
    typed_name VARCHAR(190) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    signed_at DATETIME NULL,
    -- Canonical evidence JSON + its digest; the digest is printed on the
    -- certificate, so the record and the paper always agree.
    evidence_json MEDIUMTEXT NULL,
    evidence_sha256 CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sig_doc (document_id, id),
    KEY idx_sig_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The operation log. Every row carries the hash of the row before it, so a
-- single altered or removed entry breaks the chain from that point on and
-- Sign\Audit::verify() reports exactly where.
CREATE TABLE IF NOT EXISTS sign_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL,
    event VARCHAR(40) NOT NULL,
    actor_type VARCHAR(16) NOT NULL DEFAULT 'system',  -- staff | customer | system
    actor_id BIGINT UNSIGNED NULL,
    actor_label VARCHAR(190) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    data JSON NULL,
    occurred_at DATETIME(3) NOT NULL,
    prev_hash CHAR(64) NOT NULL,
    hash CHAR(64) NOT NULL,
    UNIQUE KEY uq_audit_seq (document_id, seq),
    KEY idx_audit_doc (document_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only, enforced by the database rather than by convention: even a
-- logged-in DBA (or this application) cannot UPDATE or DELETE an audit row.
-- Needs the TRIGGER privilege on the schema; if the migration fails here, grant
-- it (GRANT TRIGGER ON <db>.* TO '<user>'@'<host>') and re-run. Cleaning up test
-- data means dropping these two triggers first and re-creating them after.
DROP TRIGGER IF EXISTS sign_audit_no_update;
CREATE TRIGGER sign_audit_no_update BEFORE UPDATE ON sign_audit FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sign_audit is append-only: rows cannot be modified';

DROP TRIGGER IF EXISTS sign_audit_no_delete;
CREATE TRIGGER sign_audit_no_delete BEFORE DELETE ON sign_audit FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sign_audit is append-only: rows cannot be deleted';

-- The OTP table (migration 010) is reused for document codes: purpose='doc' and
-- deal_id holding the document id would collide with real deals, so add an own
-- column instead and widen the lookup index.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_codes' AND COLUMN_NAME = 'document_id');
SET @sql := IF(@add = 0, 'ALTER TABLE otp_codes ADD COLUMN document_id BIGINT UNSIGNED NULL AFTER deal_id', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_codes' AND INDEX_NAME = 'idx_doc_lookup');
SET @sql := IF(@idx = 0, 'ALTER TABLE otp_codes ADD KEY idx_doc_lookup (document_id, purpose, used_at)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
