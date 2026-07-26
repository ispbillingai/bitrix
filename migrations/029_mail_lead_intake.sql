-- 029_mail_lead_intake: leads that arrive as EMAIL (web-form notifications sent
-- to the company mailbox) instead of hitting the webhook directly.
--
-- The mailbox (Aruba) only offers POP3 on this plan — no IMAP, so there are no
-- server-side "seen" flags to lean on, and mail stays in the inbox for staff to
-- read in webmail. This table is therefore our own memory of which messages have
-- already been looked at: one row per message ever seen, keyed on a hash of the
-- Message-ID (or of the headers when a message has none). The first poll of a
-- freshly configured mailbox records everything already in it as 'baseline' and
-- imports nothing — years of old correspondence must not become leads.
CREATE TABLE IF NOT EXISTS mail_intake (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_key CHAR(40) NOT NULL,           -- sha1: our stable identity for the message
    message_id  VARCHAR(255) NULL,           -- raw Message-ID header, for the humans
    from_addr   VARCHAR(190) NULL,
    subject     VARCHAR(255) NULL,
    sent_at     DATETIME NULL,               -- the mail's own Date header
    status      VARCHAR(16) NOT NULL,        -- baseline | imported | skipped | error
    reason      VARCHAR(190) NULL,           -- skip rule that fired / error text
    lead_id     BIGINT UNSIGNED NULL,        -- when status = imported
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_message (message_key),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
