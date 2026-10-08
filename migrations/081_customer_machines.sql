-- 081_customer_machines: the customer's own machines, entered by hand.
--
-- "Le macchine non sono queste, me le farai aggiungere manualmente attraverso
--  un form con marca, modello, numero di serie e varie."
--
-- Migration 080 hung a contract on `devices`, which is the NETWORK side of the
-- house: the tills and tablets the CRM pings through a shop's MikroTik, named
-- for what they do on the LAN ("TAB 7", "PRECONTO"). That is not the machine a
-- contract covers. A contract covers a Cashmatic 1060 with serial
-- 2026 26 014 04 00304, and nobody can tell that from a hostname.
--
-- So the machines get their own registry, one row per physical thing, typed in
-- by the office: brand, model, serial, what kind it is, where it stands, when
-- it was installed. The serial is what makes it that machine and not another.
--
--   customer_machines   a customer's hardware
--   contract_machines   which of it a contract covers (replaces contract_devices)
--
-- contract_devices is dropped rather than kept: it was one day old and never
-- held a row — the device picker it belonged to is what the client is correcting
-- here. Should it hold rows on some other install, the DROP is the only
-- destructive line in this file and the rest stands without it.
--
-- MySQL: tables guarded with IF NOT EXISTS; no ADD COLUMN IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS customer_machines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id        BIGINT UNSIGNED NOT NULL,
    kind              VARCHAR(40)         NULL,      -- cassa automatica, stampante fiscale, totem…
    brand             VARCHAR(60)         NULL,      -- Cashmatic, Epson, Berkel…
    model             VARCHAR(80)         NULL,      -- 1060, SELFPAY 460, FP-81 II
    serial            VARCHAR(80)         NULL,      -- what makes it THAT machine
    label             VARCHAR(80)         NULL,      -- what the shop calls it: "Cassa 1"
    location          VARCHAR(120)        NULL,      -- where it stands: sala, bar, magazzino
    installed_on      DATE                NULL,
    status            ENUM('active','dismissed') NOT NULL DEFAULT 'active',
    notes             VARCHAR(500)        NULL,
    install_report_id BIGINT UNSIGNED     NULL,      -- when it was taken from a technician's report
    created_by        INT UNSIGNED        NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_machine_contact (contact_id, status),
    KEY ix_machine_serial (serial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contract_machines (
    contract_id BIGINT UNSIGNED NOT NULL,
    machine_id  BIGINT UNSIGNED NOT NULL,
    added_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (contract_id, machine_id),
    KEY ix_cm_machine (machine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS contract_devices;
