<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Event\Log;
use PDO;

/**
 * The contracts a customer has — plural (migration 080).
 *
 * "Un cliente deve poter avere un elenco di contratti indipendenti tra loro.
 *  Ogni contratto deve poter essere associato a uno o più dispositivi."
 *
 * One row per contract, and nothing about one contract touches another: a shop
 * can hold an H24 on the two Cashmatic machines, paid by SDD, and a basic one
 * on the fiscal printer, paid by transfer, and the card shows both with what
 * each covers.
 *
 * What a contract is worth is the pair (amount, period): "€ 1.200" means
 * nothing on its own, and the office writes a canone annuale as readily as a
 * monthly one. Status is not typed in — a contract is active until its date
 * passes, or until somebody cancels it, and both are facts rather than
 * opinions ([[status]]).
 *
 * The devices are the real ones (`devices`, the machines the CRM already
 * monitors per shop: CASHMATIC, PC CASSA, STAMPANTE FISCALE…), not free text,
 * so "which contract covers this till" has an answer.
 *
 * See Crm\Maintenance, which asks this first when it decides whether a customer
 * is covered at all.
 */
final class Contracts
{
    /** How a canone is billed. The amount alone says nothing. */
    public const PERIODS = ['month', 'quarter', 'semester', 'year', 'one_off'];

    /** How it is paid — the list the office asked for, "ecc." included. */
    public const METHODS = ['sdd', 'transfer', 'card', 'cash', 'other'];

    /** Every contract of one customer, the live and nearest first, each with its devices. */
    public static function forContact(int $contactId): array
    {
        if ($contactId <= 0) {
            return [];
        }
        $q = Db::pdo()->prepare(
            // Live ones first and, among those, the one that runs out soonest — which is
            // what the maintenance panel and the expiry warning both want to read.
            'SELECT * FROM contracts WHERE contact_id = ?
              ORDER BY (status = "cancelled") ASC,
                       (expires_on IS NOT NULL AND expires_on < CURDATE()) ASC,
                       (expires_on IS NULL) ASC, expires_on ASC, id DESC'
        );
        $q->execute([$contactId]);
        $rows = $q->fetchAll() ?: [];
        if (!$rows) {
            return [];
        }
        $devices = self::devicesOf(array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $r['devices'] = $devices[(int)$r['id']] ?? [];
            $r['state']   = self::state($r);
        }
        return $rows;
    }

    public static function find(int $id): ?array
    {
        $q = Db::pdo()->prepare('SELECT * FROM contracts WHERE id = ?');
        $q->execute([$id]);
        $r = $q->fetch();
        if (!$r) {
            return null;
        }
        $r['devices'] = self::devicesOf([(int)$r['id']])[(int)$r['id']] ?? [];
        $r['state']   = self::state($r);
        return $r;
    }

    /**
     * Where a contract stands, which is read and never typed: cancelled by
     * somebody, expired because the date has passed, or active. A contract with
     * no end date does not expire — plenty of them never do.
     */
    public static function state(array $c): string
    {
        if ((string)$c['status'] === 'cancelled') {
            return 'cancelled';
        }
        $to = trim((string)($c['expires_on'] ?? ''));
        return $to !== '' && $to < date('Y-m-d') ? 'expired' : 'active';
    }

    /** Is this customer covered by at least one live contract? */
    public static function activeFor(int $contactId): ?array
    {
        foreach (self::forContact($contactId) as $c) {
            if ($c['state'] === 'active') {
                return $c;
            }
        }
        return null;
    }

    /**
     * Save one contract — a new one when $id is 0, otherwise the one by that id.
     * The devices come as a list of ids; whatever is not in it stops being
     * covered.
     *
     * @return array{ok:bool, id:int, error:?string}
     */
    public static function save(int $id, int $contactId, array $d, ?int $userId): array
    {
        $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 120);
        if ($name === '') {
            return ['ok' => false, 'id' => 0, 'error' => 'name'];
        }
        if ($contactId <= 0 || !Contacts::find($contactId)) {
            return ['ok' => false, 'id' => 0, 'error' => 'no_customer'];
        }
        $amount = self::cents((string)($d['amount'] ?? ''));
        if ($amount !== null && $amount < 0) {
            return ['ok' => false, 'id' => 0, 'error' => 'amount'];
        }
        $period = in_array((string)($d['period'] ?? ''), self::PERIODS, true) ? (string)$d['period'] : 'year';
        $method = in_array((string)($d['payment_method'] ?? ''), self::METHODS, true)
            ? (string)$d['payment_method'] : 'transfer';
        $from = self::date((string)($d['started_on'] ?? ''));
        $to   = self::date((string)($d['expires_on'] ?? ''));
        if ($from !== null && $to !== null && $to < $from) {
            return ['ok' => false, 'id' => 0, 'error' => 'dates'];
        }
        $status = (string)($d['status'] ?? '') === 'cancelled' ? 'cancelled' : 'active';
        $notes  = mb_substr(trim((string)($d['notes'] ?? '')), 0, 500) ?: null;

