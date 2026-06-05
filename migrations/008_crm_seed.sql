-- 008_crm_seed: default Leads and Deals pipelines with Bitrix-like stages.
--
-- Stage `code`s match the config defaults the automation/cadences already use
-- (lead first = NEW, deal quote = QUOTE, deal won = WON) so reminders work out of
-- the box. Idempotent via ON DUPLICATE KEY UPDATE on the primary/unique keys, so
-- re-running never duplicates or overwrites operator edits.

INSERT INTO pipelines (id, entity_type, name, is_default, sort) VALUES
    (1, 'lead', 'Leads', 1, 0),
    (2, 'deal', 'Deals', 1, 0)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO stages (pipeline_id, code, name, sort, is_first, is_won, is_lost, color) VALUES
    (1, 'NEW',        'New',          0, 1, 0, 0, '#5b6cff'),
    (1, 'CONTACTED',  'Contacted',    1, 0, 0, 0, '#d9a40a'),
    (1, 'QUALIFIED',  'Qualified',    2, 0, 0, 0, '#3fb868'),
    (1, 'CONVERTED',  'Converted',    3, 0, 1, 0, '#3fb868'),
    (1, 'JUNK',       'Junk',         4, 0, 0, 1, '#e5616e'),
    (2, 'NEW',        'New',          0, 1, 0, 0, '#5b6cff'),
    (2, 'QUOTE',      'Quote sent',   1, 0, 0, 0, '#d9a40a'),
    (2, 'NEGOTIATION','Negotiation',  2, 0, 0, 0, '#7c5cff'),
    (2, 'WON',        'Won',          3, 0, 1, 0, '#3fb868'),
    (2, 'LOST',       'Lost',         4, 0, 0, 1, '#e5616e')
ON DUPLICATE KEY UPDATE id = id;
