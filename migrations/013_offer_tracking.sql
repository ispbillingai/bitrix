-- Offer flow: staff can start a conversation and send the offer file; we track
-- when the customer last saw the thread, when each attachment was downloaded,
-- and when the customer accepted an offer message.
-- MySQL has no ADD COLUMN IF NOT EXISTS, so guard via information_schema.

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE tickets ADD COLUMN customer_seen_at DATETIME NULL AFTER last_sender',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'customer_seen_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ticket_messages ADD COLUMN downloaded_at DATETIME NULL AFTER attachment_name',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'downloaded_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE ticket_messages ADD COLUMN accepted_at DATETIME NULL AFTER downloaded_at',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'accepted_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
