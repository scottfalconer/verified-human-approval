<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;

/**
 * The one root transaction an approval runs in, on the application's own DBAL connection, shared by the approval
 * store, the audit log, the passkey repository and the protected change. It keeps the ApprovalStore rules:
 *
 * - It refuses to start inside any other transaction, so a normal return always means the root committed.
 * - It begins through DBAL, so an ORM flush inside nests as a savepoint instead of failing.
 * - Before anything is read, SQLite takes the database's write reservation (what BEGIN IMMEDIATE does), and
 *   PostgreSQL, MySQL and MariaDB switch to READ COMMITTED, so each statement after a lock sees what committed
 *   before it. There, the stores lock their own rows, and the application locks the rows its decision reads,
 *   with forUpdate() and forShare().
 * - It runs the callback once, never retries, and rolls back on any Throwable, rethrowing the original error even
 *   when the rollback fails too.
 * - It refuses the approval store's writes and the passkey counter's update once the root has ended or been
 *   replaced underneath, and inside a nested transaction (assertActive()). The audit store's appends and the
 *   passkey repository's add() and revoke() also run in the host's own transactions, so they are not checked
 *   this way.
 *
 * Use the same connection for every protected write: the ORM's entity manager, the stores and your own queries.
 */
final class DbalTransaction
{
    /** Marks the root transaction, so a root that was ended or replaced underneath is caught before a write and before commit. */
    private const ROOT = 'verified_human_approval_root';

    private const NESTED = 'The approval service must own the outermost database transaction, and one is already open on this connection. Do not call ApprovalService inside Connection::transactional(), EntityManager::wrapInTransaction() or a Messenger handler behind the doctrine_transaction middleware: call it outside that transaction, or from a handler without that middleware.';

    private bool $active = false;
    private ?Dialect $dialect = null;
    private bool $seeded = false;

