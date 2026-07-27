-- 029_invoice_chase_flag: per-invoice control over chasing.
--
-- chase_excluded keeps one specific invoice out of the AUTOMATIC reminders and
-- the whole-customer "remind now" — a disputed invoice, or one already being
-- handled another way — while the customer's other overdue invoices are still
-- chased normally. Set from the customer panel on the Invoices page.
--
-- Staff-owned, like the contact columns on sibill_customers: the invoice sync
-- rewrites every other field but never touches this one (it is absent from the
-- upsert's UPDATE clause), so an exclusion survives the next sync.
-- (MySQL: no ADD COLUMN IF NOT EXISTS — the migrations table keeps this re-run safe.)

ALTER TABLE sibill_invoices
    ADD COLUMN chase_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER pay_state,
    ADD KEY idx_chase_excluded (chase_excluded);