        $db = Db::pdo();
        if ($id > 0) {
            $cur = self::find($id);
            if (!$cur || (int)$cur['contact_id'] !== $contactId) {
                return ['ok' => false, 'id' => 0, 'error' => 'not_found'];
            }
            $db->prepare(
                'UPDATE contracts SET name = ?, amount_cents = ?, period = ?, payment_method = ?,
                        started_on = ?, expires_on = ?, status = ?, notes = ? WHERE id = ?'
            )->execute([$name, $amount, $period, $method, $from, $to, $status, $notes, $id]);
        } else {
            $db->prepare(
                'INSERT INTO contracts (contact_id, name, amount_cents, period, payment_method,
                                        started_on, expires_on, status, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([$contactId, $name, $amount, $period, $method, $from, $to, $status, $notes, $userId ?: null]);
            $id = (int)$db->lastInsertId();
        }

        self::setDevices($id, (array)($d['device_ids'] ?? []));
        Log::write('crm', $id > 0 ? 'contract_saved' : 'contract_created', 'contact', $contactId,
            ['contract' => $id, 'name' => $name, 'by' => $userId]);
        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    /** Which machines this contract covers, from here on. */
    public static function setDevices(int $contractId, array $deviceIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $deviceIds), fn($i) => $i > 0)));
        $db  = Db::pdo();
        $db->prepare('DELETE FROM contract_devices WHERE contract_id = ?')->execute([$contractId]);
        if (!$ids) {
            return;
        }
        // Only devices that exist: a stale form must not invent coverage.
        $in   = implode(',', $ids);
        $real = $db->query("SELECT id FROM devices WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $ins  = $db->prepare('INSERT IGNORE INTO contract_devices (contract_id, device_id) VALUES (?, ?)');
        foreach ($real as $did) {
            $ins->execute([$contractId, (int)$did]);
        }
    }

    /** A contract is never deleted while it may have been billed: it is cancelled. */
    public static function cancel(int $id, ?int $userId): bool
    {
        $c = self::find($id);
        if (!$c) {
            return false;
        }
        Db::pdo()->prepare('UPDATE contracts SET status = "cancelled" WHERE id = ?')->execute([$id]);
        Log::write('crm', 'contract_cancelled', 'contact', (int)$c['contact_id'], ['contract' => $id, 'by' => $userId]);
        return true;
    }

    /** …unless it was written by mistake and covers nothing: then it goes. */
    public static function delete(int $id, ?int $userId): bool
    {
        $c = self::find($id);
        if (!$c) {
            return false;
        }
        $db = Db::pdo();
        $db->prepare('DELETE FROM contract_devices WHERE contract_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM contracts WHERE id = ?')->execute([$id]);
        Log::write('crm', 'contract_deleted', 'contact', (int)$c['contact_id'],
            ['contract' => $id, 'name' => (string)$c['name'], 'by' => $userId]);
        return true;
    }

    /**
     * The machines, with the shop they stand in, for the picker.
     * The customer's own first when the CRM knows which area is theirs, since
     * that is nearly always what is being covered.
     *
     * @return array<int,array> devices, each with 'area' and 'mine'
     */
    public static function devicePicker(int $contactId): array
    {
        $rows = Db::pdo()->query(
            'SELECT d.id, d.name, d.ip, d.active, a.name AS area, a.contact_id
               FROM devices d LEFT JOIN network_areas a ON a.id = d.area_id
              ORDER BY a.name IS NULL, a.name, d.sort_order, d.id'
        )->fetchAll() ?: [];
        foreach ($rows as &$r) {
            $r['mine'] = $contactId > 0 && (int)($r['contact_id'] ?? 0) === $contactId;
        }
        unset($r);
        usort($rows, static fn(array $a, array $b): int
            => ($b['mine'] <=> $a['mine']) ?: strcasecmp((string)$a['area'] . $a['name'], (string)$b['area'] . $b['name']));
        return $rows;
    }

    /** @return array<int,array<int,array>> contract id => its devices */
    private static function devicesOf(array $contractIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $contractIds)));
        if (!$ids) {
            return [];
        }
        $in   = implode(',', $ids);
        $rows = Db::pdo()->query(
            "SELECT cd.contract_id, d.id, d.name, d.ip, d.status, a.name AS area
               FROM contract_devices cd
               JOIN devices d ON d.id = cd.device_id
               LEFT JOIN network_areas a ON a.id = d.area_id
              WHERE cd.contract_id IN ($in)
              ORDER BY a.name, d.sort_order, d.id"
        )->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['contract_id']][] = $r;
        }
        return $out;
    }

    /** "1.200,50" / "1200.50" → 120050. Empty stays empty: not every contract has a price on it. */
    public static function cents(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace(' ', '', $raw);
        // Italian writes 1.200,50; the form may also be filled from a keyboard
        // that does it the other way round.
        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        }
        return is_numeric($raw) ? (int)round(((float)$raw) * 100) : null;
    }

    private static function date(string $raw): ?string
    {
        $raw = trim($raw);
        return $raw !== '' && ($ts = strtotime($raw)) ? date('Y-m-d', $ts) : null;
    }
}
