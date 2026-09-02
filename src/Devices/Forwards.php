<?php
declare(strict_types=1);

namespace Glue\Devices;

use Glue\Db;
use RuntimeException;
use Throwable;

/**
 * Remote access to a shop device's own web page.
 *
 * Every customer sits behind a MikroTik we already reach over WireGuard, and
 * every shop reuses the same private LAN (192.168.100.x), so a device is only
 * addressable through its own router. This class writes the dst-nat rule that
 * publishes one device's web port on the router's VPN address:
 *
 *   chain=dstnat protocol=tcp in-interface=WIREGUARD dst-port=81
 *   action=dst-nat to-addresses=192.168.100.3 to-ports=80
 *
 * and the panel then links to http://<router VPN ip>:81. Same pattern as the AP
 * front-door rules in the ISP billing app: the rule carries a CRM-NAT-<id>
 * comment, which is how we find ours again on a router that also holds
 * hand-made rules — we never touch a rule we did not write.
 *
 * The router is the source of truth for whether a rule is live: every save is
 * pushed immediately, and a push that fails (VPN blip, wrong password) leaves
 * the row in 'error' carrying the router's own message, retryable from the UI.
 */
final class Forwards
{
    /** Interface the request arrives on. Overridable per router. */
    public const DEFAULT_INTERFACE = 'WIREGUARD';

    /** Device-side port. Practically every device's web page answers on 80. */
    public const DEFAULT_TO_PORT = 80;

    /** Cashmatic change machines answer on 80 but only open at this path. */
    public const CASHMATIC_PATH = '/cws/loginform.php';

    /** First port offered when suggesting a free one (the client's own example). */
    public const PORT_MIN = 81;

    /**
     * Ports on the ROUTER we refuse to steal: dst-nat'ing one of these would
     * redirect the router's own management traffic to a shop device and lock us
     * out of the very box we would need to undo it.
     */
    private const RESERVED_PORTS = [21, 22, 23, 53, 80, 443, 1723, 2000, 8080, 8291, 8728, 8729, 13231, 51820];

    /** Comment we stamp on every rule we create. */
    public static function tag(int $id): string
    {
        return 'CRM-NAT-' . $id;
    }

    // ---- reads ---------------------------------------------------------

    /**
     * Every forward with its router and device joined in, ready to list.
     * Each row carries a computed 'url' — what the Open button points at.
     */
    public static function all(): array
    {
        $rows = Db::pdo()->query(
            "SELECT f.*, a.name AS area_name, a.host AS area_host, a.vpn_interface,
                    d.name AS device_name, d.ip AS device_ip, d.status AS device_status
               FROM device_forwards f
               JOIN network_areas a ON a.id = f.area_id
               JOIN devices d       ON d.id = f.device_id
              ORDER BY a.sort_order, a.id, f.dst_port"
        )->fetchAll() ?: [];
        foreach ($rows as &$r) {
            $r['url'] = self::url($r);
        }
        return $rows;
    }

    /**
     * Forwards keyed by device id, for the link column on the Devices tab.
     * @return array<int,array<int,array>>
     */
    public static function byDevice(): array
    {
        $out = [];
        foreach (self::all() as $r) {
            $out[(int)$r['device_id']][] = $r;
        }
        return $out;
    }

    /** Ports already taken, keyed by router id — the UI suggests the next free one. */
    public static function usedPorts(): array
    {
        $out = [];
        foreach (Db::pdo()->query("SELECT area_id, dst_port FROM device_forwards") as $r) {
            $out[(int)$r['area_id']][] = (int)$r['dst_port'];
        }
        return $out;
    }

    /** Ports we will not hand out (router services). */
    public static function reservedPorts(): array
    {
        return self::RESERVED_PORTS;
    }

