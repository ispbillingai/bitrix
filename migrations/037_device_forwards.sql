-- 037_device_forwards: one-click remote access to a shop device's web page.
--
-- The CRM already reaches every customer's MikroTik over WireGuard. To open a
-- device's own web interface (fiscal printer, kiosk, Cashmatic…) a technician
-- had to build a dst-nat rule in Winbox by hand. This table is the CRM's record
-- of those rules: pick customer + device + a free port, and the panel writes
--
--   /ip firewall nat add chain=dstnat protocol=tcp in-interface=WIREGUARD \
--       dst-port=<dst_port> action=dst-nat to-addresses=<device ip> to-ports=<to_port>
--
-- on that customer's router, tagged with a CRM-NAT-<id> comment so we can find
-- it again. The Devices tab then shows a link to http://<router VPN ip>:<dst_port>.
--
-- url_path exists for the Cashmatic change machines: they answer on port 80 but
-- the login page only opens at /cws/loginform.php.

-- The WireGuard interface name the dst-nat rule matches on. It is "WIREGUARD"
-- everywhere today; per-router so a differently-named tunnel isn't a code change.
SET @add := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'network_areas' AND COLUMN_NAME = 'vpn_interface');
SET @sql := IF(@add = 0,
    "ALTER TABLE network_areas ADD COLUMN vpn_interface VARCHAR(64) NOT NULL DEFAULT 'WIREGUARD' AFTER host",
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS device_forwards (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_id        INT UNSIGNED NOT NULL,              -- router the rule lives on
    device_id      INT UNSIGNED NOT NULL,              -- device it points at
    dst_port       INT NOT NULL,                       -- port dialled on the router's VPN address
    to_port        INT NOT NULL DEFAULT 80,            -- port the device's web page answers on
    url_path       VARCHAR(190) NOT NULL DEFAULT '',   -- appended to the link, e.g. /cws/loginform.php
    protocol       VARCHAR(8) NOT NULL DEFAULT 'tcp',
    router_rule_id VARCHAR(32) NOT NULL DEFAULT '',    -- RouterOS internal .id (*1A) of the live rule
    status         ENUM('pending','active','error') NOT NULL DEFAULT 'pending',
    last_error     VARCHAR(255) NOT NULL DEFAULT '',
    last_synced_at DATETIME NULL,                      -- last time the rule was confirmed on the router
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- One rule per port per router: two dst-nat rules on the same port and the
    -- second one never fires.
    UNIQUE KEY uniq_area_port (area_id, dst_port),
    KEY idx_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
