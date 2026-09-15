-- 055_team_chat: the team's own chat — agents, admins and technicians talking
-- to each other inside the CRM, and each of them talking to the AI assistant.
--
-- "An internal chat feature within the CRM for the team ... identical to the
--  one we use with customers — text, voice notes, video, file sharing, and
--  groups." The customer chat is a ticket (contact <-> staff); this is
-- staff <-> staff, so it gets its own three tables rather than a flag on
-- tickets: a chat, who is in it, and its messages.
--
--   team_chats          direct (two people), group (a name, any number), or
--                       ai (one person and the assistant). direct_key makes a
--                       direct chat unique per pair ("d:<low>:<high>") and the
--                       assistant chat unique per person ("ai:<user>").
--   team_chat_members   membership + how far each member has read, which is
--                       what the unread counts in the sidebar come from.
--   team_messages       one row per message. sender_id NULL = the assistant
--                       (role 'assistant') or the CRM itself (role 'system':
--                       "X added Y", "task #12 created"). meta carries the
--                       assistant's proposed actions and their outcomes.
--
-- CREATE TABLE IF NOT EXISTS: re-run safe, portable (MySQL and MariaDB).

CREATE TABLE IF NOT EXISTS team_chats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('direct','group','ai') NOT NULL DEFAULT 'direct',
    name VARCHAR(190) NULL,
    direct_key VARCHAR(64) NULL,
    created_by INT UNSIGNED NULL,
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team_direct (direct_key),
    KEY idx_team_last (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_chat_members (
    chat_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (chat_id, user_id),
    KEY idx_team_member_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NULL,
    sender_name VARCHAR(190) NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'user',
    body MEDIUMTEXT NOT NULL,
    attachment_path VARCHAR(255) NULL,
    attachment_name VARCHAR(190) NULL,
    attachment_kind VARCHAR(8) NULL,
    meta JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_team_msg_chat (chat_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
