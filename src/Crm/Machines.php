<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;

/**
 * The machines a customer actually has (migration 081).
 *
 * "Le macchine non sono queste, me le farai aggiungere manualmente attraverso
 *  un form con marca, modello, numero di serie e varie."
 *
 * The CRM knew hardware in two half-ways, and neither was this. `devices` is
 * the LAN: what the monitoring pings, named for what it does on the network
 * ("TAB 7", "PRECONTO"). `install_reports` is the paperwork of one visit, which
 * happens to carry a model and a serial. Neither answers "what does this
 * customer own", which is the question a maintenance contract, a spare part and
 * a call-out all start from.
 *
 * So: one row per physical machine, typed in by the office. The serial is what
 * makes it that machine and not an identical one in the next shop — everything
 * else is description. Nothing here is inferred or imported: a wrong serial is
 * worse than an empty one.
 *
 * A machine is never deleted once it has been on a contract or a report: it is
 * marked dismissed (dismessa) and stays, because last year's contract still
 * refers to it.
 */
final class Machines
{
    /** The kinds this business actually installs. Free text underneath. */
    public const KINDS = ['cassa_automatica', 'registratore', 'stampante_fiscale',
                          'totem', 'bilancia', 'pc', 'monitor', 'pos', 'altro'];

    /** Every machine of one customer: live first, then the dismissed ones. */
    public static function forContact(int $contactId, bool $withDismissed = true): array
    {
        if ($contactId <= 0) {
            return [];
        }
        $sql = 'SELECT * FROM customer_machines WHERE contact_id = ?'
             . ($withDismissed ? '' : " AND status = 'active'")
             . " ORDER BY status = 'dismissed', label IS NULL, label, brand, model, id";
        $q = Db::pdo()->prepare($sql);
        $q->execute([$contactId]);
        return $q->fetchAll() ?: [];
    }

