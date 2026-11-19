<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use Doctrine\DBAL\Connection;
use VerifiedHumanApproval\Core\Audit\AuditStore;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalAuditStore;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\StoreContracts\AuditHarness;
use VerifiedHumanApproval\Tests\Support\TestDatabase;

/**
 * DbalAuditStore on one database. The host's transaction is begun through DBAL, as a host's code begins it; the
 * store joins that one, and owns a short approval transaction when there is none.
 */
abstract class DbalAuditHarness extends AuditHarness
{
    /** One of TestDatabase::KINDS. */
    protected const KIND = 'sqlite';

    private readonly Connection $connection;
    private readonly DbalAuditStore $store;

    final public function __construct(private readonly string $location)
    {
        $this->connection = TestDatabase::connect(static::KIND, $location);
        $this->store = new DbalAuditStore(new DbalTransaction($this->connection));
    }

    public static function prepare(): string
    {
        $location = TestDatabase::create(static::KIND);
        $connection = TestDatabase::connect(static::KIND, $location);
        ApprovalSchema::install($connection);
        $connection->executeStatement('CREATE TABLE business (note VARCHAR(64) NOT NULL)');
        $connection->close();

        return $location;
    }

    public static function destroy(string $location): void
    {
        TestDatabase::drop(static::KIND, $location);
    }

    public static function connect(string $location): static
    {
        return new static($location);
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function store(): AuditStore
    {
        return $this->store;
    }

    public function begin(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->connection->isTransactionActive();
    }

    public function writeBusinessRow(): void
    {
        $this->connection->executeStatement("INSERT INTO business (note) VALUES ('revoked')");
    }

    public function businessRows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM business');
    }

    public function read(): void
    {
        $this->connection->fetchOne('SELECT COUNT(*) FROM '.self::TABLE);
    }

    public function lockTimeout(int $milliseconds): void
    {
        TestDatabase::lockTimeout(static::KIND, $this->connection, $milliseconds);
    }

    public function isLockTimeout(\Throwable $error): bool
    {
        return TestDatabase::isLockTimeout($error);
    }

    public function readersBlockWriters(): bool
    {
        return 'sqlite' === static::KIND;
    }

    public function appendsFromAnOlderViewFail(): bool
    {
        return 'sqlite' === TestDatabase::platform(static::KIND);
    }

    public function failInserts(): void
    {
        foreach (match (TestDatabase::platform(static::KIND)) {
            'sqlite' => ['CREATE TRIGGER reject_audit BEFORE INSERT ON '.self::TABLE." BEGIN SELECT RAISE(ABORT, 'injected insert failure'); END"],
            'postgresql' => [
                "CREATE OR REPLACE FUNCTION reject_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected insert failure'; END \$\$",
                'CREATE TRIGGER reject_audit BEFORE INSERT ON '.self::TABLE.' FOR EACH ROW EXECUTE FUNCTION reject_audit()',
            ],
            'mysql' => ['CREATE TRIGGER reject_audit BEFORE INSERT ON '.self::TABLE." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected insert failure'"],
        } as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    public function clearInsertFailures(): void
    {
        $this->connection->executeStatement('postgresql' === TestDatabase::platform(static::KIND) ? 'DROP TRIGGER IF EXISTS reject_audit ON '.self::TABLE : 'DROP TRIGGER IF EXISTS reject_audit');
    }

    public function insertRaw(array $row): void
    {
        // As a migration from another store would: the store's own digest of the subject, and nothing else added.
        $this->connection->insert(self::TABLE, $row + ['subject_key' => hash('sha256', $row['subject'])]);
    }

    public function damage(int $seq, string $assignment): void
    {
        $this->connection->executeStatement('UPDATE '.self::TABLE.' SET '.$assignment.' WHERE seq = ?', [$seq]);
    }

    public function column(int $seq, string $column): string
    {
        return (string) $this->connection->fetchOne('SELECT '.$column.' FROM '.self::TABLE.' WHERE seq = ?', [$seq]);
    }
}
