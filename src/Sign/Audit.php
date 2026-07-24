<?php
declare(strict_types=1);

namespace Glue\Sign;

use Glue\Db;

/**
 * The operation log — the part the whole exercise stands on.
 *
 * Two independent defences, because either one alone is weak:
 *
 *   append-only    triggers (migration 025) make UPDATE and DELETE on sign_audit
 *                  fail at the database, so not even this application can rewrite
 *                  history through the connection it already holds.
 *   hash chain     every row carries the hash of the row before it. Someone with
 *                  root, mysqldump and the table files can still forge rows — but
 *                  they cannot forge them *quietly*: any inserted, edited or
 *                  removed row breaks the chain from that point on, and verify()
 *                  reports the exact sequence number where it breaks.
 *
 * The head hash is printed on the signature certificate and covered by the PDF
 * signature, which is what stops the chain being rebuilt end-to-end: a rebuilt
 * chain no longer matches the hash inside a document signed with a key the
 * rebuilder does not have.
 */
final class Audit
{
    /** The chain's starting value — 64 zeros, so seq 1 has a defined prev_hash. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Append one entry. Returns the new row's hash, which is the chain head for
     * that document.
     *
     * $actor is ['type' => 'staff'|'customer'|'system', 'id' => ?int, 'label' => ?string].
     */
    public static function append(int $documentId, string $event, array $data = [], array $actor = []): string
    {
        $pdo = Db::pdo();

        // Serialise appends per document: two concurrent writers must not both
        // read the same head and build two rows claiming the same predecessor.
        // The unique key on (document_id, seq) is the backstop if they do.
        // If a caller already opened a transaction, join it rather than nesting —
        // PDO would throw, and the append is what matters, not who commits.
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT seq, hash FROM sign_audit WHERE document_id = ? ORDER BY seq DESC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$documentId]);
            $prev = $stmt->fetch();

            $seq      = $prev ? (int)$prev['seq'] + 1 : 1;
            $prevHash = $prev ? (string)$prev['hash'] : self::GENESIS;

            $row = [
                'document_id' => $documentId,
                'seq'         => $seq,
                'event'       => $event,
                'actor_type'  => (string)($actor['type'] ?? 'system'),
                'actor_id'    => isset($actor['id']) ? (int)$actor['id'] ?: null : null,
                'actor_label' => isset($actor['label']) ? mb_substr((string)$actor['label'], 0, 190) : null,
                'ip'          => self::clientIp(),
                'user_agent'  => self::userAgent(),
                'data'        => $data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'occurred_at' => self::now(),
                'prev_hash'   => $prevHash,
            ];
            $row['hash'] = self::hashOf($row);

            $pdo->prepare(
                'INSERT INTO sign_audit
                 (document_id, seq, event, actor_type, actor_id, actor_label, ip, user_agent,
                  data, occurred_at, prev_hash, hash)
                 VALUES (:document_id, :seq, :event, :actor_type, :actor_id, :actor_label, :ip, :user_agent,
                         :data, :occurred_at, :prev_hash, :hash)'
            )->execute($row);

            if ($own) {
                $pdo->commit();
            }
            return $row['hash'];
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The hash a row must have, given its own fields and its predecessor. Every
     * field that carries meaning is inside it, joined by a separator that cannot
     * occur in the values, so no two different rows can hash the same.
     */
    public static function hashOf(array $row): string
    {
        return hash('sha256', implode("\x1f", [
            (string)$row['document_id'],
            (string)$row['seq'],
            (string)$row['event'],
            (string)($row['actor_type'] ?? ''),
            (string)($row['actor_id'] ?? ''),
            (string)($row['actor_label'] ?? ''),
            (string)($row['ip'] ?? ''),
            (string)($row['user_agent'] ?? ''),
            (string)($row['data'] ?? ''),
            (string)$row['occurred_at'],
            (string)$row['prev_hash'],
        ]));
    }

    /** @return array<int,array<string,mixed>> the document's entries, oldest first */
    public static function forDocument(int $documentId): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM sign_audit WHERE document_id = ? ORDER BY seq');
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    /** The current chain head, or GENESIS when nothing has been logged yet. */
    public static function head(int $documentId): string
    {
        $stmt = Db::pdo()->prepare('SELECT hash FROM sign_audit WHERE document_id = ? ORDER BY seq DESC LIMIT 1');
        $stmt->execute([$documentId]);
        return (string)($stmt->fetchColumn() ?: self::GENESIS);
    }

    /**
     * Walk the chain and recompute every link.
     *
     * @return array{ok:bool, entries:int, head:string, broken_at:?int, reason:?string}
     */
    public static function verify(int $documentId): array
    {
        $rows = self::forDocument($documentId);
        $prevHash = self::GENESIS;
        $expectedSeq = 1;

        foreach ($rows as $row) {
            if ((int)$row['seq'] !== $expectedSeq) {
                return self::broken($rows, (int)$row['seq'], 'sequence jumps — an entry is missing');
            }
            if ((string)$row['prev_hash'] !== $prevHash) {
                return self::broken($rows, (int)$row['seq'], 'does not follow the previous entry');
            }
            if (!hash_equals((string)$row['hash'], self::hashOf($row))) {
                return self::broken($rows, (int)$row['seq'], 'contents do not match the recorded hash');
            }
            $prevHash = (string)$row['hash'];
            $expectedSeq++;
        }

        return [
            'ok' => true, 'entries' => count($rows), 'head' => $prevHash,
            'broken_at' => null, 'reason' => null,
        ];
    }

    private static function broken(array $rows, int $seq, string $reason): array
    {
        return [
            'ok' => false, 'entries' => count($rows), 'head' => '',
            'broken_at' => $seq, 'reason' => $reason,
        ];
    }

    // ---- request context ----------------------------------------------------------

    /** Millisecond precision — signing steps can land inside the same second. */
    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');
    }

    public static function clientIp(): ?string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? mb_substr($ip, 0, 45) : null;
    }

    public static function userAgent(): ?string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return $ua !== '' ? mb_substr($ua, 0, 255) : null;
    }
}
