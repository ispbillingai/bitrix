-- SmallPay routes a position to a payment channel by the SERVICE it is filed
-- under: the merchant now has one API service per gateway (API Nexi = card,
-- API SDD = SEPA direct debit). Nothing in the request picks a gateway, so the
-- choice IS the service id — and every later call about that position
-- (retry, cash, delete, cancel) signs with the same service. So the gateway a
-- contract was opened on has to be remembered, not re-derived from settings:
-- change the default tomorrow and yesterday's contracts must still be
-- maintainable.
ALTER TABLE payment_contracts
    ADD COLUMN gateway ENUM('card','sdd') NOT NULL DEFAULT 'card' AFTER kind;
