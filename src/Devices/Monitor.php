<?php
declare(strict_types=1);

namespace Glue\Devices;

use Glue\Db;
use Glue\Notify\TextMeBot;
use Throwable;

/**
 * Device up/down monitor. Loads the network areas (routers) from the DB, logs
 * into each router's RouterOS API over WireGuard, pings its devices, and records
 * status. On every state change it appends a row to device_events — that's the
 * disconnection log the dashboard shows.
 */
final class Monitor
{
    /**
     * Network areas (routers) to poll.
     * @return array<int,array{id:int,name:string,host:string,port:int,user:string,pass:string,count:int,alert_phone:string}>
     */
    public static function areas(): array
    {
        // alert_phone may not exist pre-migration 016 — tolerate that.
        try {
            $rows = Db::pdo()->query(
                "SELECT id, name, host, api_port, api_user, api_pass, ping_count, alert_phone
                   FROM network_areas WHERE active = 1 ORDER BY sort_order, id"
            )->fetchAll();
        } catch (Throwable $e) {
            $rows = Db::pdo()->query(
                "SELECT id, name, host, api_port, api_user, api_pass, ping_count
                   FROM network_areas WHERE active = 1 ORDER BY sort_order, id"
            )->fetchAll();
        }
        return array_map(static fn($r) => [
            'id'    => (int)$r['id'],
            'name'  => (string)$r['name'],
            'host'  => (string)$r['host'],
            'port'  => (int)$r['api_port'],
            'user'  => (string)$r['api_user'],
            'pass'  => (string)$r['api_pass'],
            'count' => max(1, (int)$r['ping_count']),
            'alert_phone' => trim((string)($r['alert_phone'] ?? '')),
        ], $rows ?: []);
    }

    /** Connect + login to one area's router. Throws on failure. */
    public static function connect(array $area): RouterOsApi
    {
        $api = new RouterOsApi($area['host'], $area['port']);
        $api->login($area['user'], $area['pass']);
        return $api;
    }

    /**
     * Poll every active device once and record results. Each device is pinged
     * through its area's router (devices with no/inactive area use the first
     * active area). A router that's unreachable leaves its devices untouched
     * (a VPN blip must not mark them all down). Logs up/down transitions to
     * device_events.
     *
     * @return array{ok:bool,checked:int,up:int,down:int,error:?string,results:array}
     */
    public static function poll(): array
    {
        $pdo = Db::pdo();

        $areas = self::areas();
        if (!$areas) {
            return ['ok' => false, 'checked' => 0, 'up' => 0, 'down' => 0,
                    'error' => 'no_network_areas', 'results' => []];
        }
        $areaById = [];
        foreach ($areas as $a) {
            $areaById[$a['id']] = $a;
        }
        $fallback = $areas[0];

        $devices = $pdo->query(
            "SELECT id, name, ip, area_id, status FROM devices WHERE active = 1 ORDER BY sort_order, id"
        )->fetchAll();
        if (!$devices) {
            return ['ok' => true, 'checked' => 0, 'up' => 0, 'down' => 0, 'error' => null, 'results' => []];
        }

        // Group devices by the router that will poll them.
        $byArea = [];
        foreach ($devices as $d) {
            $aid  = $d['area_id'] !== null ? (int)$d['area_id'] : 0;
            $area = $areaById[$aid] ?? $fallback;
            $byArea[$area['id']]['area'] = $area;
            $byArea[$area['id']]['devices'][] = $d;
        }

        $now = date('Y-m-d H:i:s');
        $up = 0; $down = 0; $results = []; $firstError = null;

        $updUp   = $pdo->prepare("UPDATE devices SET status='up', latency_ms=?, last_seen_at=?, last_checked_at=? WHERE id=?");
        $updDown = $pdo->prepare("UPDATE devices SET status='down', latency_ms=NULL, last_checked_at=? WHERE id=?");
        $logEvt  = $pdo->prepare("INSERT INTO device_events (device_id, event_type, latency_ms) VALUES (?, ?, ?)");

        foreach ($byArea as $group) {
            $area = $group['area'];
            try {
                $api = self::connect($area);
            } catch (Throwable $e) {
                $firstError = $firstError ?? ($area['name'] . ': ' . $e->getMessage());
                continue;
            }

            // Transitions detected this area, to alert on AFTER we close the
            // router connection (a slow WhatsApp call shouldn't hold it open).
            $transitions = [];
            foreach ($group['devices'] as $d) {
                try {
                    [$isUp, $ms] = $api->ping($d['ip'], $area['count']);
                } catch (Throwable $e) {
                    $firstError = $firstError ?? ($area['name'] . ': ' . $e->getMessage());
                    break;
                }
                $prev = (string)$d['status'];
                if ($isUp) {
                    $updUp->execute([$ms, $now, $now, $d['id']]);
                    $up++;
                    // Log recovery only when it was previously down (not on first-ever check).
                    if ($prev === 'down') {
                        $logEvt->execute([$d['id'], 'up', $ms]);
                        $transitions[] = ['device' => $d['name'], 'ip' => $d['ip'], 'event' => 'up', 'latency_ms' => $ms];
                    }
                } else {
                    $updDown->execute([$now, $d['id']]);
                    $down++;
                    // Log a disconnection when it was up (or first seen as down after unknown).
                    if ($prev === 'up') {
                        $logEvt->execute([$d['id'], 'down', null]);
                        $transitions[] = ['device' => $d['name'], 'ip' => $d['ip'], 'event' => 'down', 'latency_ms' => null];
                    }
                }
                $results[] = ['ip' => $d['ip'], 'name' => $d['name'], 'up' => $isUp, 'latency_ms' => $ms];
            }
            $api->close();

            // Fire WhatsApp alerts for this area's transitions.
            self::sendAlerts($area, $transitions);
        }

        return ['ok' => $firstError === null, 'checked' => count($results),
                'up' => $up, 'down' => $down, 'error' => $firstError, 'results' => $results];
    }

    /**
     * Send a WhatsApp alert per device transition to the area's alert_phone.
     * No-op when the phone is blank or TextMeBot isn't configured. Never throws —
     * a failed alert must not break polling (it's logged to the PHP error log).
     *
     * @param array $transitions each ['device','ip','event'=>'up'|'down','latency_ms']
     */
    private static function sendAlerts(array $area, array $transitions): void
    {
        $phone = trim((string)($area['alert_phone'] ?? ''));
        if ($phone === '' || !$transitions) {
            return;
        }
        try {
            $bot = new TextMeBot();
            if (!$bot->enabled()) {
                return;
            }
            foreach ($transitions as $tr) {
                if ($tr['event'] === 'down') {
                    $msg = sprintf(
                        "\u{1F534} %s\nDevice OFFLINE: %s (%s)\n%s",
                        $area['name'], $tr['device'], $tr['ip'], date('Y-m-d H:i')
                    );
                } else {
                    $lat = $tr['latency_ms'] !== null ? ' — ' . number_format((float)$tr['latency_ms'], 1) . ' ms' : '';
                    $msg = sprintf(
                        "\u{1F7E2} %s\nDevice BACK ONLINE: %s (%s)%s\n%s",
                        $area['name'], $tr['device'], $tr['ip'], $lat, date('Y-m-d H:i')
                    );
                }
                $res = $bot->sendWhatsapp($phone, $msg);
                if (empty($res['ok'])) {
                    error_log('device alert WhatsApp failed for ' . $phone . ': http=' . ($res['http'] ?? '?'));
                }
            }
        } catch (Throwable $e) {
            error_log('sendAlerts failed: ' . $e->getMessage());
        }
    }
}
