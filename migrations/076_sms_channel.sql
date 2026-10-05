-- 076_sms_channel: SMS becomes a channel the CRM can send on (Skebby).
--
-- "Devo integrare questo servizio (messenger.skebby.it) nel CRM… per adesso lo
--  voglio usare solo per la verifica dei documenti in maniera esclusiva, poi
--  attraverso una spunta nelle impostazioni potrò scegliere anche per le
--  campagne pubblicitarie o altro."
--
-- So: the plumbing for SMS everywhere, switched on for one use. The enums are
-- widened now because that is the part a later tick cannot add by itself — the
-- settings that decide WHICH uses go by SMS live in `settings`, not here.
--
--   messages.channel    every SMS is logged in the outbox like the rest
--   reminders.channel   a queued notification can be addressed to SMS
--   campaigns.channel   ready for the tick that opens campaigns to SMS
--
-- MySQL: MODIFY is idempotent, so no information_schema guard is needed.

ALTER TABLE messages   MODIFY channel ENUM('whatsapp','email','sms') NOT NULL;
ALTER TABLE reminders  MODIFY channel ENUM('whatsapp','email','both','sms') NOT NULL DEFAULT 'both';
ALTER TABLE campaigns  MODIFY channel ENUM('whatsapp','email','sms') NOT NULL DEFAULT 'whatsapp';
