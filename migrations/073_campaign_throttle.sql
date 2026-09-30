-- 073_campaign_throttle: the wait between one campaign message and the next,
-- set per campaign.
--
-- "Mi fai impostare anche il ritardo tra un messaggio ed il successivo."
--
-- The pace already existed as textmebot.campaign_throttle_seconds, but only in
-- the config: the office could not see it, let alone change it. It is now a
-- setting (Impostazioni → WhatsApp) AND a field on each campaign, because the
-- right pace is not the same for ten customers and for ten thousand — WhatsApp
-- bans a number that sends too fast.
--
--   NULL  use the setting, whatever it says at the time the batch runs
--   n     this campaign waits n seconds between messages
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'throttle_seconds');
SET @s := IF(@c = 0, 'ALTER TABLE campaigns ADD COLUMN throttle_seconds SMALLINT UNSIGNED NULL AFTER media_kind', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
