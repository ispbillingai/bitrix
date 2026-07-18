-- Standalone CRM — full reference schema (MySQL 5.7+ / 8.0).
-- This mirrors the end state produced by /migrations. Fresh installs can load
-- this file directly, then run `php migrate.php` (it will mark these as applied
-- via CREATE TABLE IF NOT EXISTS / guarded ALTERs being no-ops).

CREATE DATABASE IF NOT EXISTS bitrix_glue
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE bitrix_glue;

-- ---------------------------------------------------------------------------
-- Infrastructure: audit log, settings, dashboard users (with agent profile)
-- ---------------------------------------------------------------------------

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

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(120) NOT NULL PRIMARY KEY,
    `value` TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    full_name VARCHAR(190) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(32) NULL,
    title VARCHAR(120) NULL,
    photo_url VARCHAR(255) NULL,
    lang CHAR(2) NOT NULL DEFAULT 'it',
    bitrix_user_id BIGINT UNSIGNED NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'agent',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- CRM core: pipelines/stages, contacts, leads, deals, appointments, tasks
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pipelines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('lead','deal') NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_entity (entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pipeline_id INT UNSIGNED NOT NULL,
    code VARCHAR(48) NOT NULL,
    name VARCHAR(120) NOT NULL,
    sort INT NOT NULL DEFAULT 0,
    is_first TINYINT(1) NOT NULL DEFAULT 0,
    is_won TINYINT(1) NOT NULL DEFAULT 0,
    is_lost TINYINT(1) NOT NULL DEFAULT 0,
    color VARCHAR(16) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pipeline_code (pipeline_id, code),
    KEY idx_pipeline (pipeline_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    company VARCHAR(190) NULL,
    phone VARCHAR(32) NULL,
    email VARCHAR(190) NULL,
    lang CHAR(2) NOT NULL DEFAULT 'it',
    source VARCHAR(48) NULL,
    assigned_to INT UNSIGNED NULL,
    notes TEXT NULL,
    password_hash VARCHAR(255) NULL,         -- customer portal login (optional)
    portal_token VARCHAR(64) NULL,           -- magic-link token
    portal_token_expires DATETIME NULL,
    portal_enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_phone (phone),
    KEY idx_email (email),
    KEY idx_assigned (assigned_to),
    KEY idx_portal_token (portal_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NULL,
    title VARCHAR(190) NULL,
    source VARCHAR(48) NULL,
    zone VARCHAR(80) NULL,
    pipeline_id INT UNSIGNED NULL,
    stage_code VARCHAR(48) NOT NULL DEFAULT 'NEW',
    assigned_to INT UNSIGNED NULL,
    status ENUM('open','converted','junk') NOT NULL DEFAULT 'open',
    customer_name VARCHAR(190) NULL,
    customer_phone VARCHAR(32) NULL,
    customer_email VARCHAR(190) NULL,
    vat_number VARCHAR(32) NULL,
    comments TEXT NULL,
    lang CHAR(2) NOT NULL DEFAULT 'it',
    received_at DATETIME NULL,
    stage_changed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_stage (pipeline_id, stage_code),
    KEY idx_assigned (assigned_to),
    KEY idx_status (status),
    KEY idx_contact (contact_id),
    KEY idx_zone (zone),
    KEY idx_vat (vat_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    lead_id BIGINT UNSIGNED NULL,
    pipeline_id INT UNSIGNED NULL,
    stage_code VARCHAR(48) NOT NULL DEFAULT 'NEW',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency VARCHAR(8) NOT NULL DEFAULT 'EUR',
    assigned_to INT UNSIGNED NULL,
    status ENUM('open','won','lost') NOT NULL DEFAULT 'open',
    expected_close_date DATE NULL,
    sign_due_date DATE NULL,
    offer_status VARCHAR(16) NULL,
    signed_at DATETIME NULL,
    signed_name VARCHAR(190) NULL,
    signed_ip VARCHAR(45) NULL,
    customer_name VARCHAR(190) NULL,
    customer_phone VARCHAR(32) NULL,
    customer_email VARCHAR(190) NULL,
    lang CHAR(2) NOT NULL DEFAULT 'it',
    stage_changed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_stage (pipeline_id, stage_code),
    KEY idx_assigned (assigned_to),
    KEY idx_status (status),
    KEY idx_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS appointments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NULL,
    lead_id BIGINT UNSIGNED NULL,
    agent_id INT UNSIGNED NULL,
    title VARCHAR(190) NULL,
    location VARCHAR(190) NULL,
    preferred_at DATETIME NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status ENUM('requested','confirmed','done','cancelled','no_show') NOT NULL DEFAULT 'requested',
    notes TEXT NULL,
    customer_name VARCHAR(190) NULL,
    customer_phone VARCHAR(32) NULL,
    customer_email VARCHAR(190) NULL,
    lang CHAR(2) NOT NULL DEFAULT 'it',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status (status),
    KEY idx_agent (agent_id),
    KEY idx_starts (starts_at),
    KEY idx_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    assigned_to INT UNSIGNED NULL,
    related_type VARCHAR(16) NULL,
    related_id BIGINT UNSIGNED NULL,
    due_at DATETIME NULL,
    priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    status ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
    kpi_score INT NULL,
    kpi_weight INT NOT NULL DEFAULT 1,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_assigned (assigned_to, status),
    KEY idx_due (status, due_at),
    KEY idx_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(16) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    type ENUM('note','call','email','stage','meeting','task','system') NOT NULL DEFAULT 'note',
    body TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_entity (entity_type, entity_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Reminder queue + message outbox + campaigns
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(16) NOT NULL,       -- lead | deal | appointment
    entity_id BIGINT UNSIGNED NULL,         -- local record id
    rule_key VARCHAR(48) NOT NULL,
    recipient_type ENUM('customer','agent','logistics') NOT NULL,
    channel ENUM('whatsapp','email','both') NOT NULL DEFAULT 'both',
    due_at DATETIME NOT NULL,
    skip_if_stage_changed_from VARCHAR(64) NULL,
    repeat_every_hours INT UNSIGNED NULL,   -- >0 => re-enqueue next occurrence after send
    payload JSON NULL,
    lang CHAR(2) NULL,
    status ENUM('pending','sent','skipped','cancelled','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    sent_at DATETIME NULL,
    dedupe_key VARCHAR(128) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dedupe (dedupe_key),
    KEY idx_due (status, due_at),
    KEY idx_entity (entity_type, entity_id)
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
    lang CHAR(2) NOT NULL DEFAULT 'it',
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

-- ---------------------------------------------------------------------------
-- Optional Bitrix24 sync mapping (only used when bitrix.sync_enabled = true)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sync_map (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    local_type ENUM('lead','deal','contact') NOT NULL,
    local_id BIGINT UNSIGNED NOT NULL,
    bitrix_id BIGINT UNSIGNED NULL,
    last_pushed_at DATETIME NULL,
    last_pulled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_local (local_type, local_id),
    KEY idx_bitrix (local_type, bitrix_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Customer portal: one-time codes (contract signing, future verifications)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS otp_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    deal_id BIGINT UNSIGNED NULL,
    purpose VARCHAR(24) NOT NULL DEFAULT 'sign',
    code VARCHAR(10) NOT NULL,
    channel VARCHAR(16) NOT NULL DEFAULT 'both',
    attempts INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lookup (contact_id, deal_id, purpose, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Customer support tickets / customer<->agent messaging
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    assigned_agent_id INT UNSIGNED NULL,
    deal_id BIGINT UNSIGNED NULL,
    subject VARCHAR(190) NOT NULL,
    status ENUM('open','pending','closed') NOT NULL DEFAULT 'open',
    last_sender VARCHAR(16) NULL,
    customer_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_contact (contact_id),
    KEY idx_agent (assigned_agent_id, status),
    KEY idx_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_type VARCHAR(16) NOT NULL,
    sender_id BIGINT UNSIGNED NULL,
    sender_name VARCHAR(190) NULL,
    body TEXT NOT NULL,
    attachment_path VARCHAR(255) NULL,
    attachment_name VARCHAR(190) NULL,
    downloaded_at DATETIME NULL,
    accepted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket (ticket_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Seed: default Leads and Deals pipelines with stages
-- ---------------------------------------------------------------------------

INSERT INTO pipelines (id, entity_type, name, is_default, sort) VALUES
    (1, 'lead', 'Leads', 1, 0),
    (2, 'deal', 'Deals', 1, 0)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO stages (pipeline_id, code, name, sort, is_first, is_won, is_lost, color) VALUES
    (1, 'NEW',        'New',          0, 1, 0, 0, '#5b6cff'),
    (1, 'CONTACTED',  'Contacted',    1, 0, 0, 0, '#d9a40a'),
    (1, 'QUALIFIED',  'Qualified',    2, 0, 0, 0, '#3fb868'),
    (1, 'CONVERTED',  'Converted',    3, 0, 1, 0, '#3fb868'),
    (1, 'JUNK',       'Junk',         4, 0, 0, 1, '#e5616e'),
    (2, 'NEW',        'New',          0, 1, 0, 0, '#5b6cff'),
    (2, 'QUOTE',      'Quote sent',   1, 0, 0, 0, '#d9a40a'),
    (2, 'NEGOTIATION','Negotiation',  2, 0, 0, 0, '#7c5cff'),
    (2, 'SIGNATURE',  'Signature',    3, 0, 0, 0, '#1f9bb8'),
    (2, 'WON',        'Won',          4, 0, 1, 0, '#3fb868'),
    (2, 'LOST',       'Lost',         5, 0, 0, 1, '#e5616e')
ON DUPLICATE KEY UPDATE id = id;
