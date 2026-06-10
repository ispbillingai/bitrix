-- 011_tickets: customer support tickets / two-way customer<->agent messaging.
--
-- A ticket is a conversation thread between a customer (a contact) and the staff
-- (the agent assigned to that customer, or admin). The customer opens/reads it in
-- the portal; the agent sees and replies from the dashboard Tickets page. This is
-- both "support tickets" and the "customer<->agent chat" in one model.
-- CREATE TABLE IF NOT EXISTS — re-run safe, portable.

CREATE TABLE IF NOT EXISTS tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    assigned_agent_id INT UNSIGNED NULL,
    deal_id BIGINT UNSIGNED NULL,
    subject VARCHAR(190) NOT NULL,
    status ENUM('open','pending','closed') NOT NULL DEFAULT 'open',
    last_sender VARCHAR(16) NULL,                 -- customer | agent | admin
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_contact (contact_id),
    KEY idx_agent (assigned_agent_id, status),
    KEY idx_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_type VARCHAR(16) NOT NULL,             -- customer | agent | admin
    sender_id BIGINT UNSIGNED NULL,               -- user id (staff) or contact id (customer)
    sender_name VARCHAR(190) NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket (ticket_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
