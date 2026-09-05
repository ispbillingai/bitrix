<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use RuntimeException;
use ZipArchive;

/**
 * Ingests the gestionale's customer export (…-CLIENTI.xlsx) into contacts.
 *
 * The management software drops a full snapshot of every customer — about
 * 10,000 rows — named like 20260903T180034.655-CLIENTI.xlsx. This class reads
 * that file straight (an .xlsx is a zip of XML; no library needed for one known
 * sheet), and upserts each row keyed on its "Cod." (customer_code).
 *
 * Ownership of fields is the part worth being careful about:
 *   gestionale-owned  name/company (only on rows the import itself created),
 *                     VAT, address, city, province, zip, balance, contract
 *                     expiry, agent — rewritten on every import. The gestionale
 *                     is the registry of record; a re-import must converge.
 *   staff-owned       phone, phone2, email — filled when blank, NEVER
 *                     overwritten. A number an agent corrected after a customer
 *                     changed SIM must not be undone by the nightly file.
 *
 * Matching order for a row not yet imported: an existing contact already
 * carrying that VAT (a won lead that became this customer — attach the code to
 * it rather than duplicating the person), else a new contact is created.
 *
 * Every file is hashed into customer_imports; a hash already seen is skipped,
 * so the FTP drop directory can be rescanned by cron forever.
 */
final class CustomerImport
{
    /**
     * Header spellings in the export, mapped to what we call them. The
     * gestionale has shipped two layouts so far — the Sep 2026 one renamed
     * "Cognome o Ragione Sociale" to plain "Cognome" and added Disattiva and
     * Email Pec — so both spellings map, and a column a file doesn't have is
     * simply absent from the map.
     */
    private const HEADERS = [
        'Cod.'                       => 'code',
        'Vs. Rif.'                   => 'ref',
        'Cognome o Ragione Sociale'  => 'surname_or_company',
        'Cognome'                    => 'surname_or_company',
        'Disattiva'                  => 'deactivated',
        'Email Pec'                  => 'pec',
        'Nome'                       => 'first_name',
        'Localita'                   => 'city',
        'Telefono'                   => 'phone_land',
        'Indirizzo'                  => 'address',
        'Num'                        => 'address_num',
        'Prov'                       => 'province',
        'Cap'                        => 'zip',
        'Altro Telefono'             => 'phone_other',
        'Cellulare'                  => 'phone_mobile',
        'Partita IVA'                => 'vat',
        'Email'                      => 'email',
        'Note'                       => 'notes',
        'Note Agg.'                  => 'notes2',
        'Saldo'                      => 'balance',
        'Scad. contratto'            => 'contract_expiry',
        'Agente'                     => 'agent',
        'Aux Check1Label'            => 'check1',
        'Aux Check2Label'            => 'check2',
    ];

