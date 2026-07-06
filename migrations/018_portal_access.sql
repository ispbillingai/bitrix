-- 018_portal_access: track how often a lead/contact accesses the private area.
--
-- The customer portal is per-contact. Count each portal login (magic-link or
-- password) and remember the last access, shown on the lead/contact. A detail
-- row is also logged so we can see access history if needed later.

ALTER TABLE contacts
    ADD COLUMN portal_access_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN portal_last_access_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS portal_access_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id BIGINT UNSIGNED NOT NULL,
    method     VARCHAR(16) NOT NULL DEFAULT 'link',   -- link | password
    ip         VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_contact_time (contact_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
