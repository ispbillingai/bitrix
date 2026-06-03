-- 001_init: initial tables. Idempotent (IF NOT EXISTS) so re-runs are safe.
-- No CREATE DATABASE / USE here — migrate.php runs against the configured DB.

CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(32) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    entity_type VARCHAR(16) NULL,
    entity_id BIGINT UNSIGNED NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_entity (entity_type, entity_id),
    KEY idx_created (created_at),
    KEY idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tracked_entities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(16) NOT NULL,
    bitrix_id BIGINT UNSIGNED NOT NULL,
    stage_id VARCHAR(64) NULL,
    assigned_by_id BIGINT UNSIGNED NULL,
    customer_phone VARCHAR(32) NULL,
    customer_email VARCHAR(190) NULL,
    customer_name VARCHAR(190) NULL,
    received_at DATETIME NULL,
    stage_changed_at DATETIME NULL,
    last_synced_at DATETIME NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_entity (entity_type, bitrix_id),
    KEY idx_stage (stage_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(16) NOT NULL,
    bitrix_id BIGINT UNSIGNED NULL,
    rule_key VARCHAR(48) NOT NULL,
    recipient_type ENUM('customer','agent','logistics') NOT NULL,
    channel ENUM('whatsapp','email','both') NOT NULL DEFAULT 'both',
    due_at DATETIME NOT NULL,
    skip_if_stage_changed_from VARCHAR(64) NULL,
    payload JSON NULL,
    status ENUM('pending','sent','skipped','cancelled','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    sent_at DATETIME NULL,
    dedupe_key VARCHAR(128) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dedupe (dedupe_key),
    KEY idx_due (status, due_at),
    KEY idx_entity (entity_type, bitrix_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reminder_id BIGINT UNSIGNED NULL,
    campaign_id BIGINT UNSIGNED NULL,
    channel ENUM('whatsapp','email') NOT NULL,
    recipient VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NULL,
    body MEDIUMTEXT NULL,
    status ENUM('sent','failed') NOT NULL,
    provider_response JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reminder (reminder_id),
    KEY idx_campaign (campaign_id),
    KEY idx_recipient (recipient)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    channel ENUM('whatsapp','email') NOT NULL DEFAULT 'whatsapp',
    subject VARCHAR(255) NULL,
    body MEDIUMTEXT NOT NULL,
    status ENUM('draft','running','paused','done') NOT NULL DEFAULT 'draft',
    total INT UNSIGNED NOT NULL DEFAULT 0,
    sent INT UNSIGNED NOT NULL DEFAULT 0,
    failed INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaign_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(190) NOT NULL,
    name VARCHAR(190) NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_campaign_status (campaign_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
