-- 016_device_alert_phone: per-customer WhatsApp alert number.
--
-- When a device under a network area (customer) goes offline or comes back
-- online, the poller sends a WhatsApp message to this number via TextMeBot.
-- E.164 format, e.g. +393331234567. Blank = no alerts for that customer.

ALTER TABLE network_areas
    ADD COLUMN alert_phone VARCHAR(32) NOT NULL DEFAULT '' AFTER active;
