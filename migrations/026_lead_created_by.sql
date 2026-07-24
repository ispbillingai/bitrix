-- 026_lead_created_by: who actually typed the lead in.
--
-- `assigned_to` says who works the lead, not who entered it. A lead the admin
-- created and then assigned to a seller looked identical to one the seller keyed
-- in himself: same card, same name. The only record was the "Lead created from X"
-- timeline row, which nobody sees without opening the lead.
--
-- NULL = not entered by a logged-in user, i.e. genuinely inbound (public request
-- form, the shareable fair form, the partner API, the website webhook — all call
-- Leads::create with no actor).
-- (MySQL: no ADD COLUMN IF NOT EXISTS — the migrations table keeps this re-run safe.)

ALTER TABLE leads
    ADD COLUMN created_by INT UNSIGNED NULL DEFAULT NULL AFTER assigned_to,
    ADD KEY idx_created_by (created_by);

-- Backfill the history: the "Lead created from ..." activity already carries the
-- actor for every lead ever entered from the panel, so nothing is lost.
UPDATE leads l
  JOIN activities a
    ON a.entity_type = 'lead'
   AND a.entity_id   = l.id
   AND a.type        = 'system'
   AND a.body     LIKE 'Lead created from %'
   AND a.user_id  IS NOT NULL
   SET l.created_by = a.user_id
 WHERE l.created_by IS NULL;