    /** @var list<callable(): void> */
    private array $onRollback = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * Runs $callback once inside the root transaction and returns its result after the commit.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function run(callable $callback): mixed
    {
        if (!$this->connection->isAutoCommit()) {
            throw new \LogicException('The approval transaction needs the connection in auto-commit mode, so that it can be the root transaction.');
        }
        if ($this->active || $this->connection->isTransactionActive() || $this->nativeTransactionActive()) {
            throw new \LogicException(self::NESTED);
        }
        $dialect = $this->dialect();
        $this->usePrimary();
        if ($dialect->locksRows() && !$this->seeded) {
            // A migration creates the lock table empty: its rows are added once, outside any transaction.
            ApprovalSchema::seed($this->connection);
            $this->seeded = true;
        }
        if ($dialect->mysql) {
            // Applies to the next transaction only, so the application's other transactions keep their level.
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        try {
            $this->connection->beginTransaction();
        } catch (\Throwable $error) {
            // DBAL counts a transaction whose BEGIN failed as open.
            $this->abandon();
            // SQLite's PDO driver does not see a transaction begun with plain SQL; SQLite itself refuses the BEGIN.
            if (str_contains($error->getMessage(), 'within a transaction')) {
                throw new \LogicException(self::NESTED, 0, $error);
            }
            throw $error;
        }
        $this->active = true;
        $this->onRollback = [];
        try {
            if ($dialect->postgresql) {
                $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            } elseif ($dialect->sqlite) {
                // A write that changes nothing takes the database's write reservation now, as BEGIN IMMEDIATE does,
                // and keeps it to the end: no other writer can change what the approval reads until it commits.
                $this->connection->executeStatement('UPDATE '.ApprovalSchema::LOCKS.' SET name = name WHERE 1 = 0');
            }
            $this->connection->executeStatement('SAVEPOINT '.self::ROOT);
            $result = $callback();
            if (1 !== $this->connection->getTransactionNestingLevel()) {
                throw new \LogicException('The approval callback ended the root transaction, or left a nested one open.');
            }
            // Fails if the root was rolled back and begun again, committed implicitly (MySQL commits on a schema
            // change), or aborted by a failed statement, which PostgreSQL would answer COMMIT with a silent rollback.
            $this->releaseRoot();
            $this->connection->commit();
            if ($this->connection->isTransactionActive() || $this->nativeTransactionActive()) {
                throw new \LogicException('The approval root transaction did not finish.');
            }

            return $result;
        } catch (\Throwable $error) {
            $this->abandon();
            foreach ($this->onRollback as $listener) {
                try {
                    $listener();
                } catch (\Throwable) {
                    // The failure that rolled the transaction back is the one to report.
                }
            }
            throw $error;
        } finally {
            $this->active = false;
            $this->onRollback = [];
        }
    }

    /** Whether the approval transaction is open on this connection. */
    public function isActive(): bool
    {
        return $this->active && $this->connection->isTransactionActive();
    }

    /**
     * Refuses a write outside the approval transaction, including one after code on the connection ended or replaced
     * the root past DBAL, through the driver or in SQL, or the database rolled it back by itself after an error. DBAL
     * still counts the root then, and the write would commit on its own. Releasing the root's savepoint and setting it
     * again shows whether the root is still open. Inside a nested transaction that would end the nested one too, and
     * DBAL's count is no guide there either: once the root under it has ended, DBAL fails to roll a nested transaction
     * back and still counts it. So the approval's writes run at the root, and a write inside a nested transaction is
     * refused.
     */
    public function assertActive(): void
    {
        if (!$this->isActive()) {
            throw new \LogicException('Approval writes require the approval transaction (DbalTransaction::run()).');
        }
        if (1 !== $this->connection->getTransactionNestingLevel()) {
            throw new \LogicException('Approval writes run at the root of the approval transaction, and a nested transaction is open on it.');
        }
        $this->releaseRoot();
        $this->connection->executeStatement('SAVEPOINT '.self::ROOT);
    }

    /**
     * Registers what to undo in memory if the approval transaction rolls back, such as resetting an entity manager
     * whose objects now describe rows that were never committed. Valid only inside the transaction, nested
     * transactions included.
     *
     * @param callable(): void $listener
     */
    public function onRollback(callable $listener): void
    {
        if (!$this->isActive()) {
            throw new \LogicException('Rollback listeners require the approval transaction (DbalTransaction::run()).');
        }
        $this->onRollback[] = $listener;
    }

    /**
     * The clause that locks the selected rows for writing until commit: append it to a SELECT of the content the
     * approved action will change. Empty on SQLite, where the approval transaction holds the database's write lock.
     */
    public function forUpdate(): string
    {
        return $this->dialect()->forUpdate();
    }

    /**
     * The clause that keeps the selected rows from changing until commit: append it to a SELECT of the permissions,
     * grants and policies the decision reads. Empty on SQLite, for the same reason.
     */
    public function forShare(): string
    {
        return $this->dialect()->forShare();
    }

    /** @internal for the stores */
    public function dialect(): Dialect
    {
        return $this->dialect ??= Dialect::of($this->connection->getDatabasePlatform());
    }

    /**
     * Sends the next reads to the primary database, never a replica.
     *
     * @internal for the stores
     */
    public function usePrimary(): void
    {
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
    }

    /**
     * Ends whatever this attempt left open. DBAL's levels are unwound first; then a driver transaction DBAL no longer
     * counts (SQLite keeps one open after a busy COMMIT) is rolled back on the driver. Only if a level cannot be
     * unwound is the connection closed, which ends the transaction on the server and resets DBAL's count.
     */
    private function abandon(): void
    {
        try {
            while ($this->connection->isTransactionActive()) {
                $level = $this->connection->getTransactionNestingLevel();
                try {
                    $this->connection->rollBack();
                } catch (\Throwable $error) {
                    // At the root, DBAL closes the level before it asks the driver, so only a stuck savepoint remains.
                    if ($this->connection->getTransactionNestingLevel() >= $level) {
                        throw $error;
                    }
                }
            }
            if ($this->nativeTransactionActive()) {
                $native = $this->connection->getNativeConnection();
                \assert($native instanceof \PDO);
                $native->rollBack();
            }
        } catch (\Throwable) {
            try {
                $this->connection->close();
            } catch (\Throwable) {
                // Nothing more can be done here; the original error is the one reported.
            }
        }
    }

    /** Releases the savepoint that marks the root, which fails once the root is no longer the open transaction. */
    private function releaseRoot(): void
    {
        try {
            $this->connection->executeStatement('RELEASE SAVEPOINT '.self::ROOT);
        } catch (\Throwable $error) {
            throw new \LogicException('The approval transaction was committed, rolled back, replaced or aborted before the approval finished, so nothing more is written in it.', 0, $error);
        }
    }

    private function nativeTransactionActive(): bool
    {
        if (!$this->connection->isConnected()) {
            return false;
        }
        $native = $this->connection->getNativeConnection();

        return $native instanceof \PDO && $native->inTransaction();
    }
}
