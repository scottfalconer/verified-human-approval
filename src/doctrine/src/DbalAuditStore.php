<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Doctrine;

use Doctrine\DBAL\Connection;
use VerifiedHumanApproval\Core\Audit\AuditStore;

/**
 * The signed security log's rows on the application's DBAL connection.
 *
 * Appenders are serialized before the head is read, on every database and for an empty log too: on SQLite by the
 * database's write reservation, elsewhere by locking the audit row of the lock table. Inside a transaction (the
 * approval's, or the host's own begun through DBAL) an append joins it, holds that lock until it ends, and never
 * commits or rolls it back; outside one, it runs in a short approval transaction of its own.
 *
 * Join only a READ COMMITTED transaction on PostgreSQL: under REPEATABLE READ the head can be older than the
 * newest row, and the append then fails on the sequence's uniqueness rather than fork the chain.
 *
 * @phpstan-import-type AuditRecord from AuditStore
 */
final readonly class DbalAuditStore implements AuditStore
{
    /**
     * The columns of a record. Not subject_key, the subject's digest for the index: a reading of the chain fetches
     * every row at once, and a column it never returns would still cost memory at every entry.
     */
    private const COLUMNS = 'seq, tenant_id, type, subject, entry, hash, key_id, signature';

    private Connection $connection;

    public function __construct(private DbalTransaction $transaction)
    {
        $this->connection = $transaction->connection();
    }

    public function append(callable $buildRecord): array
    {
        if (!$this->connection->isTransactionActive()) {
            return $this->transaction->run(fn (): array => $this->appendWithin($buildRecord));
        }

        return $this->appendWithin($buildRecord);
    }

    public function recordsFor(string $type, string $subject): iterable
    {
        $this->transaction->usePrimary();
        $rows = $this->connection->fetchAllAssociative('SELECT '.self::COLUMNS.' FROM '.ApprovalSchema::AUDIT.' WHERE type = ? AND subject_key = ? ORDER BY seq DESC', [$type, hash('sha256', $subject)]);

        return array_values(array_filter(array_map(self::row(...), $rows), static fn (array $row): bool => $row['type'] === $type && $row['subject'] === $subject));
    }

    public function records(): iterable
    {
        $this->transaction->usePrimary();

        return array_map(self::row(...), $this->connection->fetchAllAssociative('SELECT '.self::COLUMNS.' FROM '.ApprovalSchema::AUDIT.' ORDER BY seq'));
    }

    /**
     * @param callable(array{seq: int, hash: string}|null): AuditRecord $buildRecord
     *
     * @return AuditRecord
     */
    private function appendWithin(callable $buildRecord): array
    {
        $this->transaction->usePrimary();
        $dialect = $this->transaction->dialect();
        if ($dialect->sqlite) {
            // The host's transaction may have begun deferred. A write that changes nothing takes SQLite's write
            // reservation now and keeps it until that transaction ends. If the transaction can no longer become a
            // writer (it read an older snapshot), this fails, and the host rolls back.
            $this->connection->executeStatement('UPDATE '.ApprovalSchema::LOCKS.' SET name = name WHERE 1 = 0');
        } elseif (ApprovalSchema::AUDIT_LOCK !== $this->connection->fetchOne('SELECT name FROM '.ApprovalSchema::LOCKS.' WHERE name = ? '.$dialect->forUpdate(), [ApprovalSchema::AUDIT_LOCK])) {
            throw new \LogicException('The approval lock table is missing its rows: run ApprovalSchema::install() again.');
        }
        // A locking read, so even a REPEATABLE READ transaction reads the newest committed head.
        $head = $this->connection->fetchAssociative(
            $this->connection->getDatabasePlatform()->modifyLimitQuery('SELECT seq, hash FROM '.ApprovalSchema::AUDIT.' ORDER BY seq DESC', 1).($dialect->sqlite ? '' : ' '.$dialect->forUpdate()),
        );
        $row = $buildRecord(false === $head ? null : ['seq' => (int) $head['seq'], 'hash' => (string) $head['hash']]);
        $this->connection->insert(ApprovalSchema::AUDIT, [
            'seq' => $row['seq'], 'tenant_id' => $row['tenant_id'], 'type' => $row['type'], 'subject' => $row['subject'],
            'subject_key' => hash('sha256', $row['subject']), 'entry' => $row['entry'], 'hash' => $row['hash'],
            'key_id' => $row['key_id'], 'signature' => $row['signature'],
        ]);

        return $row;
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
