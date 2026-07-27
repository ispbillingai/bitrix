-- 030_device_ip_per_area: a device IP is unique PER ROUTER, not globally.
--
-- Every customer sits behind its own router on its own private LAN, so
-- 192.168.100.13 exists at practically every shop — it is a different machine
-- each time. The original UNIQUE(ip) was global, so adding the second customer's
-- device failed with "a device with that IP already exists" even though the two
-- belong to different routers. Scope the uniqueness to (area_id, ip).
--
-- Note: MySQL treats NULLs as distinct in a unique index, so router-less devices
-- (only possible while no routers exist at all) are not blocked by each other —
-- the API already requires a router as soon as one is configured.
--
-- Guarded against information_schema so a re-run is harmless (ADD/DROP INDEX
-- have no IF [NOT] EXISTS form on MySQL).

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND INDEX_NAME = 'uniq_ip');
SET @sql := IF(@has > 0, 'ALTER TABLE devices DROP INDEX uniq_ip', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND INDEX_NAME = 'uniq_area_ip');
SET @sql := IF(@has = 0, 'ALTER TABLE devices ADD UNIQUE KEY uniq_area_ip (area_id, ip)', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- idx_area is now a redundant prefix of uniq_area_ip.
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND INDEX_NAME = 'idx_area');
SET @sql := IF(@has > 0, 'ALTER TABLE devices DROP INDEX idx_area', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
