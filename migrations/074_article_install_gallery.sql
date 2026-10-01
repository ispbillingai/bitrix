-- 074_article_install_gallery: a folder of installation photos per product, and
-- the link that shows them as a gallery.
--
-- "Nei listini per ogni prodotto crea un repository, ossia una cartella nella
--  quale posso caricare tutte le foto delle installazioni relative a quel
--  prodotto, e mi crei un link che mi permette di visualizzare la galleria."
--
-- The product sheet already stores photos and documents in article_media; the
-- installation photos are the same kind of thing with a different purpose, so
-- they are a third `kind` rather than a second table — same upload pipeline
-- (upright, bounded, JPEG on white, with a small copy), same streaming, same
-- deletion.
--
-- articles.gallery_token is the credential of the public page, exactly like the
-- signing and survey links: the token IS the permission. It is minted when the
-- first installation photo is uploaded, and the office can mint a new one
-- (killing the old link) or take the link away entirely.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

ALTER TABLE article_media MODIFY kind ENUM('photo','file','install') NOT NULL;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND COLUMN_NAME = 'gallery_token');
SET @s := IF(@c = 0, 'ALTER TABLE articles ADD COLUMN gallery_token CHAR(32) NULL AFTER info_url', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'articles' AND INDEX_NAME = 'uq_gallery_token');
SET @s := IF(@i = 0, 'ALTER TABLE articles ADD UNIQUE KEY uq_gallery_token (gallery_token)', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
