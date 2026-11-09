<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Storage;

use VerifiedHumanApproval\Core\Audit\AuditStore;

/**
 * SQLite reference adapter. Share the application's PDO connection.
 *
 * @phpstan-import-type AuditRecord from AuditStore
 */
final readonly class PdoAuditStore implements AuditStore
{
    public function __construct(private \PDO $pdo)
    {
        if ('sqlite' !== $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            throw new \InvalidArgumentException('This audit adapter supports SQLite only; supply an AuditStore for other databases.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function initialize(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS verified_human_approval_audit (
            seq INTEGER PRIMARY KEY, tenant_id TEXT NOT NULL, type TEXT NOT NULL, subject TEXT NOT NULL,
            entry TEXT NOT NULL, hash TEXT NOT NULL, key_id TEXT NOT NULL, signature TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS verified_human_approval_audit_subject ON verified_human_approval_audit(type, subject)');
    }

    public function append(callable $buildRecord): array
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        }
        try {
            // A joined transaction may be deferred. Reserve the SQLite writer
            // before reading the head, even when this table has no rows yet.
            $this->pdo->exec('UPDATE verified_human_approval_audit SET seq = seq WHERE 0');
            $query = $this->pdo->prepare('SELECT seq, hash FROM verified_human_approval_audit ORDER BY seq DESC LIMIT 1');
            $query->execute();
            $last = $query->fetch(\PDO::FETCH_ASSOC);
            $record = $buildRecord(false === $last ? null : ['seq' => (int) $last['seq'], 'hash' => $last['hash']]);
            $this->pdo->prepare('INSERT INTO verified_human_approval_audit (seq, tenant_id, type, subject, entry, hash, key_id, signature) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$record['seq'], $record['tenant_id'], $record['type'], $record['subject'], $record['entry'], $record['hash'], $record['key_id'], $record['signature']]);
            if ($own) {
                $this->pdo->exec('COMMIT');
            }

            return $record;
        } catch (\Throwable $error) {
            if ($own) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                    // Preserve the original failure, including an uncertain commit.
                }
            }
            throw $error;
        }
    }

    /** @return list<AuditRecord> */
    public function recordsFor(string $type, string $subject): iterable
    {
        $query = $this->pdo->prepare('SELECT * FROM verified_human_approval_audit WHERE type = ? AND subject = ? ORDER BY seq DESC');
        $query->execute([$type, $subject]);

        return array_values($query->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<AuditRecord> */
    public function records(): iterable
    {
        $query = $this->pdo->prepare('SELECT * FROM verified_human_approval_audit ORDER BY seq');
        $query->execute();

        return array_values($query->fetchAll(\PDO::FETCH_ASSOC));
    }
}
