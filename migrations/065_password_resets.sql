-- 065_password_resets: "ho dimenticato la password" for staff logins.
--
-- A table rather than two columns on `users`, for three reasons: the request
-- history is what rate limiting counts, a used token stays on file as evidence
-- of when an account's password was changed and through which channel, and a
-- second request does not silently destroy a link the person is already
-- holding — both are valid until one is spent.
--
-- The token IS the credential, so it is long, single-use and short-lived, and
-- the row records where the request came from.

CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    -- Which channels the link actually went out on, for the audit trail:
    -- 'both', 'whatsapp', 'email'.
    channel VARCHAR(16) NOT NULL DEFAULT 'both',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    request_ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_token (token),
    -- The rate-limit count: "how many has this account asked for lately".
    KEY idx_user_recent (user_id, created_at),
    KEY idx_open (used_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
