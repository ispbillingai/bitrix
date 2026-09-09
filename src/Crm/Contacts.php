<?php
declare(strict_types=1);

namespace Glue\Crm;

use Glue\Db;
use Glue\Reminder\Templates;
use PDO;

/**
 * Contacts — the people/companies behind leads and deals. findOrCreate() de-dupes
 * on phone/email so the same customer submitting two requests doesn't fan out into
 * two contact records.
 */
final class Contacts
{
    /**
     * Nome + Cognome as they are typed, into the single joined name.
     * contacts.name holds the join; first_name/last_name hold the parts.
     */
    public static function fullName(string $first, string $last): string
    {
        return trim(trim($first) . ' ' . trim($last));
    }

    /**
     * The three name columns for one write, from whatever the caller passed.
     *
     * Callers come in two shapes and both must fill all three: the dashboard
     * forms send first_name/last_name, while the public forms and the mailbox
     * importer send a single name. Whichever arrives, the other is derived here
     * rather than at each call site, so no write path can leave a row half-split.
     *
     * @return array{name:string, first_name:string, last_name:string}
     */
    public static function nameParts(array $d): array
    {
        $first = trim((string)($d['first_name'] ?? ''));
        $last  = trim((string)($d['last_name'] ?? ''));
        $name  = trim((string)($d['name'] ?? ''));

        if ($first !== '' || $last !== '') {
            $name = self::fullName($first, $last) ?: ($name ?: 'Unknown');
            return ['name' => $name, 'first_name' => $first, 'last_name' => $last];
        }

        $name = $name ?: 'Unknown';
        [$first, $last] = self::splitName($name);
        return ['name' => $name, 'first_name' => $first, 'last_name' => $last];
    }

    /**
     * The inverse, for putting a stored name back into two boxes:
     * "Mario De Luca" -> ["Mario", "De Luca"].
     *
     * Split at the FIRST space, because an Italian surname carries a particle
     * ("De Luca", "Lo Russo") far more often than a first name is compound, so
     * the remainder is the better surname guess. It is only ever a prefill —
     * whoever is looking at the two boxes can correct them.
     *
     * @return array{0:string, 1:string}
     */
    public static function splitName(string $full): array
    {
        $parts = array_pad(explode(' ', trim($full), 2), 2, '');
        return [trim($parts[0]), trim($parts[1])];
    }

    /**
     * Find an existing contact by phone or email, else create one.
     * @return int contact id
     */
    /**
     * A VAT number worth matching people on, or '' when it is not.
     *
     * The partita IVA is the surest identifier a business has — Leads::duplicateId
     * has always said so — but the registry is full of placeholders that are NOT
     * identifiers: 00000000000 is shared by a doctor and a restaurant,
     * OO99999999999 by three unrelated companies. Matching on those would weld
     * strangers together, which is far worse than the duplicate it would prevent.
     *
     * The test is deliberately blunt: at least eight characters and at least four
     * DISTINCT ones. Real VATs pass (10216761212 has five distinct digits); the
     * placeholders, which are one character repeated, do not.
     */
    public static function matchableVat(string $vat): string
    {
        $v = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '', $vat));
        if (strlen($v) < 8) {
            return '';
        }
        return count(array_unique(str_split($v))) >= 4 ? $v : '';
    }

    /**
     * The contact these details belong to, creating one only when nobody matches.
     *
     * VAT FIRST. It used to be phone-or-email only, and that is how a lead typed
     * in for a customer the CRM already had — same partita IVA, same company name
     * — landed on a brand-new contact instead: the agent's mobile number for the
     * person was not the number on the registry row for the company. The customer
     * existed, the VAT matched, and the link was lost anyway. Where several rows
     * share a VAT, the registry entry (is_customer) wins, because that is the one
     * carrying the invoices, the contracts and the gestionale code.
     *
     * A contact matched on phone or email that has no VAT yet is given the one
     * supplied here, so the next lead for the same business matches on it.
     */
    public static function findOrCreate(array $d): int
    {
        $phone = trim((string)($d['phone'] ?? ''));
        $email = trim((string)($d['email'] ?? ''));
        $vat   = self::matchableVat((string)($d['vat_number'] ?? ''));

        if ($vat !== '') {
            $stmt = Db::pdo()->prepare(
                "SELECT id FROM contacts WHERE vat_number <> '' AND vat_number = :vat
                  ORDER BY is_customer DESC, id ASC LIMIT 1"
            );
            $stmt->execute([':vat' => $vat]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        if ($phone !== '' || $email !== '') {
            $stmt = Db::pdo()->prepare(
                'SELECT id FROM contacts
                 WHERE (phone <> "" AND phone = :phone) OR (email <> "" AND email = :email)
                 ORDER BY id ASC LIMIT 1'
            );
            $stmt->execute([':phone' => $phone, ':email' => $email]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                if ($vat !== '') {
                    Db::pdo()->prepare(
                        "UPDATE contacts SET vat_number = ? WHERE id = ? AND (vat_number IS NULL OR vat_number = '')"
                    )->execute([$vat, $id]);
                }
                return $id;
            }
        }
        return self::create($d);
    }

    public static function create(array $d): int
    {
        $n = self::nameParts($d);
        $stmt = Db::pdo()->prepare(
            'INSERT INTO contacts (name, first_name, last_name, company, vat_number, phone, email, lang, source, assigned_to, notes)
             VALUES (:name, :first_name, :last_name, :company, :vat_number, :phone, :email, :lang, :source, :assigned_to, :notes)'
        );
        $stmt->execute([
            ':name'        => $n['name'],
            ':first_name'  => $n['first_name'],
            ':last_name'   => $n['last_name'],
            ':company'     => $d['company'] ?? null,
            // Stored, at last. The column existed and the value was handed in,
            // but the INSERT never listed it — so a contact born from a lead had
            // no VAT, and nothing could ever match it on one afterwards.
            ':vat_number'  => strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '',
                                (string)($d['vat_number'] ?? ''))) ?: null,
            ':phone'       => trim((string)($d['phone'] ?? '')) ?: null,
            ':email'       => trim((string)($d['email'] ?? '')) ?: null,
            ':lang'        => Templates::lang($d['lang'] ?? null),
            ':source'      => $d['source'] ?? null,
            ':assigned_to' => $d['assigned_to'] ?? null,
            ':notes'       => $d['notes'] ?? null,
        ]);
        return (int)Db::pdo()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM contacts WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array> recent contacts (newest first) */
    public static function all(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        return Db::pdo()->query("SELECT * FROM contacts ORDER BY id DESC LIMIT $limit")->fetchAll();
    }

    /**
     * Edit a contact. A caller touching any name field re-derives all three, so
     * name can never drift out of step with the two parts it is built from.
     */
    public static function update(int $id, array $fields): void
    {
        if (isset($fields['first_name']) || isset($fields['last_name']) || isset($fields['name'])) {
            $fields = array_merge($fields, self::nameParts($fields));
        }
        $allowed = ['name', 'first_name', 'last_name', 'company', 'phone', 'phone2', 'email', 'pec', 'lang', 'source', 'assigned_to', 'notes',
                    // customer-registry fields (migration 038, edited from the Customers page)
                    'customer_code', 'vat_number', 'is_customer', 'address', 'city', 'province', 'zip',
                    'balance', 'contract_expiry', 'gestionale_agent'];
        $set = [];
        $args = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $set[] = "$k = ?";
                $args[] = $v;
            }
        }
        if (!$set) {
            return;
        }
        $args[] = $id;
        Db::pdo()->prepare('UPDATE contacts SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    }

    public static function count(): int
    {
        return (int)Db::pdo()->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    }
}