    public static function find(int $id): ?array
    {
        $q = Db::pdo()->prepare('SELECT * FROM customer_machines WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /**
     * How to name a machine in one line: what the shop calls it when it has a
     * name, otherwise brand and model, otherwise the serial, otherwise nothing
     * but its number — a machine with no description at all is still a machine.
     */
    public static function title(array $m): string
    {
        $bits = array_filter([
            trim((string)($m['brand'] ?? '')),
            trim((string)($m['model'] ?? '')),
        ], 'strlen');
        $name = trim((string)($m['label'] ?? ''));
        $desc = $bits ? implode(' ', $bits) : '';
        if ($name !== '' && $desc !== '') {
            return $name . ' · ' . $desc;
        }
        return $name ?: ($desc ?: (trim((string)($m['serial'] ?? '')) ?: '#' . (int)($m['id'] ?? 0)));
    }

    /**
     * Save one machine. Everything is optional except that it must say SOMETHING
     * — a row with no brand, no model, no serial and no name is not a machine,
     * it is an empty form submitted by accident.
     *
     * @return array{ok:bool, id:int, error:?string}
     */
    public static function save(int $id, int $contactId, array $d, ?int $userId): array
    {
        if ($contactId <= 0 || !Contacts::find($contactId)) {
            return ['ok' => false, 'id' => 0, 'error' => 'no_customer'];
        }
        $cut = static fn(string $k, int $n): ?string
            => (($v = mb_substr(trim((string)($d[$k] ?? '')), 0, $n)) !== '' ? $v : null);

        $brand  = $cut('brand', 60);
        $model  = $cut('model', 80);
        $serial = $cut('serial', 80);
        $label  = $cut('label', 80);
        if ($brand === null && $model === null && $serial === null && $label === null) {
            return ['ok' => false, 'id' => 0, 'error' => 'empty'];
        }
        $kind = (string)($d['kind'] ?? '');
        $kind = in_array($kind, self::KINDS, true) ? $kind : null;

        // The same serial twice on the same customer is nearly always the same
        // machine entered again; across customers it is normal (nothing says a
        // serial is unique in the world, and refurbished units come back).
        if ($serial !== null) {
            $q = Db::pdo()->prepare(
                'SELECT id FROM customer_machines WHERE contact_id = ? AND serial = ? AND id <> ? LIMIT 1'
            );
            $q->execute([$contactId, $serial, $id]);
            if ($q->fetchColumn()) {
                return ['ok' => false, 'id' => 0, 'error' => 'serial_twice'];
            }
        }

        $status = (string)($d['status'] ?? '') === 'dismissed' ? 'dismissed' : 'active';
        $on     = trim((string)($d['installed_on'] ?? ''));
        $on     = $on !== '' && ($ts = strtotime($on)) ? date('Y-m-d', $ts) : null;
        $args   = [$kind, $brand, $model, $serial, $label, $cut('location', 120), $on, $status, $cut('notes', 500)];

        $db = Db::pdo();
        if ($id > 0) {
            $cur = self::find($id);
            if (!$cur || (int)$cur['contact_id'] !== $contactId) {
                return ['ok' => false, 'id' => 0, 'error' => 'not_found'];
            }
            $db->prepare(
                'UPDATE customer_machines SET kind = ?, brand = ?, model = ?, serial = ?, label = ?,
                        location = ?, installed_on = ?, status = ?, notes = ? WHERE id = ?'
            )->execute([...$args, $id]);
        } else {
            $db->prepare(
                'INSERT INTO customer_machines (kind, brand, model, serial, label, location, installed_on,
                                                status, notes, contact_id, install_report_id, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([...$args, $contactId,
                ((int)($d['install_report_id'] ?? 0)) ?: null, $userId ?: null]);
            $id = (int)$db->lastInsertId();
        }
        Log::write('crm', 'machine_saved', 'contact', $contactId,
            ['machine' => $id, 'serial' => $serial, 'by' => $userId]);
        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    /**
     * Take a machine off the floor. It stays on the record — the contract that
     * covered it last year still names it — unless it is on nothing at all, in
     * which case it was a typo and goes.
     */
    public static function remove(int $id, ?int $userId): string
    {
        $m = self::find($id);
        if (!$m) {
            return 'not_found';
        }
        $used = (int)Db::pdo()->query(
            'SELECT COUNT(*) FROM contract_machines WHERE machine_id = ' . (int)$id
        )->fetchColumn();
        if ($used > 0) {
            Db::pdo()->prepare('UPDATE customer_machines SET status = "dismissed" WHERE id = ?')->execute([$id]);
            Log::write('crm', 'machine_dismissed', 'contact', (int)$m['contact_id'], ['machine' => $id, 'by' => $userId]);
            return 'dismissed';
        }
        Db::pdo()->prepare('DELETE FROM customer_machines WHERE id = ?')->execute([$id]);
        Log::write('crm', 'machine_deleted', 'contact', (int)$m['contact_id'], ['machine' => $id, 'by' => $userId]);
        return 'deleted';
    }

    /**
     * Machines a technician already wrote down for this customer and that are
     * not on the record yet — model and serial, straight off the installation
     * reports. Typing a serial twice is how serials get typed wrong.
     *
     * @return array<int,array{report_id:int, model:string, serial:string, when:string}>
     */
    public static function fromReports(int $contactId): array
    {
        $q = Db::pdo()->prepare(
            "SELECT id, machine_model, serial_number, created_at
               FROM install_reports
              WHERE contact_id = ? AND serial_number IS NOT NULL AND serial_number <> ''
              ORDER BY id DESC LIMIT 20"
        );
        $q->execute([$contactId]);
        $have = [];
        foreach (self::forContact($contactId) as $m) {
            $have[self::serialKey((string)($m['serial'] ?? ''))] = true;
        }
        $out = [];
        foreach ($q->fetchAll() ?: [] as $r) {
            $key = self::serialKey((string)$r['serial_number']);
            if ($key === '' || isset($have[$key]) || isset($out[$key])) {
                continue;
            }
            $out[$key] = ['report_id' => (int)$r['id'], 'model' => (string)$r['machine_model'],
                          'serial' => (string)$r['serial_number'], 'when' => (string)$r['created_at']];
        }
        return array_values($out);
    }

    /** Serials are written with spaces as often as without: "2026 26 014" is "202626014". */
    public static function serialKey(string $s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', trim($s)) ?? '');
    }
}
