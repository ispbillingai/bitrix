-- Bitrix24 glue middleware schema (MySQL 5.7+ / MariaDB 10.4+)
-- This is the full reference schema. Incremental changes live in /migrations.

CREATE DATABASE IF NOT EXISTS bitrix_glue
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE bitrix_glue;

-- Audit log of everything that comes in or gets processed.
-- Mirrors the parking app's gate_events pattern.
CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(32) NOT NULL,            -- form_intake | bitrix_event | scheduler | campaign
    event_type VARCHAR(64) NOT NULL,        -- lead_created | stage_changed | reminder_sent ...
    entity_type VARCHAR(16) NULL,           -- lead | deal | contact | appointment
    entity_id BIGINT UNSIGNED NULL,         -- Bitrix entity id
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_entity (entity_type, entity_id),
    KEY idx_created (created_at),
    KEY idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Local mirror of the Bitrix entities whose timers we watch. We only store
-- the few fields needed to decide "has this moved?" — Bitrix stays the source of truth.
CREATE TABLE IF NOT EXISTS tracked_entities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(16) NOT NULL,       -- lead | deal
    bitrix_id BIGINT UNSIGNED NOT NULL,
    stage_id VARCHAR(64) NULL,              -- current STATUS_ID / STAGE_ID
    assigned_by_id BIGINT UNSIGNED NULL,    -- Bitrix user id of the agent
    customer_phone VARCHAR(32) NULL,
    customer_email VARCHAR(190) NULL,
    customer_name VARCHAR(190) NULL,
    lang CHAR(2) NOT NULL DEFAULT 'it',     -- recipient language for messages (en|it)
    received_at DATETIME NULL,              -- when it first landed (timer anchor)
    stage_changed_at DATETIME NULL,         -- last time stage_id changed
    last_synced_at DATETIME NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_entity (entity_type, bitrix_id),
    KEY idx_stage (stage_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The scheduled-work queue. The cron scheduler picks up due, pending rows.
-- rule_key identifies which automation produced it (see src/Reminder/Rules.php).
CREATE TABLE IF NOT EXISTS reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(16) NOT NULL,       -- lead | deal | appointment
    bitrix_id BIGINT UNSIGNED NULL,         -- related Bitrix entity (lead/deal id)
    rule_key VARCHAR(48) NOT NULL,          -- welcome | agent_assigned | lead_inactivity |
                                            -- appointment | sign_due | sign_overdue | thank_you
    recipient_type ENUM('customer','agent','logistics') NOT NULL,
    channel ENUM('whatsapp','email','both') NOT NULL DEFAULT 'both',
    due_at DATETIME NOT NULL,
    -- guard: if set, scheduler re-reads Bitrix and SKIPS if the entity already
    -- moved past this stage (manual-silence: changing the deal status cancels it).
    skip_if_stage_changed_from VARCHAR(64) NULL,
    payload JSON NULL,                      -- extra template vars (appointment time, etc.)
    lang CHAR(2) NULL,                      -- override recipient language; NULL = use entity's
    status ENUM('pending','sent','skipped','cancelled','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    sent_at DATETIME NULL,
    -- dedup key so the same automation never schedules twice for one entity+offset.
    dedupe_key VARCHAR(128) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dedupe (dedupe_key),
    KEY idx_due (status, due_at),
    KEY idx_entity (entity_type, bitrix_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Outbox: one row per message actually dispatched (audit + delivery status).
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reminder_id BIGINT UNSIGNED NULL,
    campaign_id BIGINT UNSIGNED NULL,
    channel ENUM('whatsapp','email') NOT NULL,
    recipient VARCHAR(190) NOT NULL,        -- phone (E.164) or email
    subject VARCHAR(255) NULL,
    body MEDIUMTEXT NULL,
    status ENUM('sent','failed') NOT NULL,
    provider_response JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reminder (reminder_id),
    KEY idx_campaign (campaign_id),
    KEY idx_recipient (recipient)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mass WhatsApp / email campaigns (req part2 #2 marketing). Recipients are
-- expanded into messages rows as they are sent, throttled by the scheduler.
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
