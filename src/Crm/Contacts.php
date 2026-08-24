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
    public static function findOrCreate(array $d): int
    {
        $phone = trim((string)($d['phone'] ?? ''));
        $email = trim((string)($d['email'] ?? ''));

        if ($phone !== '' || $email !== '') {
            $stmt = Db::pdo()->prepare(
                'SELECT id FROM contacts
                 WHERE (phone <> "" AND phone = :phone) OR (email <> "" AND email = :email)
                 ORDER BY id ASC LIMIT 1'
            );
            $stmt->execute([':phone' => $phone, ':email' => $email]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }
        return self::create($d);
    }

    public static function create(array $d): int
    {
        $n = self::nameParts($d);
        $stmt = Db::pdo()->prepare(
            'INSERT INTO contacts (name, first_name, last_name, company, phone, email, lang, source, assigned_to, notes)
             VALUES (:name, :first_name, :last_name, :company, :phone, :email, :lang, :source, :assigned_to, :notes)'
        );
        $stmt->execute([
            ':name'        => $n['name'],
            ':first_name'  => $n['first_name'],
            ':last_name'   => $n['last_name'],
            ':company'     => $d['company'] ?? null,
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
        $allowed = ['name', 'first_name', 'last_name', 'company', 'phone', 'email', 'lang', 'source', 'assigned_to', 'notes'];
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
