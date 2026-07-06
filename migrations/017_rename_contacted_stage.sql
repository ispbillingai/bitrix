-- 017_rename_contacted_stage: rename the lead pipeline's "Contacted" stage to
-- "In Contact" (customer request). Same stage/code (CONTACTED) — label only.
-- Only rename if it still holds the seeded default name, so an operator's own
-- custom rename is never overwritten.

UPDATE stages
   SET name = 'In Contact'
 WHERE code = 'CONTACTED'
   AND name = 'Contacted';
