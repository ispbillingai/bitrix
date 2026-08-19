-- 034_reminder_dispatch_lock: stop the same reminder being delivered twice.
--
-- claimForDispatch() used an optimistic lock on `attempts`, which only breaks a
-- tie between two dispatchers that read the SAME attempts value. A reminder stays
-- status='pending' for the WHOLE send (TextMeBot sleeps out its 8s rate-limit gap
-- before the call), so the every-minute cron routinely SELECTed a row the web
-- request had already claimed a second earlier, re-claimed it with the new
-- attempts, and sent the message a second time — the customer's welcome /
-- agent-assigned / portal-invite WhatsApp arriving twice.
--
-- locked_at is a dispatch lease: the claim is now conditional on the row's own
-- current state, not on a value the caller read, so a second dispatcher can never
-- claim a send already in flight. The lease expires (see Scheduler::DISPATCH_LEASE_SEC)
-- so a dispatcher that dies mid-send doesn't strand the reminder forever.
-- Guarded so re-runs are no-ops on MySQL (no MariaDB-only IF NOT EXISTS).

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reminders'
                   AND COLUMN_NAME = 'locked_at');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE reminders ADD COLUMN locked_at DATETIME NULL DEFAULT NULL AFTER attempts',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
