-- 080_contracts: a customer has CONTRACTS, not a contract.
--
-- "Un cliente deve poter avere un elenco di contratti indipendenti tra loro…
--  ogni contratto deve poter essere associato a uno o più dispositivi specifici
--  (hardware/totem/casse)… Nome/Tipo, Importo, Modalità di pagamento,
--  Dispositivi associati… una tabella riepilogativa nella scheda cliente."
--
-- Until now the answer to "what are we to this customer?" was a single one,
-- resolved by Crm\Maintenance from three half-sources (the office's own fields
-- on `contacts`, a live SmallPay subscription, the gestionale's expiry date).
-- That holds for a bar with one till. It does not hold for the customer with an
-- H24 contract on the two Cashmatic machines and a basic one on the fiscal
-- printer, billed separately and paid by different means.
--
--   contracts          one row per contract, independent of the others
--   contract_devices   which machines that contract covers (devices.id)
--
-- `contacts.maint_*` and `contract_expiry` are NOT touched: they stay as the
-- fallback for the ~596 customers whose only contract is the gestionale's date,
-- and Maintenance::forContact() now reads a real contract first when there is
-- one. Nothing is migrated automatically — a contract is a commercial fact and
-- the office enters it, rather than the CRM guessing one from an expiry date.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS anywhere; tables guarded by IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS contracts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id      BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(120)    NOT NULL,              -- "Assistenza Base", "Full Risk", "H24"
    amount_cents    INT UNSIGNED        NULL,              -- the canone agreed for THIS contract
    currency        CHAR(3)         NOT NULL DEFAULT 'EUR',
    period          ENUM('month','quarter','semester','year','one_off') NOT NULL DEFAULT 'year',
    payment_method  ENUM('sdd','transfer','card','cash','other') NOT NULL DEFAULT 'transfer',
    started_on      DATE                NULL,
    expires_on      DATE                NULL,              -- empty = open-ended
    status          ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    notes           VARCHAR(500)        NULL,
    created_by      INT UNSIGNED        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_contract_contact (contact_id, status),
    KEY ix_contract_expiry (expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which machines this contract covers. A device may be on more than one
-- contract over time, so the pair is unique, not the device.
CREATE TABLE IF NOT EXISTS contract_devices (
    contract_id BIGINT UNSIGNED NOT NULL,
    device_id   INT UNSIGNED    NOT NULL,
    added_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (contract_id, device_id),
    KEY ix_cd_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
