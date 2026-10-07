-- 079_pec: certified email (PEC), and the receipts that make it certified.
--
-- "Puoi gestire una mail PEC? Ho bisogno di inviare una mail certificata nel
--  caso il cliente non paghi una rata di assistenza, ad esempio."
--
-- A PEC is ordinary SMTP to the provider's server; what it is worth in front of
-- a judge is the pair of receipts that come BACK into the same mailbox —
-- accettazione (the provider took it) and consegna (it reached the other PEC,
-- with the message itself sealed inside). A sent PEC nobody collected the
-- receipts for proves nothing, so the receipts are stored, matched to their
-- message, and shown beside it.
--
--   messages.channel          gains 'pec': a sollecito is in the outbox like
--                             every other thing the CRM sends
--   messages.provider_ref     already exists — it holds our Message-ID, which
--                             is what a receipt refers back to
--   pec_receipts              one row per receipt, with the .eml kept on disk
--
-- The customer's PEC address is NOT new: contacts.pec already arrives with the
-- CLIENTI.xlsx import ("Email Pec") and is editable on the customer card.
--
-- MySQL: MODIFY is idempotent; the table is guarded by IF NOT EXISTS.

ALTER TABLE messages MODIFY channel ENUM('whatsapp','email','sms','pec') NOT NULL;

CREATE TABLE IF NOT EXISTS pec_receipts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id    BIGINT UNSIGNED NULL,            -- the outbox row it belongs to
    kind          ENUM('accettazione','consegna','errore','preavviso','altro') NOT NULL,
    ref_msgid     VARCHAR(255) NULL,               -- Message-ID of the PEC it refers to
    recipient     VARCHAR(190) NULL,               -- who the original went to
    subject       VARCHAR(255) NULL,
    from_addr     VARCHAR(190) NULL,
    received_at   DATETIME NULL,                   -- the time the receipt itself carries
    uid           VARCHAR(190) NOT NULL,           -- the mailbox message key, so a poll never doubles
    eml_path      VARCHAR(190) NULL,               -- the receipt kept whole, signature and all
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pec_uid (uid),
    KEY ix_pec_msg (message_id),
    KEY ix_pec_ref (ref_msgid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
