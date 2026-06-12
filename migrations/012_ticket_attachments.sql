-- Ticket messages can carry one file attachment (customer or staff side).
-- MySQL has no ADD COLUMN IF NOT EXISTS, so guard via information_schema.

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ticket_messages ADD COLUMN attachment_path VARCHAR(255) NULL AFTER body',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'attachment_path');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ticket_messages ADD COLUMN attachment_name VARCHAR(190) NULL AFTER attachment_path',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'attachment_name');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