    /**
     * Import one export file.
     *
     * @return array{file:string, sha256:string, total:int, created:int, updated:int,
     *               skipped:int, already:bool, dry_run:bool}
     */
    public static function run(string $path, ?int $userId = null, bool $dryRun = false, bool $force = false, bool $prune = false): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("No such file: $path");
        }
        $sha = hash_file('sha256', $path);
        $out = [
            'file' => basename($path), 'sha256' => $sha, 'total' => 0,
            'created' => 0, 'updated' => 0, 'skipped' => 0,
            'pruned' => 0, 'prune_kept' => 0,
            'already' => false, 'dry_run' => $dryRun,
        ];

        $pdo = Db::pdo();
        if (!$force) {
            $q = $pdo->prepare('SELECT id FROM customer_imports WHERE sha256 = ?');
            $q->execute([$sha]);
            if ($q->fetchColumn()) {
                $out['already'] = true;
                return $out;
            }
        }

        $rows = self::readSheet($path); // a generator: rows stream, nothing is held
        $map  = null;

        // One transaction for the whole file: 10,000 autocommitted INSERTs are
        // 10,000 disk flushes (minutes); one commit is seconds. All-or-nothing
        // is also the right failure mode — half an import helps nobody.
        if (!$dryRun) {
            $pdo->beginTransaction();
        }

        $findByCode = $pdo->prepare('SELECT id, source, phone, phone2, email, company FROM contacts WHERE customer_code = ?');
        // Adopt by VAT only when the match is unambiguous and not another import row.
        $findByVat  = $pdo->prepare(
            'SELECT id, source, phone, phone2, email, company FROM contacts
             WHERE vat_number = ? AND customer_code IS NULL LIMIT 2'
        );

        $seenCodes = [];
        try {
        foreach ($rows as $r) {
            if ($map === null) { // first row is the header
                $map = self::mapHeaders($r);
                if (!isset($map['code'])) {
                    throw new RuntimeException('Column "Cod." not found — is this really the CLIENTI export?');
                }
                continue;
            }
            $g = self::rowToFields($r, $map);
            if ($g === null) {
                $out['skipped']++;
                continue;
            }
            $out['total']++;
            $seenCodes[$g['code']] = true;

            $findByCode->execute([$g['code']]);
            $hit = $findByCode->fetch() ?: null;
            if (!$hit && $g['vat'] !== null) {
                $findByVat->execute([$g['vat']]);
                $cands = $findByVat->fetchAll();
                if (count($cands) === 1) {
                    $hit = $cands[0];
                }
            }

            if ($dryRun) {
                $hit ? $out['updated']++ : $out['created']++;
                continue;
            }

            if ($hit) {
                self::updateExisting((int)$hit['id'], $hit, $g);
                $out['updated']++;
            } else {
                self::insertNew($g);
                $out['created']++;
            }
        }

        if ($map === null) {
            throw new RuntimeException('The file has no rows at all.');
        }
        if ($out['total'] === 0) {
            // An empty snapshot is a broken export, not "all customers left" —
            // refuse before prune can act on it.
            throw new RuntimeException('The file has a header but no usable data rows.');
        }
        if ($prune) {
            [$out['pruned'], $out['prune_kept']] = self::prune($seenCodes, $dryRun);
        }
        } catch (\Throwable $e) {
            if (!$dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if (!$dryRun) {
            // Upsert, not insert: a --force re-run of a file already in the
            // ledger refreshes its row instead of dying on the unique hash.
            $pdo->prepare(
                'INSERT INTO customer_imports (filename, sha256, rows_total, created_n, updated_n, skipped_n, imported_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE rows_total = VALUES(rows_total), created_n = VALUES(created_n),
                     updated_n = VALUES(updated_n), skipped_n = VALUES(skipped_n),
                     imported_by = VALUES(imported_by), imported_at = NOW()'
            )->execute([basename($path), $sha, $out['total'], $out['created'], $out['updated'], $out['skipped'], $userId]);
            $pdo->commit();
            Log::write('crm', 'customers_imported', null, null, $out);
        }
        return $out;
    }

    // ---- row -> contact -----------------------------------------------------

    /** Normalise one sheet row into the fields we store; null = not importable. */
    private static function rowToFields(array $r, array $map): ?array
    {
        $get = static fn(string $k): string => trim((string)($r[$map[$k] ?? -1] ?? ''));

        $code = $get('code');
        $surname = $get('surname_or_company');
        if ($code === '' || $surname === '') {
            return null; // no identity, nothing to hang the row on
        }
        // A row the gestionale switched off is not a customer to import; with
        // --prune its code also drops out of "seen", so an existing CRM row for
        // it goes away like one deleted upstream.
        if (in_array(strtolower($get('deactivated')), ['vero', 'true', '1', 'si', 'sì'], true)) {
            return null;
        }

        $first = $get('first_name');
        // "Nome" filled means a person split over two columns; empty means the
        // whole thing is one string — usually a company, sometimes "SURNAME NAME".
        // Either way the joined form is the display name.
        $name = $first !== '' ? Contacts::fullName($first, $surname) : $surname;

        // Best mobile first: WhatsApp reminders go to `phone`.
        $mobile = self::phone($get('phone_mobile'));
        $land   = self::phone($get('phone_land')) ?: self::phone($get('phone_other'));
        $phone  = $mobile ?: $land;
        $phone2 = ($mobile && $land) ? $land : (self::phone($get('phone_other')) ?: null);
        if ($phone2 === $phone) {
            $phone2 = null;
        }

        // The gestionale packs several addresses into one cell ("a@x.it, b@y.it")
        // — 508 rows do. The first valid one becomes THE email (portal login,
        // chasing); the rest are kept in the notes, not thrown away.
        $emails = array_values(array_filter(
            preg_split('/[,;\s]+/', $get('email')) ?: [],
            static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
        ));
        $email = $emails[0] ?? null;

        // Everything the gestionale wrote about this customer, in one notes
        // field: Note, Note Agg., the chasing labels, any spare emails.
        $noteParts = array_filter([
            $get('notes'),
            $get('notes2'),
            count($emails) > 1 ? 'Email 2: ' . implode(', ', array_slice($emails, 1)) : '',
            $get('check1') !== '' ? 'Gestionale: ' . $get('check1') : '',
            $get('check2') !== '' ? 'Gestionale 2: ' . $get('check2') : '',
        ], static fn($s) => $s !== '');

        $addr = trim($get('address') . ' ' . $get('address_num'));

        return [
            'code'            => $code,
            'name'            => mb_substr($name, 0, 190),
            'first_name'      => $first !== '' ? mb_substr($first, 0, 100) : null,
            'last_name'       => $first !== '' ? mb_substr($surname, 0, 100) : null,
            'company'         => $first === '' ? mb_substr($surname, 0, 190) : null,
            'vat'             => VatLock::normalize($get('vat')) ?: null,
            'pec'             => filter_var($get('pec'), FILTER_VALIDATE_EMAIL) ? $get('pec') : null,
            'phone'           => $phone ?: null,
            'phone2'          => $phone2,
            'email'           => $email,
            'address'         => $addr !== '' ? mb_substr($addr, 0, 190) : null,
            'city'            => $get('city') !== '' ? mb_substr($get('city'), 0, 120) : null,
            'province'        => $get('province') !== '' ? mb_substr($get('province'), 0, 8) : null,
            'zip'             => $get('zip') !== '' ? mb_substr($get('zip'), 0, 12) : null,
            'balance'         => (float)str_replace(',', '.', $get('balance') ?: '0'),
            'contract_expiry' => self::excelDate($get('contract_expiry')),
            'agent'           => $get('agent') !== '' ? mb_substr($get('agent'), 0, 120) : null,
            'notes'           => $noteParts ? implode("\n", $noteParts) : null,
        ];
    }

    private static function insertNew(array $g): void
    {
        Db::pdo()->prepare(
            'INSERT INTO contacts
                (name, first_name, last_name, company, phone, phone2, email, pec, lang, source,
                 customer_code, vat_number, is_customer, customer_since,
                 address, city, province, zip, balance, contract_expiry, gestionale_agent, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $g['name'], $g['first_name'] ?? '', $g['last_name'] ?? '', $g['company'],
            $g['phone'], $g['phone2'], $g['email'], $g['pec'],
            (string)Config::get('app.default_lang', 'it'), 'gestionale',
            $g['code'], $g['vat'],
            $g['address'], $g['city'], $g['province'], $g['zip'],
            $g['balance'], $g['contract_expiry'], $g['agent'], $g['notes'],
        ]);
    }

    /**
     * Update a matched contact. Registry fields converge on the file; identity
     * fields follow only on rows the import created (source = 'gestionale') —
     * a contact born in the CRM keeps the name an agent gave it; contact
     * channels and notes are fill-if-blank.
     */
    private static function updateExisting(int $id, array $existing, array $g): void
    {
        $ownedByImport = ($existing['source'] ?? '') === 'gestionale';

        $set = [
            'customer_code'    => $g['code'],
            'vat_number'       => $g['vat'],
            'pec'              => $g['pec'],
            'is_customer'      => 1,
            'address'          => $g['address'],
            'city'             => $g['city'],
            'province'         => $g['province'],
            'zip'              => $g['zip'],
            'balance'          => $g['balance'],
            'contract_expiry'  => $g['contract_expiry'],
            'gestionale_agent' => $g['agent'],
        ];
        if ($ownedByImport) {
            $set['name']       = $g['name'];
            $set['first_name'] = $g['first_name'] ?? '';
            $set['last_name']  = $g['last_name'] ?? '';
            $set['company']    = $g['company'];
            // Notes on an import-born row are the gestionale's notes too —
            // they converge with the file like the rest of the registry.
            $set['notes']      = $g['notes'];
        } elseif (trim((string)($existing['company'] ?? '')) === '' && $g['company'] !== null) {
            $set['company'] = $g['company'];
        }
        foreach (['phone', 'phone2', 'email'] as $k) {
            if (trim((string)($existing[$k] ?? '')) === '' && $g[$k] !== null) {
                $set[$k] = $g[$k];
            }
        }

        $cols = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($set)));
        $args = array_values($set);
        $args[] = $id;
        Db::pdo()->prepare(
            "UPDATE contacts SET $cols, customer_since = COALESCE(customer_since, NOW()) WHERE id = ?"
        )->execute($args);
    }

    /**
     * The file is a full snapshot, so a code it no longer carries is a customer
     * the gestionale deleted (or deactivated): remove ours too — but ONLY rows
     * the import itself created, and only when nothing in the CRM points at
     * them. A vanished customer with tickets, deals, documents, a contract, a
     * router or a portal login is history someone may need; those are counted
     * as kept, never silently destroyed.
     *
     * Deliberately NOT run by default: a truncated or partial file must never
     * be able to mass-delete the registry. The caller opts in per run.
     *
     * @param array<string,true> $seen codes present in this file
     * @return array{0:int,1:int} [deleted, kept because linked]
     */
    private static function prune(array $seen, bool $dryRun): array
    {
        $pdo = Db::pdo();
        $gone = [];
        foreach ($pdo->query("SELECT id, customer_code FROM contacts
                              WHERE source = 'gestionale' AND customer_code IS NOT NULL") as $r) {
            if (!isset($seen[(string)$r['customer_code']])) {
                $gone[] = (int)$r['id'];
            }
        }
        if (!$gone) {
            return [0, 0];
        }

        $in = implode(',', $gone);
        $linked = [];
        foreach ([
            "SELECT DISTINCT contact_id FROM tickets           WHERE contact_id IN ($in)",
            "SELECT DISTINCT contact_id FROM leads             WHERE contact_id IN ($in)",
            "SELECT DISTINCT contact_id FROM deals             WHERE contact_id IN ($in)",
            "SELECT DISTINCT contact_id FROM sign_documents    WHERE contact_id IN ($in)",
            "SELECT DISTINCT contact_id FROM payment_contracts WHERE contact_id IN ($in)",
            "SELECT DISTINCT contact_id FROM network_areas     WHERE contact_id IN ($in)",
            "SELECT id AS contact_id    FROM contacts          WHERE id IN ($in) AND portal_enabled = 1",
        ] as $sql) {
            foreach ($pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN) as $cid) {
                $linked[(int)$cid] = true;
            }
        }

        $deletable = array_values(array_filter($gone, static fn(int $id) => !isset($linked[$id])));
        if (!$dryRun && $deletable) {
            foreach (array_chunk($deletable, 500) as $chunk) {
                $pdo->exec('DELETE FROM contacts WHERE id IN (' . implode(',', $chunk) . ')');
            }
        }
        return [count($deletable), count($linked)];
    }

    /**
     * Not Notifier::normalizePhone — that one drops a leading trunk "0", which is
     * right for mobiles but mangles an Italian landline (the 0 is PART of an
     * Italian number in E.164: 0972 35294 -> +39097235294). Here both kinds pass
     * through the same rule: international prefix respected, else +39 + digits
     * verbatim. Anything shorter than 6 digits is gestionale noise, not a number.
     */
    public static function phone(string $raw): string
    {
        $raw = trim($raw);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) < 6) {
            return '';
        }
        if (str_starts_with($raw, '+')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }
        return '+39' . $digits;
    }

    /** Excel serial ("42204") or textual date -> Y-m-d, else null. */
    private static function excelDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{4,6}$/', $v)) { // serial days since 1899-12-30
            return gmdate('Y-m-d', (int)(((int)$v - 25569) * 86400));
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $v, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            return substr($v, 0, 10);
        }
        return null;
    }

    // ---- the xlsx itself ----------------------------------------------------

    /**
     * Stream the first worksheet, one row at a time, header row first.
     *
     * XMLReader, not simplexml_load_string on the whole sheet: the newer export
     * layout is ~15 MB of XML, and a full DOM of it OOM-killed the import on
     * the production box (848 MB total RAM). Only one <row> fragment is ever
     * materialised at a time, so memory stays flat however large the file gets.
     *
     * @return \Generator<array<int,string>>
     */
    private static function readSheet(string $path): \Generator
    {
        // ZipArchive just to validate + confirm the parts exist; reading itself
        // goes through the zip:// stream wrapper so nothing is inflated whole.
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open the xlsx (is it a real Excel file?).');
        }
        $hasShared = $zip->locateName('xl/sharedStrings.xml') !== false;
        $hasSheet  = $zip->locateName('xl/worksheets/sheet1.xml') !== false;
        $zip->close();
        if (!$hasSheet) {
            throw new RuntimeException('No worksheet found in the file.');
        }

        $shared = [];
        if ($hasShared) {
            $r = new \XMLReader();
            if ($r->open('zip://' . $path . '#xl/sharedStrings.xml')) {
                $ok = $r->read();
                while ($ok) {
                    if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'si') {
                        $si = simplexml_load_string($r->readOuterXml());
                        // A cell string is either one <t> or a run of <r><t> pieces.
                        $txt = '';
                        if (isset($si->t)) {
                            $txt = (string)$si->t;
                        } else {
                            foreach ($si->r as $run) {
                                $txt .= (string)$run->t;
                            }
                        }
                        $shared[] = $txt;
                        $ok = $r->next();
                        continue;
                    }
                    $ok = $r->read();
                }
                $r->close();
            }
        }

        $r = new \XMLReader();
        if (!$r->open('zip://' . $path . '#xl/worksheets/sheet1.xml')) {
            throw new RuntimeException('No worksheet found in the file.');
        }
        $ok = $r->read();
        while ($ok) {
            if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'row') {
                $row = simplexml_load_string($r->readOuterXml());
                $out = [];
                foreach ($row->c as $c) {
                    $ref = (string)$c['r'];
                    preg_match('/^([A-Z]+)/', $ref, $m);
                    $col = 0;
                    foreach (str_split($m[1]) as $ch) {
                        $col = $col * 26 + (ord($ch) - 64);
                    }
                    $col--;
                    $t = (string)$c['t'];
                    if ($t === 'inlineStr') {
                        $val = (string)($c->is->t ?? '');
                    } elseif ($t === 's') {
                        $val = $shared[(int)$c->v] ?? '';
                    } else {
                        $val = (string)$c->v;
                    }
                    $out[$col] = $val;
                }
                if ($out) {
                    yield $out;
                }
                $ok = $r->next();
                continue;
            }
            $ok = $r->read();
        }
        $r->close();
    }

    /** @return array<string,int> our field name -> column index */
    private static function mapHeaders(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $idx => $label) {
            $label = trim((string)$label);
            if (isset(self::HEADERS[$label])) {
                $map[self::HEADERS[$label]] = (int)$idx;
            }
        }
        return $map;
    }
}
