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
        $stmt = Db::pdo()->prepare(
            'INSERT INTO contacts (name, company, phone, email, lang, source, assigned_to, notes)
             VALUES (:name, :company, :phone, :email, :lang, :source, :assigned_to, :notes)'
        );
        $stmt->execute([
            ':name'        => trim((string)($d['name'] ?? '')) ?: 'Unknown',
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

    public static function update(int $id, array $fields): void
    {
        $allowed = ['name', 'company', 'phone', 'email', 'lang', 'source', 'assigned_to', 'notes'];
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
