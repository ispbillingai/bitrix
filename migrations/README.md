# Database Migrations

Versioned SQL changes applied by [`../migrate.php`](../migrate.php). Add a new
numbered file, commit, pull on the server, and run `php migrate.php` — it applies
only the files it hasn't seen and records each in a `migrations` table.

## Adding a change

1. Create the next-numbered file:
   ```
   002_add_lead_source_column.sql
   003_appointments_cache.sql
   ```
   Files run in ascending order.

2. Prefer idempotent statements so accidental re-runs are safe.

   `CREATE TABLE IF NOT EXISTS` is fine. But **`ADD COLUMN IF NOT EXISTS` is
   MariaDB-only and errors on MySQL** (what we run everywhere). For columns,
   guard against `information_schema` instead — see `002_entity_lang.sql`:
   ```sql
   SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'tracked_entities' AND COLUMN_NAME = 'lead_source');
   SET @sql := IF(@add = 0,
       'ALTER TABLE tracked_entities ADD COLUMN lead_source VARCHAR(64) NULL', 'DO 0');
   PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
   ```

3. Commit & push, then on the server:
   ```bash
   cd /var/www/html/bitrix-glue
   git pull origin main
   php migrate.php
   ```

## Running

| Where | Command |
|-------|---------|
| Server CLI (recommended) | `php migrate.php` |
| Preview without applying | `php migrate.php --dry-run` |
| Browser (one-off)        | `https://yoursite/migrate.php?key=YOUR_SECRET` |

The web key comes from `app.migrate_key` in `config/config.php`. In production,
prefer the CLI and block this endpoint from the web.
