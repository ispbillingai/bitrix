-- 005_crm_core: the standalone CRM data model.
--
-- This is the pivot from "Bitrix24 glue" to a CRM that owns its own data.
-- Leads/deals/contacts/appointments/tasks live here now; Bitrix (if enabled at
-- all) becomes an optional downstream sync, not the source of truth.
-- All CREATE TABLE IF NOT EXISTS — portable across MySQL and MariaDB, re-run safe.

-- Configurable pipelines (one default per entity type, like a Bitrix funnel).
CREATE TABLE IF NOT EXISTS pipelines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('lead','deal') NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_entity (entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ordered stages within a pipeline. `code` is the stable key reminders compare
-- against (skip_if_stage_changed_from); is_first/is_won/is_lost drive automation.
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

-- People/companies. Leads and deals reference a contact; messages reuse these
-- phone/email values. findOrCreate() de-dupes on phone/email (see Crm\Contacts).
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_phone (phone),
    KEY idx_email (email),
    KEY idx_assigned (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Incoming requests. customer_* are denormalised so a reminder can render even
-- if the contact row is later edited; stage_changed_at anchors inactivity timers.
CREATE TABLE IF NOT EXISTS leads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NULL,
    title VARCHAR(190) NULL,
    source VARCHAR(48) NULL,
    pipeline_id INT UNSIGNED NULL,
    stage_code VARCHAR(48) NOT NULL DEFAULT 'NEW',
    assigned_to INT UNSIGNED NULL,
    status ENUM('open','converted','junk') NOT NULL DEFAULT 'open',
    customer_name VARCHAR(190) NULL,
    customer_phone VARCHAR(32) NULL,
    customer_email VARCHAR(190) NULL,
    comments TEXT NULL,
    lang CHAR(2) NOT NULL DEFAULT 'it',
    received_at DATETIME NULL,
    stage_changed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_stage (pipeline_id, stage_code),
    KEY idx_assigned (assigned_to),
    KEY idx_status (status),
    KEY idx_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Opportunities with a value and a pipeline. Entering the quote stage starts the
-- signing-reminder cadence; the won stage fires thank-you + logistics (see Crm\Deals).
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

-- Customer appointment with a salesperson. The customer requests (status
-- 'requested', preferred_at); staff assign an agent and confirm a real starts_at,
-- which enqueues reminders to BOTH parties (req #5).
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

-- Activities assigned to agents, with an optional KPI score on completion
-- (doc requirement: "Score Evaluation (KPI)"). related_type/id link to a record.
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

-- Per-record timeline (notes, calls, stage moves, system events) shown on the
-- lead/deal detail drawer. Distinct from `events` (machine audit log).
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
