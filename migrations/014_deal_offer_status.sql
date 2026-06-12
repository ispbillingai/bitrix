-- Offer LED on the pipeline card: sent (yellow) -> downloaded (orange) ->
-- accepted (green), kept in sync by the ticket chat events.
-- MySQL has no ADD COLUMN IF NOT EXISTS, so guard via information_schema.

SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE deals ADD COLUMN offer_status VARCHAR(16) NULL AFTER sign_due_date',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deals' AND COLUMN_NAME = 'offer_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- New "Signature" stage between Negotiation and Won on every deal pipeline:
-- the deal lands here when the customer accepts the offer; OTP signing then
-- moves it to Won. Re-sort Won/Lost to make room.
UPDATE stages s JOIN pipelines p ON p.id = s.pipeline_id
SET s.sort = s.sort + 1
WHERE p.entity_type = 'deal' AND s.code IN ('WON','LOST')
  AND NOT EXISTS (SELECT 1 FROM (SELECT pipeline_id, code FROM stages) x
                  WHERE x.pipeline_id = s.pipeline_id AND x.code = 'SIGNATURE');

INSERT INTO stages (pipeline_id, code, name, sort, is_first, is_won, is_lost, color)
SELECT p.id, 'SIGNATURE', 'Signature',
       COALESCE((SELECT MAX(x.sort) FROM (SELECT pipeline_id, code, sort FROM stages) x
                 WHERE x.pipeline_id = p.id AND x.code = 'NEGOTIATION'), 2) + 1,
       0, 0, 0, '#1f9bb8'
FROM pipelines p
WHERE p.entity_type = 'deal'
  AND NOT EXISTS (SELECT 1 FROM stages s WHERE s.pipeline_id = p.id AND s.code = 'SIGNATURE');
