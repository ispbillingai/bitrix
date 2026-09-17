-- 060_price_lists: sales price lists for the agents, drawn from the warehouse.
--
-- "Sales price lists accessible to agents, drawing directly from the CRM
--  inventory. A flag on each item's record enables the product for a given
--  price list. The catalogue page shows the enabled items like a shop: photo,
--  description, code, price. The photo links to a dedicated page or folder with
--  more information. The list can be exported or printed as a PDF."
--
--   price_lists       one row per list ("Listino rivenditori", "Listino fiera").
--                     Its prices come from the article itself (LISTINO or price
--                     list 4), optionally marked up or down by adjust_pct.
--   price_list_items  THE FLAG: an article is in a list when a row exists here.
--                     `price` is the list's own price for that article, when the
--                     office wants one; NULL = the warehouse price, adjusted.
--   article_media     the product's photos and documents (datasheets,
--                     manuals). Files live outside the web root.
--   articles.web_description / articles.info_url
--                     the product sheet: a longer text and the link the photo
--                     opens (a manufacturer page, a shared folder).
--
-- Everything here is CRM-owned. None of these columns is in the ARTICO
-- importer's column list, so the fifteen-minute re-import never clears them,
-- and setting them does NOT detach a gestionale article (its prices and
-- description keep following the file).
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

CREATE TABLE IF NOT EXISTS price_lists (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    description  VARCHAR(500) NULL,
    price_basis  ENUM('list','sale4') NOT NULL DEFAULT 'list',   -- LISTINO | Prezzo Ven. 4
    adjust_pct   DECIMAL(6,2) NOT NULL DEFAULT 0,                -- +10 = 10% above, -15 = 15% off
    vat_included TINYINT(1) NOT NULL DEFAULT 0,                  -- show prices VAT included
    visible      TINYINT(1) NOT NULL DEFAULT 1,                  -- agents can open it
    created_by   INT UNSIGNED NULL,
    updated_by   INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pl_visible (visible, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_list_items (
    list_id    INT UNSIGNED NOT NULL,
    article_id BIGINT UNSIGNED NOT NULL,
    price      DECIMAL(18,8) NULL,
    added_by   INT UNSIGNED NULL,
    added_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (list_id, article_id),
    KEY idx_pli_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_media (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id  BIGINT UNSIGNED NOT NULL,
    kind        ENUM('photo','file') NOT NULL,
    path        VARCHAR(80) NOT NULL,          -- stored name, random
    thumb_path  VARCHAR(80) NULL,              -- photos: the small copy the grid and the PDF use
    name        VARCHAR(190) NOT NULL,         -- what it was called when uploaded
    mime        VARCHAR(100) NULL,
    size_bytes  INT UNSIGNED NOT NULL DEFAULT 0,
    sort        INT NOT NULL DEFAULT 0,        -- lowest first; the first photo is the cover
    uploaded_by INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_am_article (article_id, kind, sort, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'web_description');
SET @sql := IF(@add = 0,
  'ALTER TABLE articles ADD COLUMN web_description TEXT NULL AFTER description', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'info_url');
SET @sql := IF(@add = 0,
  'ALTER TABLE articles ADD COLUMN info_url VARCHAR(500) NULL AFTER web_description', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
