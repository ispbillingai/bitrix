-- 063_campaign_media: a campaign can carry a photo or a document, and its
-- recipients can be picked from the customer list instead of typed.
--
-- "Nel menu Campagne mi dai la possibilità di scegliere i clienti dalla lista
--  così da non scriverli, e oltre al messaggio se mi puoi far allegare anche
--  una foto o un documento."
--
--   campaigns.media_*        the attachment: where it is kept, what it was
--                            called, and whether it goes out as a PHOTO or as a
--                            DOCUMENT — TextMeBot takes an image on `file` and
--                            a PDF on `document`, and email attaches either.
--                            The file lives in public/uploads/campaigns under a
--                            random name because WhatsApp fetches it by URL: it
--                            must be readable without a session.
--   campaign_recipients.contact_id
--                            who this row is, when it came from the registry
--                            rather than from a typed line. Kept so the same
--                            customer is never queued twice in one campaign and
--                            so a campaign can say who it actually reached.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'media_path');
SET @s := IF(@c = 0, 'ALTER TABLE campaigns ADD COLUMN media_path VARCHAR(255) NULL AFTER body', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'media_name');
SET @s := IF(@c = 0, 'ALTER TABLE campaigns ADD COLUMN media_name VARCHAR(190) NULL AFTER media_path', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'media_mime');
SET @s := IF(@c = 0, 'ALTER TABLE campaigns ADD COLUMN media_mime VARCHAR(100) NULL AFTER media_name', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'media_kind');
SET @s := IF(@c = 0, "ALTER TABLE campaigns ADD COLUMN media_kind ENUM('image','document') NULL AFTER media_mime", 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_recipients' AND COLUMN_NAME = 'contact_id');
SET @s := IF(@c = 0, 'ALTER TABLE campaign_recipients ADD COLUMN contact_id BIGINT UNSIGNED NULL AFTER campaign_id', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_recipients' AND INDEX_NAME = 'idx_cr_contact');
SET @s := IF(@c = 0, 'ALTER TABLE campaign_recipients ADD KEY idx_cr_contact (contact_id)', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
