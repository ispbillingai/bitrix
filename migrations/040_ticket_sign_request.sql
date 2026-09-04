-- 040_ticket_sign_request: a quote sent in the chat can carry a signature
-- request.
--
-- The agent attaches the PDF in the ticket reply bar and ticks "request
-- signature": instead of a plain attachment the message references a
-- sign_documents row (created + sent through the existing in-house signing
-- flow). The chat then renders the live signing state on the bubble — sent,
-- viewed, signed — on both sides, and the customer gets a "sign it" button in
-- the portal pointing at the tokenised signing page. One column is the whole
-- schema change; the file itself lives in sign storage, not ticket storage.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'sign_document_id');
SET @sql := IF(@add = 0, 'ALTER TABLE ticket_messages ADD COLUMN sign_document_id BIGINT UNSIGNED NULL AFTER attachment_name', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND INDEX_NAME = 'idx_msg_sign_doc');
SET @sql := IF(@idx = 0, 'ALTER TABLE ticket_messages ADD KEY idx_msg_sign_doc (sign_document_id)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
