-- 046_articles: the article registry — the gestionale's ARTICO export, ~7,600
-- catering and refrigeration items (machines, spare parts, consumables).
--
-- The key is `code` (the export's "Codice"): unique on every row shipped so
-- far. It is deliberately NOT the export's "ID" column (a literal "+" on every
-- row) and NOT the barcode (shared between articles — compatible consumables
-- carry the maker's barcode). The unnamed numeric column next to ID looks like
-- the gestionale's internal id; kept as gest_num for reference only.
--
-- Every field is gestionale-owned: the file is a full snapshot and a re-import
-- converges the whole row. Nothing in the CRM edits articles.
--
-- Prices are DECIMAL(18,8): the export carries cost prices with up to 8
-- decimals, and rounding them would drift the stock-value control total the
-- import is verified against. One list price in the file is a fat-fingered
-- ~1.7 billion — stored as exported; the registry mirrors the file, faults
-- included.

CREATE TABLE IF NOT EXISTS articles (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(64) NOT NULL,               -- Codice: the import key
    gest_num          INT UNSIGNED NULL,                  -- unnamed numeric column in the export
    barcode           VARCHAR(64) NULL,                   -- NOT unique: 9 are shared between articles
    description       VARCHAR(190) NULL,                  -- Descrizione (8 rows ship without one)
    list_price        DECIMAL(18,8) NOT NULL DEFAULT 0,   -- LISTINO
    cost_price        DECIMAL(18,8) NOT NULL DEFAULT 0,   -- Prezzo Acq.
    sale_price4       DECIMAL(18,8) NULL,                 -- Prezzo Ven. 4 (net)
    sale_price4_gross DECIMAL(18,8) NULL,                 -- Prezzo Ivato 4 (incl. VAT)
    vat_rate          DECIMAL(5,2) NULL,                  -- IVA (%)
    location          VARCHAR(64) NULL,                   -- Ubicazione (warehouse slot)
    category          VARCHAR(120) NULL,                  -- Classe Merc.
    subcategory       VARCHAR(120) NULL,                  -- Sotto classe
    group_code        VARCHAR(32) NULL,                   -- Codice Gruppo
    supplier_gest_id  INT UNSIGNED NULL,                  -- ID Fornitore (0 = none)
    supplier          VARCHAR(190) NULL,                  -- Fornitore
    supplier_code1    VARCHAR(64) NULL,                   -- Cod. Fornitore 1
    supplier_code2    VARCHAR(64) NULL,                   -- Cod. Fornitore 2
    stock_initial     DECIMAL(12,2) NOT NULL DEFAULT 0,   -- Giacenza Iniziale
    stock             DECIMAL(12,2) NOT NULL DEFAULT 0,   -- Esistenza (can go negative)
    stock_ordered     DECIMAL(12,2) NOT NULL DEFAULT 0,   -- Ordinato
    stock_available   DECIMAL(12,2) NOT NULL DEFAULT 0,   -- Disponibile
    has_serials       TINYINT(1) NOT NULL DEFAULT 0,      -- Matricole (Vero/Falso)
    has_image         TINYINT(1) NOT NULL DEFAULT 0,      -- Immagine column says BLOB
    last_movement     DATE NULL,                          -- Ult. Data Mov.
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_article_code (code),
    KEY idx_article_barcode (barcode),
    KEY idx_article_category (category),
    KEY idx_article_supplier (supplier),
    KEY idx_article_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Import ledger, same shape and purpose as customer_imports: one row per file
-- ever ingested, keyed by content hash, so a drop directory can be rescanned
-- by cron forever and a file already taken is simply skipped.
CREATE TABLE IF NOT EXISTS article_imports (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename    VARCHAR(190) NOT NULL,
    sha256      CHAR(64) NOT NULL,
    rows_total  INT UNSIGNED NOT NULL DEFAULT 0,
    created_n   INT UNSIGNED NOT NULL DEFAULT 0,
    updated_n   INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_n   INT UNSIGNED NOT NULL DEFAULT 0,
    note        VARCHAR(255) NULL,
    imported_by INT UNSIGNED NULL,               -- users.id; NULL = CLI/cron
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_file_hash (sha256),
    KEY idx_imported (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