    /**
     * Ports one router's OWN dst-nat rules already answer on — read-only.
     *
     * The customers' routers came with hand-made forwards (Cisbu Mugnano was
     * already using 81, La Scogliera a dozen more), so a port that looks free
     * in our table is often taken on the box. The form asks for this before
     * suggesting one; a router we cannot reach just falls back to our own
     * records, and the save-time check still guards it.
     *
     * @return array{ok:bool,ports:int[],error?:string}
     */
    public static function routerPorts(int $areaId): array
    {
        $stmt = Db::pdo()->prepare("SELECT * FROM network_areas WHERE id = ?");
        $stmt->execute([$areaId]);
        $area = $stmt->fetch();
        if (!$area) {
            return ['ok' => false, 'ports' => [], 'error' => 'not_found'];
        }

        try {
            $api = Monitor::connect([
                'host' => (string)$area['host'], 'port' => (int)$area['api_port'],
                'user' => (string)$area['api_user'], 'pass' => (string)$area['api_pass'],
            ]);
            try {
                $configured = self::iface(['vpn_interface' => $area['vpn_interface'] ?? '']);
                $iface = self::resolveInterface($api, (string)$area['host'], $configured);
                self::rememberInterface($areaId, $iface, $configured);
                $rules = $api->command('/ip/firewall/nat/print');
            } finally {
                $api->close();
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'ports' => [], 'error' => $e->getMessage()];
        }

        $ports = [];
        foreach ($rules as $rule) {
            if (($rule['chain'] ?? '') !== 'dstnat' || ($rule['disabled'] ?? 'false') === 'true') {
                continue;
            }
            $ruleIface = (string)($rule['in-interface'] ?? '');
            if ($ruleIface !== '' && $ruleIface !== $iface) {
                continue; // arrives somewhere else entirely — not our lane
            }
            foreach (self::expandPorts((string)($rule['dst-port'] ?? '')) as $p) {
                $ports[$p] = true;
            }
        }
        ksort($ports);
        return ['ok' => true, 'ports' => array_keys($ports)];
    }

