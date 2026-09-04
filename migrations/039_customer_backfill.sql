-- 039_customer_backfill: make the pre-existing CRM records ready for the first
-- gestionale import.
--
-- 038 added vat_number/is_customer to contacts, but every existing row has them
-- empty: VAT numbers were only ever stored on leads, and "is a customer" only
-- existed as a won deal. The import matches existing contacts BY contact VAT
-- (so a customer won through the pipeline adopts their gestionale code instead
-- of duplicating) — without this backfill it can never match anyone.
--
-- Data-only, re-run safe (every statement converges).

-- A contact takes the VAT from its leads, when the leads agree on exactly one.
UPDATE contacts c
JOIN (
    SELECT contact_id, MAX(vat_number) AS vat
    FROM leads
    WHERE contact_id IS NOT NULL AND vat_number IS NOT NULL AND vat_number <> ''
    GROUP BY contact_id
    HAVING COUNT(DISTINCT vat_number) = 1
) l ON l.contact_id = c.id
SET c.vat_number = l.vat
WHERE c.vat_number IS NULL OR c.vat_number = '';

-- Everyone who ever won a deal is a customer, since the day the deal was won.
UPDATE contacts c
JOIN (
    SELECT contact_id, MIN(COALESCE(stage_changed_at, created_at)) AS since
    FROM deals
    WHERE status = 'won' AND contact_id IS NOT NULL
    GROUP BY contact_id
) w ON w.contact_id = c.id
SET c.is_customer = 1,
    c.customer_since = COALESCE(c.customer_since, w.since);
