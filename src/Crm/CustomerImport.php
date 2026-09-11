<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Config;
use Glue\Db;
use Glue\Event\Log;
use RuntimeException;

/**
 * Ingests the gestionale's customer export (…-CLIENTI.xlsx) into contacts.
 *
 * The management software drops a full snapshot of every customer — about
 * 10,000 rows — named like 20260903T180034.655-CLIENTI.xlsx. This class streams
 * that file through Xlsx::rows and upserts each row keyed on its "Cod."
 * (customer_code).
 *
 * Ownership of fields is the part worth being careful about:
 *   gestionale-owned  name/company (only on rows the import itself created),
 *                     VAT, address, city, province, zip, balance, contract
 *                     expiry, agent — rewritten on every import. The gestionale
 *                     is the registry of record; a re-import must converge.
 *   shared            phone, phone2, email — a value the import put there
 *                     follows the file (gest_* remembers what it wrote); a value
 *                     typed in the CRM stays: a number an agent corrected after
 *                     a customer changed SIM must not be undone by the nightly
 *                     file. See contactFields().
 *
 * The gestionale REUSES codes — 637 changed hands between the 3 and 5 Sep 2026
 * exports — so a code alone is not an identity: isDifferentCustomer() spots a
 * code that now describes another business, and that card takes the file's
 * phone and email and loses the previous holder's portal login.
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
            'reassigned' => 0, 'contacts_refreshed' => 0,
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

        $rows = Xlsx::rows($path); // a generator: rows stream, nothing is held
        $map  = null;

        // One transaction for the whole file: 10,000 autocommitted INSERTs are
        // 10,000 disk flushes (minutes); one commit is seconds. All-or-nothing
        // is also the right failure mode — half an import helps nobody.
        if (!$dryRun) {
            $pdo->beginTransaction();
        }

        $cardCols   = 'id, source, name, vat_number, phone, phone2, email, company, gest_phone, gest_phone2, gest_email';
        $findByCode = $pdo->prepare("SELECT $cardCols FROM contacts WHERE customer_code = ?");
        // Adopt by VAT only when the match is unambiguous and not another import row.
        $findByVat  = $pdo->prepare(
            "SELECT $cardCols FROM contacts WHERE vat_number = ? AND customer_code IS NULL LIMIT 2"
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
            // Matched on the code, not adopted by VAT: only then can the code
            // have changed hands under the card.
            $byCode = $hit !== null;
            if (!$hit && $g['vat'] !== null) {
                $findByVat->execute([$g['vat']]);
                $cands = $findByVat->fetchAll();
                if (count($cands) === 1) {
                    $hit = $cands[0];
                }
            }
            $reassigned = $byCode && self::isDifferentCustomer($hit, $g);

            if ($dryRun) {
                $hit ? $out['updated']++ : $out['created']++;
                $out['reassigned'] += (int)$reassigned;
                continue;
            }

            if ($hit && $reassigned && ($hit['source'] ?? '') !== 'gestionale') {
                // A card the CRM made (a lead's person, adopted by VAT) whose code
                // the gestionale has since given to another business: the person
                // keeps their card and its history, the code moves to a new card.
                self::detachCode((int)$hit['id'], $hit, $g);
                self::insertNew($g);
                $out['created']++;
                $out['reassigned']++;
            } elseif ($hit) {
                $refreshed = self::updateExisting((int)$hit['id'], $hit, $g, $reassigned);
                $out['updated']++;
                $out['reassigned'] += (int)$reassigned;
                $out['contacts_refreshed'] += (int)$refreshed;
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

            // A lead converted last week is a gestionale customer this week — and
            // this import has just made (or refreshed) its card without knowing
            // the lead. Put such leads on their card now, by VAT. Never allowed to
            // fail an import that is already committed.
            try {
                $out['leads_linked'] = LeadCustomers::reconcile()['linked'];
            } catch (\Throwable $e) {
                Log::write('crm', 'lead_reconcile_failed', null, null, ['error' => $e->getMessage()]);
            }
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
        // A cell holding a number phone() could not read: the file says nothing
        // reliable about it, and contactFields() must not read that as "removed".
        $phonesUnusable = false;
        foreach (['phone_mobile', 'phone_land', 'phone_other'] as $col) {
            $cell = $get($col);
            if (strlen((string)preg_replace('/\D+/', '', $cell)) >= 6 && self::phone($cell) === '') {
                $phonesUnusable = true;
            }
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
            'phones_unusable' => $phonesUnusable,
            'email'           => $email,
            'address'         => $addr !== '' ? mb_substr($addr, 0, 190) : null,
            'city'            => $get('city') !== '' ? mb_substr($get('city'), 0, 120) : null,
            'province'        => $get('province') !== '' ? mb_substr($get('province'), 0, 8) : null,
            'zip'             => $get('zip') !== '' ? mb_substr($get('zip'), 0, 12) : null,
            'balance'         => (float)str_replace(',', '.', $get('balance') ?: '0'),
            'contract_expiry' => Xlsx::date($get('contract_expiry')),
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
                 address, city, province, zip, balance, contract_expiry, gestionale_agent, notes,
                 gest_phone, gest_phone2, gest_email)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $g['name'], $g['first_name'] ?? '', $g['last_name'] ?? '', $g['company'],
            $g['phone'], $g['phone2'], $g['email'], $g['pec'],
            (string)Config::get('app.default_lang', 'it'), 'gestionale',
            $g['code'], $g['vat'],
            $g['address'], $g['city'], $g['province'], $g['zip'],
            $g['balance'], $g['contract_expiry'], $g['agent'], $g['notes'],
            $g['phone'], $g['phone2'], $g['email'],
        ]);
    }

    /**
     * Update a matched contact. Registry fields converge on the file; identity
     * fields follow only on rows the import created (source = 'gestionale') —
     * a contact born in the CRM keeps the name an agent gave it; phone, second
     * phone and email follow contactFields(); notes are the gestionale's on its
     * own rows.
     *
     * $reassigned: the code now names another business (isDifferentCustomer) —
     * the card becomes that business, contacts and all, and the previous
     * holder's portal login is switched off: it must not open someone else's
     * card. Said on the card's timeline and in the event log.
     *
     * @return bool whether the phone, second phone or email changed
     */
    private static function updateExisting(int $id, array $existing, array $g, bool $reassigned = false): bool
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
        [$channels, $refreshed] = self::contactFields($existing, $g, $reassigned);
        $set += $channels;
        if ($reassigned) {
            $set['portal_enabled'] = 0;
        }

        $cols = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($set)));
        $args = array_values($set);
        $args[] = $id;
        Db::pdo()->prepare(
            "UPDATE contacts SET $cols, customer_since = COALESCE(customer_since, NOW()) WHERE id = ?"
        )->execute($args);

        if ($reassigned) {
            $was = trim((string)($existing['name'] ?? ''));
            Activities::add('contact', $id, 'system',
                "Codice gestionale {$g['code']} riassegnato dal gestionale: era $was, ora {$g['name']}. "
                . 'Telefono ed email presi dal file; accesso al portale del cliente precedente disattivato.', null);
            Log::write('crm', 'customer_code_reassigned', 'contact', $id, [
                'code' => $g['code'], 'was' => $was, 'was_vat' => $existing['vat_number'] ?? null,
                'now' => $g['name'], 'now_vat' => $g['vat'],
            ]);
        }
        return $refreshed;
    }

    /**
     * Phone, second phone and email for a card the file matched. The import
     * remembers what it last wrote (gest_*). A value still equal to that came
     * from the gestionale and follows it — to a new number, or to none when the
     * gestionale dropped it. A value that differs was typed in the CRM and
     * stays. A blank is filled. A reassigned code takes the file's values
     * outright: the previous holder's numbers are not this customer's,
     * whoever typed them. A card with no gest_* yet keeps what it has — the
     * repair of 2026-09-11 backfilled them for every registry card.
     *
     * @return array{0: array<string,?string>, 1: bool} [columns to set, contacts changed]
     */
    private static function contactFields(array $existing, array $g, bool $reassigned): array
    {
        $set = [];
        $changed = false;
        foreach (['phone', 'phone2', 'email'] as $k) {
            $cur  = trim((string)($existing[$k] ?? ''));
            $was  = trim((string)($existing['gest_' . $k] ?? ''));
            $new  = $g[$k] ?? null;
            // The row had a phone cell we could not read (two numbers glued
            // together): no news about this number — neither follow it to
            // nothing nor forget what the import wrote before. A reassigned
            // code still loses the previous holder's number.
            if ($new === null && $k !== 'email' && !empty($g['phones_unusable']) && !$reassigned) {
                $set['gest_' . $k] = $existing['gest_' . $k] ?? null;
                continue;
            }
            $take = $reassigned
                || ($cur === '' && $new !== null)
                || ($was !== '' && $cur === $was);
            if ($take && (string)$new !== $cur) {
                $set[$k] = $new;
                $changed = true;
            }
            $set['gest_' . $k] = $new;
        }
        return [$set, $changed];
    }

    /**
     * Words that say what kind of business a name is, not which one — left out
     * when isDifferentCustomer() compares names.
     */
    private const NAME_NOISE = [
        'SRL', 'SRLS', 'SAS', 'SNC', 'SPA', 'SOC', 'COOP', 'COOPERATIVA', 'SOCIETA', 'UNIPERSONALE', 'UNIP',
        'DEL', 'DELLA', 'DELLE', 'DEI', 'DEGLI', 'CON', 'PER', 'THE', 'AND', 'LLI', 'FLLI',
        'BAR', 'PIZZERIA', 'RISTORANTE', 'TRATTORIA', 'HOTEL', 'ALBERGO', 'CAFFE', 'CAFE', 'PANIFICIO',
        'MACELLERIA', 'PASTICCERIA', 'GELATERIA', 'SALUMERIA', 'TABACCHI', 'FARMACIA', 'STUDIO',
        'GROUP', 'SERVICE', 'SERVIZI', 'ITALIA', 'NAPOLI', 'MARKET', 'STORE', 'SHOP',
    ];

    /**
     * Does this file row describe a different customer than the card its code
     * points at? The gestionale REUSES codes — between the 3 and the 5 Sep 2026
     * exports 637 changed hands (9705 went from LUCIANO PITTALUGA to SINERGIA
     * MAXIMO SRL) — and the code is the only key the file has.
     *
     * The same VAT is the same business whatever the name says. Otherwise the
     * names must share a word that is not a legal form or a trade noun: "BAR DEI
     * PESCATORI" and "PESCATORI SRL" are one customer, "PIZZERIA DA MARIO" and
     * "PIZZERIA MIRACOLO" are not. A name the CRM gave (a card born from a lead,
     * adopted by VAT) says nothing about the file, so there it takes two VAT
     * numbers that differ.
     */
    private static function isDifferentCustomer(array $card, array $g): bool
    {
        $oldVat = (string)($card['vat_number'] ?? '');
        $newVat = (string)($g['vat'] ?? '');
        if ($oldVat !== '' && $oldVat === $newVat) {
            return false;
        }
        if (($oldVat === '' || $newVat === '') && ($card['source'] ?? '') !== 'gestionale') {
            return false;
        }
        return !self::sameName((string)($card['name'] ?? ''), (string)$g['name']);
    }

    private static function sameName(string $a, string $b): bool
    {
        $words = static function (string $s): array {
            $s = mb_strtoupper(str_replace(['.', "'", '’'], ['', ' ', ' '], $s));
            $w = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            return array_values(array_filter($w, static fn($x) => mb_strlen($x) >= 3 && !in_array($x, self::NAME_NOISE, true)));
        };
        $wa = $words($a);
        $wb = $words($b);
        if ($wa && $wb) {
            return (bool)array_intersect($wa, $wb);
        }
        // Nothing distinctive on one side ("BAR S.R.L."): compare the whole names.
        $flat = static fn(string $s): string => (string)preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper($s));
        $fa = $flat($a);
        $fb = $flat($b);
        return $fa !== '' && $fb !== '' && (str_contains($fa, $fb) || str_contains($fb, $fa));
    }

    /**
     * Take the code off a card the CRM made, because the gestionale has given
     * it to another business. The card keeps its person, its history and its
     * contacts; the code goes to the new card insertNew() makes next.
     */
    private static function detachCode(int $id, array $existing, array $g): void
    {
        Db::pdo()->prepare('UPDATE contacts SET customer_code = NULL WHERE id = ?')->execute([$id]);
        Activities::add('contact', $id, 'system',
            "Il codice gestionale {$g['code']} ora appartiene a {$g['name']}: tolto da questa scheda, che resta com'era.", null);
        Log::write('crm', 'customer_code_reassigned', 'contact', $id, [
            'code' => $g['code'], 'was' => $existing['name'] ?? null, 'was_vat' => $existing['vat_number'] ?? null,
            'now' => $g['name'], 'now_vat' => $g['vat'], 'detached' => true,
        ]);
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
     *
     * The gestionale also packs two numbers into one cell — "0818817744/1483",
     * or with nothing between them at all: "347564877008183" is a mobile with
     * the start of a landline glued on. Joining every digit made numbers that
     * reach nobody (or somebody else), so the cell is split on / , ; and the
     * first part that can be a real number wins. An unseparated run too long
     * for one number is read as the country code typed without its + when that
     * fits ("393493543274"), cut to its mobile when it starts with 3, and
     * otherwise dropped rather than stored wrong — rowToFields() then flags the
     * cell as unreadable, and contactFields() leaves the card's number alone.
     */
    public static function phone(string $raw): string
    {
        foreach (preg_split('#[/,;]+#', trim($raw)) ?: [] as $i => $part) {
            // After the first, a part must stand on its own: "0982/81207" is not
            // an area code and a number, and "81207" alone reaches nobody.
            $p = self::onePhone(trim($part), $i > 0);
            if ($p !== '') {
                return $p;
            }
        }
        return '';
    }

    private static function onePhone(string $raw, bool $mustStandAlone): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) < 6) {
            return '';
        }
        if (str_starts_with($raw, '+')) {
            return self::plausible('+' . $digits);
        }
        if (str_starts_with($digits, '00')) {
            return self::plausible('+' . substr($digits, 2));
        }
        if ($mustStandAlone && !in_array($digits[0], ['0', '3'], true)) {
            return '';
        }
        if (strlen($digits) > 11) {
            if (str_starts_with($digits, '39') && self::plausible('+' . $digits) !== '') {
                return '+' . $digits;
            }
            return $digits[0] === '3' ? '+39' . substr($digits, 0, 10) : '';
        }
        return '+39' . $digits;
    }

    /** $p when it can be a real number (Italian: 6-11 digits after +39), else ''. */
    private static function plausible(string $p): string
    {
        if (str_starts_with($p, '+39')) {
            return preg_match('/^\+39\d{6,11}$/', $p) ? $p : '';
        }
        return preg_match('/^\+\d{8,15}$/', $p) ? $p : '';
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
