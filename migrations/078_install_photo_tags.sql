-- 078_install_photo_tags: a word or two on each installation photo.
--
-- "Mi permetti di aggiungere dei tag alle foto delle installazioni."
--
-- A product's gallery is one shop after another; a whole list's gallery is
-- dozens. Without a word on each photo the only way to find "the ones in a
-- tabaccheria" is to scroll. Tags are free text — the office knows what it
-- wants to call things better than any fixed list would — kept as a single
-- comma-separated string per photo, which is what a handful of short labels
-- actually needs.
--
-- They are shown on the public gallery too, and the page that edits them says
-- so: a tag is a caption, not a private note.
--
-- MySQL: no ADD COLUMN IF NOT EXISTS — guarded via information_schema.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'article_media' AND COLUMN_NAME = 'tags');
SET @s := IF(@c = 0, 'ALTER TABLE article_media ADD COLUMN tags VARCHAR(255) NULL AFTER name', 'DO 0');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
