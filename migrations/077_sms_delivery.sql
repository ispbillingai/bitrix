-- 077_sms_delivery: what became of an SMS after it left.
--
-- Skebby calls back with the delivery report the client pasted:
--   GET …?delivery_date=20210204211100&order_id=…&recipient=%2B39…&status=DLVRD
--
-- "sent" only ever meant "the gateway took it". For a verification code that is
-- not the question — the question is whether it reached the phone — and this is
-- the only channel that can answer it.
--
--   provider_ref      the gateway's own id for the send (Skebby's order_id),
--                     which is what a report refers to
--   delivery_status   the word the operator used (DLVRD, UNDELIV, EXPIRED…)
--   delivered_at      when the report arrived
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'provider_ref');
SET @s := IF(@c = 0, 'ALTER TABLE messages ADD COLUMN provider_ref VARCHAR(64) NULL AFTER provider_response', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'delivery_status');
SET @s := IF(@c = 0, 'ALTER TABLE messages ADD COLUMN delivery_status VARCHAR(24) NULL AFTER provider_ref', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'delivered_at');
SET @s := IF(@c = 0, 'ALTER TABLE messages ADD COLUMN delivered_at DATETIME NULL AFTER delivery_status', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND INDEX_NAME = 'idx_provider_ref');
SET @s := IF(@i = 0, 'ALTER TABLE messages ADD KEY idx_provider_ref (provider_ref)', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
