-- 015_devices: network device up/down monitoring + disconnection log.
--
-- The CRM server reaches the shop's MikroTik router(s) over WireGuard. Because
-- the router's own /tool fetch can't POST out, the server PULLS: bin/poll-devices
-- logs into each router's RouterOS API and pings its devices, recording status
-- here. Two dashboard tabs (Devices, Network areas) show this to admins and to
-- the technical-area users. device_events is the disconnection log — one row per
-- state change (up->down / down->up), so you can see outage history over time.
-- CREATE TABLE IF NOT EXISTS + idempotent seed — safe to re-run.

CREATE TABLE IF NOT EXISTS network_areas (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    host        VARCHAR(100) NOT NULL,          -- router address (over WireGuard)
    api_port    INT NOT NULL DEFAULT 8728,      -- RouterOS API (8728 plain / 8729 SSL)
    api_user    VARCHAR(100) NOT NULL DEFAULT 'admin',
    api_pass    VARCHAR(255) NOT NULL DEFAULT '',
    ping_count  INT NOT NULL DEFAULT 2,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    ip              VARCHAR(45) NOT NULL,
    area_id         INT UNSIGNED NULL DEFAULT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    status          ENUM('up','down','unknown') NOT NULL DEFAULT 'unknown',
    latency_ms      DECIMAL(8,2) NULL,
    last_seen_at    DATETIME NULL,        -- last time it answered a ping
    last_checked_at DATETIME NULL,        -- last time the poller reported on it
    active          TINYINT(1) NOT NULL DEFAULT 1,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ip (ip),
    KEY idx_area (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Disconnection log: append one row whenever a device changes state.
CREATE TABLE IF NOT EXISTS device_events (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id  INT UNSIGNED NOT NULL,
    event_type ENUM('down','up') NOT NULL,     -- down = went offline, up = came back
    latency_ms DECIMAL(8,2) NULL,              -- latency on recovery (up events)
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_device_time (device_id, id),
    KEY idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the router we already have (password blank — set it in the UI / on server).
INSERT INTO network_areas (name, host, api_port, api_user, api_pass, ping_count, sort_order)
SELECT 'Panificio Azzurro', '192.168.200.15', 8728, 'admin', '', 2, 10
WHERE NOT EXISTS (SELECT 1 FROM network_areas);

-- Seed the shop devices, attached to that first area.
INSERT INTO devices (name, ip, area_id, sort_order) VALUES
    ('Order',          '192.168.100.10', (SELECT id FROM network_areas ORDER BY sort_order, id LIMIT 1), 10),
    ('Fiscal printer', '192.168.100.11', (SELECT id FROM network_areas ORDER BY sort_order, id LIMIT 1), 20),
    ('Cashier PC',     '192.168.100.12', (SELECT id FROM network_areas ORDER BY sort_order, id LIMIT 1), 30),
    ('Cashmatic',      '192.168.100.13', (SELECT id FROM network_areas ORDER BY sort_order, id LIMIT 1), 40),
    ('POS',            '192.168.100.14', (SELECT id FROM network_areas ORDER BY sort_order, id LIMIT 1), 50)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order);
