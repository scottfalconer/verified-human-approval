<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Storage;

use VerifiedHumanApproval\Core\Audit\AuditStore;

/**
 * The signed security log's rows in SQLite, on the application's own PDO connection.
 *
 * A host transaction it joins must be started with PDO::beginTransaction(), so that PDO reports it, as
 * PdoApprovalStore starts the approval transaction; this store then never commits or rolls it back.
 *
 * @phpstan-import-type AuditHead from AuditStore
 * @phpstan-import-type AuditRecord from AuditStore
 */
final readonly class PdoAuditStore implements AuditStore
{
    private const TABLE = 'verified_human_approval_audit';

    /** Works on the connection as it is, as PdoApprovalStore does, and refuses one that does not throw on errors. */
    public function __construct(private \PDO $pdo)
    {
        if ('sqlite' !== $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            throw new \InvalidArgumentException('This audit adapter supports SQLite only; supply an AuditStore for other databases.');
        }
        if (\PDO::ERRMODE_EXCEPTION !== $pdo->getAttribute(\PDO::ATTR_ERRMODE)) {
            throw new \InvalidArgumentException('This adapter needs a connection that throws on errors: set PDO::ATTR_ERRMODE to PDO::ERRMODE_EXCEPTION.');
        }
    }

    /** Creates the table and its index. An installation step: never part of an append. */
    public function initialize(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
            seq INTEGER PRIMARY KEY, tenant_id TEXT NOT NULL, type TEXT NOT NULL, subject TEXT NOT NULL,
            entry TEXT NOT NULL, hash TEXT NOT NULL, key_id TEXT NOT NULL, signature TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS verified_human_approval_audit_subject ON '.self::TABLE.'(type, subject)');
    }

    public function append(callable $buildRecord): array
    {
        $own = !$this->pdo->inTransaction();
        // Appenders are serialized before the head is read, so two of them can never fork the chain.
        if ($own) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            // The host's transaction may have begun deferred. A write that changes nothing takes SQLite's
            // write reservation now, even for an empty table, and keeps it until the host's transaction ends.
            // If this transaction can no longer become a writer, that fails here and the host rolls back.
            $this->pdo->exec('UPDATE '.self::TABLE.' SET seq = seq WHERE 0');
        }
        try {
            $newest = $this->pdo->query('SELECT seq, hash FROM '.self::TABLE.' ORDER BY seq DESC LIMIT 1');
            $head = false === $newest ? false : $newest->fetch(\PDO::FETCH_ASSOC);
            $row = $buildRecord(\is_array($head) ? ['seq' => (int) $head['seq'], 'hash' => (string) $head['hash']] : null);
            $this->pdo->prepare('INSERT INTO '.self::TABLE.' (seq, tenant_id, type, subject, entry, hash, key_id, signature) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$row['seq'], $row['tenant_id'], $row['type'], $row['subject'], $row['entry'], $row['hash'], $row['key_id'], $row['signature']]);
            if ($own) {
                $this->pdo->exec('COMMIT');
            }
        } catch (\Throwable $error) {
            if ($own) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                    // The original failure is the one to report.
                }
            }
            throw $error;
        }

        return $row;
    }

    public function recordsFor(string $type, string $subject): iterable
    {
        $query = $this->pdo->prepare('SELECT * FROM '.self::TABLE.' WHERE type = ? AND subject = ? ORDER BY seq DESC');
        $query->execute([$type, $subject]);

        return array_map(self::row(...), $query->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function records(): iterable
    {
        $query = $this->pdo->prepare('SELECT * FROM '.self::TABLE.' ORDER BY seq');
        $query->execute();

        return array_map(self::row(...), $query->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return AuditRecord
     */
    private static function row(array $row): array
    {
        return [
            'seq' => (int) $row['seq'], 'tenant_id' => (string) $row['tenant_id'], 'type' => (string) $row['type'],
            'subject' => (string) $row['subject'], 'entry' => (string) $row['entry'], 'hash' => (string) $row['hash'],
            'key_id' => (string) $row['key_id'], 'signature' => (string) $row['signature'],
        ];
    }
}
