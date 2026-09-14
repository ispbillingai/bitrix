-- A payment reminder can carry a SmallPay link for the chased amount
-- (Settings → Sibill → "link per pagare online"). The customer row remembers
-- the one-off position it last issued, so the next reminder reuses the link
-- while it is unpaid and the amount unchanged, cancels it when the balance
-- moved, and the paid callback finds its way back to the chase
-- (Sibill\Customers::onChasePaid).

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sibill_customers' AND COLUMN_NAME = 'pay_contract_id');
SET @sql := IF(@add = 0,
    'ALTER TABLE sibill_customers ADD COLUMN pay_contract_id BIGINT UNSIGNED NULL AFTER reminders_sent',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sibill_customers' AND INDEX_NAME = 'idx_pay_contract');
SET @sql := IF(@idx = 0,
    'ALTER TABLE sibill_customers ADD KEY idx_pay_contract (pay_contract_id)',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
