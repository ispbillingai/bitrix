-- 075_pricelist_public_links: two public addresses for a whole price list.
--
-- "Mi fai un link galleria anche per l'intero listino" — and, asked which of
-- the two things that could mean, the office answered both, as separate links:
--
--   gallery_token  every installation photo of the products in the list,
--                  grouped by product. No prices.
--   catalog_token  the catalogue as the CRM shows it, PRICES INCLUDED, open to
--                  whoever holds the link.
--
-- Two tokens and not one, because they are two different decisions: a gallery
-- can go to anybody, a price list is a quotation. Either can be minted, re-minted
-- (which kills the address already given out) or taken away on its own.
--
-- Same shape as a product's gallery_token (migration 074): the token IS the
-- permission, and the public page refuses anything else.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'price_lists' AND COLUMN_NAME = 'gallery_token');
SET @s := IF(@c = 0, 'ALTER TABLE price_lists ADD COLUMN gallery_token CHAR(32) NULL', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'price_lists' AND COLUMN_NAME = 'catalog_token');
SET @s := IF(@c = 0, 'ALTER TABLE price_lists ADD COLUMN catalog_token CHAR(32) NULL', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'price_lists' AND INDEX_NAME = 'uq_pl_gallery_token');
SET @s := IF(@i = 0, 'ALTER TABLE price_lists ADD UNIQUE KEY uq_pl_gallery_token (gallery_token)', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'price_lists' AND INDEX_NAME = 'uq_pl_catalog_token');
SET @s := IF(@i = 0, 'ALTER TABLE price_lists ADD UNIQUE KEY uq_pl_catalog_token (catalog_token)', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
