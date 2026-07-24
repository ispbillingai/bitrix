-- 028_sibill_customers: the customer-level view of who owes money, and the
-- contact details needed to chase them.
--
-- Sibill knows a counterpart's VAT number, name and address. It does NOT hold a
-- phone number or an email address — its own "send a courtesy copy" endpoint
-- asks the caller to supply one. So a payment reminder cannot be addressed from
-- Sibill data alone: phone/email are staff-owned columns here, pre-filled from a
-- matching CRM contact when there is one, and typed in when there isn't.
--
-- Column names are deliberately name/phone/email/lang: that is exactly what
-- Crm\EntityResolver already reads, so the existing reminder engine can address
-- one of these rows without a special case.

-- 027 created its tables with `DEFAULT CHARSET=utf8mb4` and no COLLATE, which
-- takes the charset default (utf8mb4_general_ci) rather than the database
-- default (utf8mb4_unicode_ci) that leads/contacts use. Comparing the two in a
-- JOIN is an "illegal mix of collations" error. Nothing joined them until now,
-- so this is a latent fault being fixed before the first query that would hit
-- it. (vat_claims has the same quirk from migration 021 and is left alone: it is
-- only ever compared against bound parameters, and rewriting it would touch the
-- VAT exclusivity lock for no gain.)
ALTER TABLE sibill_invoices CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE sibill_flows    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 'sibill_customer' is 15 characters and the column was VARCHAR(16) — true, but
-- only just. Widen it so the next entity type is not a silent truncation.
ALTER TABLE reminders MODIFY entity_type VARCHAR(32) NOT NULL;

CREATE TABLE IF NOT EXISTS sibill_customers (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vat_number       VARCHAR(32) NOT NULL,            -- normalized; the identity of a customer
    name             VARCHAR(190) NOT NULL,           -- company name as Sibill spells it
    contact_id       BIGINT UNSIGNED NULL,            -- matched CRM contact, when there is one
    -- Staff-owned. The sync never overwrites a value someone typed here; it only
    -- fills a blank from a newly matched CRM contact.
    phone            VARCHAR(32) NULL,
    email            VARCHAR(190) NULL,
    lang             CHAR(2) NOT NULL DEFAULT 'it',
    -- Chasing controls. chase_enabled is per customer so one awkward account can
    -- be left out without switching the whole thing off; snooze_until parks a
    -- customer who has promised to pay by a date.
    chase_enabled    TINYINT(1) NOT NULL DEFAULT 1,
    snooze_until     DATE NULL,
    last_reminded_at DATETIME NULL,
    reminders_sent   INT UNSIGNED NOT NULL DEFAULT 0,
    notes            TEXT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vat (vat_number),
    KEY idx_contact (contact_id),
    KEY idx_chase (chase_enabled, last_reminded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