    /**
     * RouterOS writes dst-port as a single port, a comma list, or a range
     * ("81", "81,90", "8000-8010"). Expand to the ports it actually covers.
     * Ranges are capped so a "1-65535" rule cannot blow up memory.
     *
     * @return int[]
     */
    private static function expandPorts(string $spec): array
    {
        $out = [];
        foreach (explode(',', $spec) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (strpos($part, '-') !== false) {
                [$from, $to] = array_map('intval', explode('-', $part, 2));
                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }
                $to = min($to, $from + 4096);
                for ($p = max(1, $from); $p <= $to && $p <= 65535; $p++) {
                    $out[] = $p;
                }
            } elseif (ctype_digit($part)) {
                $out[] = (int)$part;
            }
        }
        return $out;
    }

    /**
     * Lowest port from 81 up that is free on this router: not a router service,
     * not used by another access of ours, and not already forwarded by the
     * customer themselves. Reading the box is best-effort — an unreachable
     * router just falls back to our own records, and apply() still refuses a
     * clash. $exceptId lets a forward keep its own port while being edited.
     */
    public static function suggestPort(int $areaId, int $exceptId = 0): int
    {
        $taken = [];
        $stmt = Db::pdo()->prepare("SELECT dst_port FROM device_forwards WHERE area_id = ? AND id <> ?");
        $stmt->execute([$areaId, $exceptId]);
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $taken[(int)$r['dst_port']] = true;
        }
        foreach (self::routerPorts($areaId)['ports'] as $p) {
            $taken[$p] = true;
        }
        foreach (self::RESERVED_PORTS as $p) {
            $taken[$p] = true;
        }
        for ($p = self::PORT_MIN; $p <= 65535; $p++) {
            if (!isset($taken[$p])) {
                return $p;
            }
        }
        return 0;
    }

    /** Cashmatic change machines answer on 80 but only open at CASHMATIC_PATH. */
    public static function isCashmatic(string $deviceName): bool
    {
        return (bool)preg_match('/cash\s*matic/i', $deviceName);
    }

    /** http://<router VPN address>:<port><path> — the link a technician clicks. */
    public static function url(array $row): string
    {
        $host = trim((string)($row['area_host'] ?? ''));
        if ($host === '') {
            return '';
        }
        // A bare IPv6 literal needs brackets in a URL.
        if (strpos($host, ':') !== false && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        return 'http://' . $host . ':' . (int)$row['dst_port'] . (string)$row['url_path'];
    }

    // ---- writes --------------------------------------------------------

    /**
     * Create or update a forward and push it to the router.
     *
     * The row is written first and the rule pushed second, so a router that is
     * unreachable right now still leaves a retryable record instead of losing
     * what the technician typed.
     *
     * @return array{ok:bool,id?:int,error?:string,status?:string,url?:string}
     */
    public static function save(array $in): array
    {
        $pdo = Db::pdo();

        $id       = (int)($in['id'] ?? 0);
        $deviceId = (int)($in['device_id'] ?? 0);
        $dstPort  = (int)($in['dst_port'] ?? 0);
        $toPort   = (int)($in['to_port'] ?? self::DEFAULT_TO_PORT) ?: self::DEFAULT_TO_PORT;
        // A missing path is "work it out"; an explicit '-' means "no path at all",
        // so a Cashmatic can be given a bare link if someone really wants one.
        $pathGiven = array_key_exists('url_path', $in);
        $path      = trim((string)($in['url_path'] ?? ''));
        if ($path === '-') {
            $path = '';
        }

        $stmt = $pdo->prepare("SELECT id, area_id, ip, name FROM devices WHERE id = ?");
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch();
        if (!$device) {
            return ['ok' => false, 'error' => 'device_not_found'];
        }
        // The rule always lives on the device's OWN router — taking the router
        // from the request would let a mis-click write a rule pointing into a
        // LAN that belongs to a different customer.
        $areaId = (int)($device['area_id'] ?? 0);
        if ($areaId <= 0) {
            return ['ok' => false, 'error' => 'device_has_no_router'];
        }

        // One-click access from the Devices row sends no port and no path: pick
        // a free port ourselves, and give a Cashmatic the login path it needs.
        if ($dstPort <= 0) {
            $dstPort = self::suggestPort($areaId, $id);
            if ($dstPort <= 0) {
                return ['ok' => false, 'error' => 'no_free_port'];
            }
        }
        if (!$pathGiven && $path === '' && self::isCashmatic((string)$device['name'])) {
            $path = self::CASHMATIC_PATH;
        }

        if ($dstPort < 1 || $dstPort > 65535) {
            return ['ok' => false, 'error' => 'bad_port'];
        }
        if (in_array($dstPort, self::RESERVED_PORTS, true)) {
            return ['ok' => false, 'error' => 'reserved_port'];
        }
        if ($toPort < 1 || $toPort > 65535) {
            return ['ok' => false, 'error' => 'bad_to_port'];
        }
        if (!self::pathIsValid($path)) {
            return ['ok' => false, 'error' => 'bad_path'];
        }

        // Free port on this router? Checked here for a clear message; the UNIQUE
        // key is what actually guarantees it under a race.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM device_forwards WHERE area_id = ? AND dst_port = ? AND id <> ?");
        $stmt->execute([$areaId, $dstPort, $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'port_taken'];
        }

        // Re-pointing a forward at a device behind a DIFFERENT router leaves our
        // rule on the old box, where nothing would ever find it again: apply()
        // only cleans the router it is writing to. Clear the old one first, and
        // refuse the move if we cannot — better a blocked edit than a forgotten
        // hole in a customer's router.
        if ($id > 0) {
            $prev = self::one($id);
            if ($prev && (int)$prev['area_id'] !== $areaId) {
                try {
                    $api = self::connect($prev);
                    try {
                        self::dropOurRules($api, self::tag($id));
                    } finally {
                        $api->close();
                    }
                } catch (Throwable $e) {
                    return ['ok' => false, 'error' => 'old_router_unreachable: ' . $e->getMessage()];
                }
            }
        }

        try {
            if ($id > 0) {
                $pdo->prepare(
                    "UPDATE device_forwards SET area_id=?, device_id=?, dst_port=?, to_port=?, url_path=?, status='pending' WHERE id=?"
                )->execute([$areaId, $deviceId, $dstPort, $toPort, $path, $id]);
            } else {
                $pdo->prepare(
                    "INSERT INTO device_forwards (area_id, device_id, dst_port, to_port, url_path) VALUES (?,?,?,?,?)"
                )->execute([$areaId, $deviceId, $dstPort, $toPort, $path]);
                $id = (int)$pdo->lastInsertId();
            }
        } catch (Throwable $e) {
            $dup = stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), '1062') !== false;
            return ['ok' => false, 'error' => $dup ? 'port_taken' : $e->getMessage()];
        }

        $res = self::apply($id);
        $res['id'] = $id;
        return $res;
    }

    /**
     * Push one stored forward to its router, replacing whatever we put there
     * before. Safe to re-run: it removes our own tagged rule first, so an edited
     * port or a re-pointed device never leaves a stale rule behind.
     *
     * @return array{ok:bool,error?:string,status:string,url?:string}
     */
    public static function apply(int $id): array
    {
        $row = self::one($id);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found', 'status' => 'error'];
        }

        try {
            $api = self::connect($row);
        } catch (Throwable $e) {
            return self::fail($id, 'router_unreachable: ' . $e->getMessage());
        }

        $tag = self::tag($id);
        try {
            $iface = self::resolveInterface($api, (string)$row['area_host'], self::iface($row));
            self::rememberInterface((int)$row['area_id'], $iface, self::iface($row));

            self::dropOurRules($api, $tag);

            // Someone else's rule already owns this port on this interface: two
            // dst-nat rules on one port means only the first ever fires, so
            // refuse rather than write a rule that silently does nothing.
            if (self::portBusy($api, $iface, (int)$row['dst_port'])) {
                $api->close();
                return self::fail($id, 'port_busy_on_router');
            }

            $api->command('/ip/firewall/nat/add', [
                'chain'        => 'dstnat',
                'protocol'     => (string)$row['protocol'],
                'in-interface' => $iface,
                'dst-port'     => (string)$row['dst_port'],
                'action'       => 'dst-nat',
                'to-addresses' => (string)$row['device_ip'],
                'to-ports'     => (string)$row['to_port'],
                'comment'      => $tag . ' ' . $row['device_name'],
            ]);
            $ruleId = (string)($api->lastRet() ?? '');
            $api->close();
        } catch (Throwable $e) {
            try { $api->close(); } catch (Throwable $ignored) { /* already gone */ }
            return self::fail($id, $e->getMessage());
        }

        Db::pdo()->prepare(
            "UPDATE device_forwards SET router_rule_id=?, status='active', last_error='', last_synced_at=NOW() WHERE id=?"
        )->execute([$ruleId, $id]);

        return ['ok' => true, 'status' => 'active', 'url' => self::url($row)];
    }

    /**
     * Delete a forward: remove the rule from the router, then drop the row.
     *
     * With $force the row goes even when the router cannot be reached — for a
     * router that is gone for good. The caller is told what was left behind so
     * it can say so rather than imply the rule is off the box.
     *
     * @return array{ok:bool,error?:string,orphaned?:bool}
     */
    public static function delete(int $id, bool $force = false): array
    {
        $row = self::one($id);
        if (!$row) {
            return ['ok' => true];
        }

        $orphaned = false;
        try {
            $api = self::connect($row);
            try {
                self::dropOurRules($api, self::tag($id));
            } finally {
                $api->close();
            }
        } catch (Throwable $e) {
            if (!$force) {
                return ['ok' => false, 'error' => 'router_unreachable: ' . $e->getMessage()];
            }
            $orphaned = true;
        }

        Db::pdo()->prepare("DELETE FROM device_forwards WHERE id = ?")->execute([$id]);
        return ['ok' => true, 'orphaned' => $orphaned];
    }

    /**
     * Drop every forward pointing at a device that is being removed. Best
     * effort by design: deleting the device must not fail because its router
     * is offline, so an unreachable router only leaves the rule behind.
     */
    public static function deleteForDevice(int $deviceId): void
    {
        $stmt = Db::pdo()->prepare("SELECT id FROM device_forwards WHERE device_id = ?");
        $stmt->execute([$deviceId]);
        foreach ($stmt->fetchAll() ?: [] as $r) {
            try {
                self::delete((int)$r['id'], true);
            } catch (Throwable $e) {
                error_log('forward cleanup failed for device ' . $deviceId . ': ' . $e->getMessage());
            }
        }
    }

    // ---- internals -----------------------------------------------------

    private static function one(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT f.*, a.name AS area_name, a.host AS area_host, a.api_port, a.api_user, a.api_pass,
                    a.vpn_interface, d.name AS device_name, d.ip AS device_ip
               FROM device_forwards f
               JOIN network_areas a ON a.id = f.area_id
               JOIN devices d       ON d.id = f.device_id
              WHERE f.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function connect(array $row): RouterOsApi
    {
        return Monitor::connect([
            'host' => (string)$row['area_host'],
            'port' => (int)$row['api_port'],
            'user' => (string)$row['api_user'],
            'pass' => (string)$row['api_pass'],
        ]);
    }

    private static function iface(array $row): string
    {
        $iface = trim((string)($row['vpn_interface'] ?? ''));
        return $iface !== '' ? $iface : self::DEFAULT_INTERFACE;
    }

    /**
     * The interface our traffic actually arrives on: whichever one holds the
     * address the CRM dials. Not a guess — a dst-nat rule matching any other
     * interface simply never fires.
     *
     * The name is not "WIREGUARD" everywhere. Of the six routers in service,
     * four call it WIREGUARD, La Scogliera calls it wg2 and BRADEM wg1 — and
     * the four that do have a WIREGUARD also have a wg1 carrying a different
     * tunnel, so picking by name would have been wrong there too. Asking the
     * router which interface owns 192.168.200.x answers it exactly.
     *
     * Falls back to the configured name when the address cannot be matched (a
     * hostname rather than an IP, say), and only if the router really has an
     * interface by that name. Otherwise it throws, naming the candidates.
     */
    private static function resolveInterface(RouterOsApi $api, string $host, string $configured): string
    {
        $host = trim($host);
        foreach ($api->command('/ip/address/print') as $a) {
            if (($a['disabled'] ?? 'false') === 'true') {
                continue;
            }
            $addr = explode('/', (string)($a['address'] ?? ''))[0];
            if ($addr !== '' && $addr === $host) {
                $iface = trim((string)($a['interface'] ?? ''));
                if ($iface !== '') {
                    return $iface;
                }
            }
        }

        $names = [];
        foreach ($api->command('/interface/print') as $i) {
            $name = (string)($i['name'] ?? '');
            if ($name === $configured) {
                return $configured;
            }
            if (($i['type'] ?? '') === 'wg') {
                $names[] = $name;
            }
        }
        throw new RuntimeException(sprintf(
            'vpn_interface_not_found: this router has no interface "%s"%s',
            $configured,
            $names ? ' — its VPN interfaces are: ' . implode(', ', $names) : ''
        ));
    }

    /** Remember the resolved name so the router page shows what is really used. */
    private static function rememberInterface(int $areaId, string $iface, string $was): void
    {
        if ($iface === $was || $areaId <= 0) {
            return;
        }
        Db::pdo()->prepare("UPDATE network_areas SET vpn_interface = ? WHERE id = ?")
            ->execute([$iface, $areaId]);
    }

    /** Is an enabled dstnat rule we don't own already listening on this port? */
    private static function portBusy(RouterOsApi $api, string $iface, int $port): bool
    {
        foreach ($api->command('/ip/firewall/nat/print') as $rule) {
            if (($rule['chain'] ?? '') !== 'dstnat' || ($rule['disabled'] ?? 'false') === 'true') {
                continue;
            }
            // dst-port may be a list or a range, not just a single number.
            if (!in_array($port, self::expandPorts((string)($rule['dst-port'] ?? '')), true)) {
                continue;
            }
            // A rule with no in-interface matches every interface, ours included.
            $ruleIface = (string)($rule['in-interface'] ?? '');
            if ($ruleIface === '' || $ruleIface === $iface) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove every NAT rule carrying our tag. Matched on the comment rather
     * than the stored .id: RouterOS renumbers .ids, and a rule restored from a
     * router backup comes back with a different one. Rules without our tag are
     * left alone — the customer's own port forwards are not ours to touch.
     */
    private static function dropOurRules(RouterOsApi $api, string $tag): void
    {
        foreach ($api->command('/ip/firewall/nat/print') as $rule) {
            $comment = (string)($rule['comment'] ?? '');
            // Exact tag, or the tag followed by the device name we append.
            if ($comment !== $tag && strpos($comment, $tag . ' ') !== 0) {
                continue;
            }
            $ruleId = (string)($rule['.id'] ?? '');
            if ($ruleId !== '') {
                $api->command('/ip/firewall/nat/remove', ['.id' => $ruleId]);
            }
        }
    }

    /** Record a failed push on the row and hand the message back to the caller. */
    private static function fail(int $id, string $error): array
    {
        Db::pdo()->prepare("UPDATE device_forwards SET status='error', last_error=? WHERE id=?")
            ->execute([mb_substr($error, 0, 255), $id]);
        return ['ok' => false, 'error' => $error, 'status' => 'error'];
    }

    /** Blank, or an absolute path with no scheme, host, whitespace or traversal. */
    private static function pathIsValid(string $path): bool
    {
        if ($path === '') {
            return true;
        }
        if ($path[0] !== '/' || strpos($path, '//') === 0) {
            return false; // "//evil.com/x" would read as a protocol-relative host
        }
        if (preg_match('/\s/', $path) || strpos($path, '..') !== false) {
            return false;
        }
        return (bool)preg_match('#^/[A-Za-z0-9._~%!$&()*+,;=:@/?=&\[\]-]*$#', $path);
    }
}
