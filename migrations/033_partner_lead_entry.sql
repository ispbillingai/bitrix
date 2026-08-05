-- 033_partner_lead_entry: the partner area becomes a place to WORK, not just watch.
--
-- Three things the client asked for, in one migration's worth of schema:
--   1. a partner enters their own leads from their area (no schema change — a
--      partner-entered lead is just a lead with referred_by_partner_id set, the
--      same column the ?ref= link has always filled);
--   2. the partner sees only the STATUS of those leads (a read-side change);
--   3. the partner is messaged ONLY at the end of the road — when the lead is
--      closed (won) or lost. That is what needs schema: the reminders queue can
--      only address a customer, an agent or the logistics desk, so a partner was
--      not an addressable recipient at all.
--
-- Adding 'partner' to the enum is append-only: every existing row keeps its value
-- and nothing reads the enum positionally.

ALTER TABLE reminders
    MODIFY COLUMN recipient_type ENUM('customer','agent','logistics','partner') NOT NULL;
