<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Tests\Support\TestDatabase;

/**
 * The approval transaction's own rules on one database: it owns the root and refuses any other, an ORM-style nested
 * transaction becomes a savepoint, a callback that ends, replaces or leaves open a transaction is caught before
 * commit, the original error survives a failed rollback, and each database serializes the way the stores need.
 */
abstract class DbalTransactionTestCase extends TestCase
{
    /** One of TestDatabase::KINDS. */
    protected const KIND = 'sqlite';

    private ?string $location = null;
    private Connection $connection;
    private DbalTransaction $transaction;

    protected function setUp(): void
    {
        $this->location = TestDatabase::create(static::KIND);
        $this->connection = TestDatabase::connect(static::KIND, $this->location);
        ApprovalSchema::install($this->connection);
        $this->connection->executeStatement('CREATE TABLE notes (id INTEGER NOT NULL PRIMARY KEY, note VARCHAR(64) NOT NULL)');
        $this->transaction = new DbalTransaction($this->connection);
    }

    protected function tearDown(): void
    {
        // Closed before the drop, which a transaction a failed test left open on it would otherwise hold up.
        if (isset($this->connection)) {
            $this->connection->close();
        }
        if (null !== $this->location) {
            TestDatabase::drop(static::KIND, $this->location);
        }
    }

    public function testItRefusesToStartInsideAnyOtherTransaction(): void
    {
        $refused = function (): void {
            try {
                $this->transaction->run(fn () => $this->note(1));
                self::fail('The approval transaction started inside another one.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('outermost', $error->getMessage());
                self::assertStringContainsString('wrapInTransaction()', $error->getMessage(), 'It names the ORM transaction to avoid.');
                self::assertStringContainsString('doctrine_transaction', $error->getMessage(), 'It names the Messenger middleware.');
            }
        };
        $this->connection->beginTransaction();
        $refused();
        self::assertSame(1, $this->connection->getTransactionNestingLevel(), 'The other transaction is left as it was.');
        $this->connection->rollBack();

        $this->connection->transactional(static fn () => $refused());

        $native = $this->connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $native->beginTransaction();
        $refused();
        self::assertTrue($native->inTransaction());
        $native->rollBack();

        // Begun with plain SQL, which neither DBAL nor, on SQLite, PDO counts.
        $this->connection->executeStatement('mysql' === TestDatabase::platform(static::KIND) ? 'START TRANSACTION' : 'BEGIN');
        $this->connection->insert('notes', ['id' => 7, 'note' => 'the host’s own']);
        $refused();
        $this->connection->executeStatement('ROLLBACK');
        self::assertSame([], $this->notes(), 'The host’s own transaction was left to the host, and it rolled back.');

        $this->transaction->run(function () use ($refused): void {
            $refused();
            $this->note(2);
        });
        self::assertSame([2], $this->notes(), 'A refused nested run does not disturb the one it was called in.');
    }

    public function testANestedTransactionInsideItIsASavepoint(): void
    {
        $this->transaction->run(function (): void {
            $this->note(1);
            // What an ORM flush does inside the approval transaction.
            $this->connection->beginTransaction();
            $this->note(2);
            $this->connection->commit();
            $this->connection->beginTransaction();
            $this->note(3);
            $this->connection->rollBack();
            self::assertSame(1, $this->connection->getTransactionNestingLevel());
        });
        self::assertSame([1, 2], $this->notes());
    }

    public function testACallbackThatEndsReplacesOrLeavesOpenATransactionIsCaughtBeforeCommit(): void
    {
        foreach ([
            'committed the root' => function (): void {
                $this->note(1);
                $this->connection->commit();
            },
            'rolled the root back and began another' => function (): void {
                $this->note(2);
                $this->connection->rollBack();
                $this->connection->beginTransaction();
                $this->note(3);
            },
            'left a nested transaction open' => function (): void {
                $this->connection->beginTransaction();
                $this->note(4);
            },
        ] as $what => $callback) {
            try {
                $this->transaction->run($callback);
                self::fail('The approval transaction reported success after its callback '.$what.'.');
            } catch (\Throwable) {
            }
            self::assertFalse($this->connection->isTransactionActive(), 'Nothing is left open after it '.$what.'.');
        }
        // Only what the first callback committed itself, outside the approval's control, remains.
        self::assertSame([1], $this->notes());
        $this->transaction->run(fn () => $this->note(5));
        self::assertSame([1, 5], $this->notes(), 'The connection works afterwards.');
    }

    public function testAWriteAfterTheRootEndedPastDbalIsRefused(): void
    {
        $native = $this->connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        foreach ([
            'committed it through the driver' => static fn () => $native->commit(),
            'rolled it back through the driver' => static fn () => $native->rollBack(),
            'rolled it back through the driver and began another' => static fn () => $native->rollBack() && $native->beginTransaction(),
            'rolled it back in SQL' => fn () => $this->connection->executeStatement('ROLLBACK'),
        ] as $what => $end) {
            $written = false;
            try {
                $this->transaction->run(function () use ($end, &$written): void {
                    $end();
                    // DBAL still counts the root here, so only the approval transaction's own check can tell.
                    self::assertTrue($this->transaction->isActive());
                    $this->transaction->assertActive();
                    $this->note(5);
                    $written = true;
                });
                self::fail('The approval transaction reported success after the callback '.$what.'.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('before the approval finished', $error->getMessage(), $what);
            }
            self::assertFalse($written, 'The write after the callback '.$what.' was refused before it ran.');
            self::assertFalse($this->connection->isTransactionActive(), 'Nothing is left open after it '.$what.'.');
        }
        self::assertSame([], $this->notes(), 'No write after the root ended reached the database.');
        $this->transaction->run(fn () => $this->note(1));
        self::assertSame([1], $this->notes(), 'The connection works afterwards.');
    }

    /**
     * The checks that show whether the root is still open would end a nested transaction, and DBAL cannot roll one
     * back once the root under it has ended, so it still counts it. A write inside a nested transaction is refused:
     * the approval's writes all run at the root.
     */
    public function testAWriteInsideANestedTransactionIsRefused(): void
    {
        // Asked for each time: a rollback DBAL cannot finish closes the connection, and the next one has its own.
        $native = function (): \PDO {
            $native = $this->connection->getNativeConnection();
            self::assertInstanceOf(\PDO::class, $native);

            return $native;
        };
        foreach ([
            'left a nested transaction open' => fn () => $this->connection->beginTransaction(),
            'rolled the root back through the driver inside a nested transaction' => function () use ($native): void {
                $this->connection->beginTransaction();
                $native()->rollBack();
            },
            'rolled the root back inside transactional(), and ignored the failure' => function () use ($native): void {
                try {
                    $this->connection->transactional(static function () use ($native): void {
                        $native()->rollBack();
                        throw new \RuntimeException('The best-effort work failed.');
                    });
                } catch (\Throwable) {
                }
            },
            'rolled the root back in SQL inside transactional(), and ignored the failure' => function (): void {
                try {
                    $this->connection->transactional(function (): void {
                        $this->connection->executeStatement('ROLLBACK');
                        throw new \RuntimeException('The best-effort work failed.');
                    });
                } catch (\Throwable) {
                }
            },
        ] as $what => $callback) {
            $written = false;
            try {
                $this->transaction->run(function () use ($callback, &$written): void {
                    $callback();
                    self::assertTrue($this->transaction->isActive());
                    $this->transaction->assertActive();
                    $this->note(6);
                    $written = true;
                });
                self::fail('The approval transaction reported success after the callback '.$what.'.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('nested transaction', $error->getMessage(), $what);
            }
            self::assertFalse($written, 'The write after the callback '.$what.' was refused before it ran.');
            self::assertFalse($this->connection->isTransactionActive(), 'Nothing is left open after it '.$what.'.');
        }
        self::assertSame([], $this->notes(), 'No write inside a nested transaction reached the database.');

        // Once a nested transaction has ended, as an ORM flush ends its own, the root takes writes again.
        $this->transaction->run(function (): void {
            $this->connection->beginTransaction();
            $this->note(1);
            $this->connection->commit();
            $this->transaction->assertActive();
            $this->note(2);
        });
        self::assertSame([1, 2], $this->notes());
    }

    public function testAStatementThatFailedInsideItNeverReadsAsACommit(): void
    {
        try {
            $this->transaction->run(function (): void {
                $this->note(1);
                try {
                    $this->note(1); // a duplicate key, swallowed by the callback, which then returns
                } catch (DriverException) {
                }
            });
            $outcome = 'committed';
        } catch (\Throwable) {
            $outcome = 'refused';
        }
        if ('postgresql' === TestDatabase::platform(static::KIND)) {
            // PostgreSQL aborted the transaction at the failure, and would answer COMMIT with a silent rollback.
            self::assertSame('refused', $outcome);
            self::assertSame([], $this->notes());
        } else {
            // Here a failed statement leaves the rest of the transaction intact, so committing it is correct.
            self::assertSame('committed', $outcome);
            self::assertSame([1], $this->notes());
        }
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testTheOriginalErrorSurvivesARollbackThatFails(): void
    {
        $failing = $this->connectionWhoseRollbackFails();
        $transaction = new DbalTransaction($failing);
        try {
            $transaction->run(static function () use ($failing): void {
                $failing->insert('notes', ['id' => 1, 'note' => 'never committed']);
                throw new \RuntimeException('The original failure.');
            });
            self::fail('The failure was swallowed.');
        } catch (\RuntimeException $error) {
            self::assertSame('The original failure.', $error->getMessage());
        }
        self::assertFalse($failing->isTransactionActive());
        self::assertSame([], $this->notes(), 'Nothing committed.');
        $transaction->run(static fn () => $failing->insert('notes', ['id' => 2, 'note' => 'committed']));
        self::assertSame([2], $this->notes(), 'The connection works afterwards.');
    }

    public function testWritesAndRollbackListenersNeedTheTransaction(): void
    {
        self::assertFalse($this->transaction->isActive());
        foreach ([fn () => $this->transaction->assertActive(), fn () => $this->transaction->onRollback(static function (): void {})] as $outside) {
            try {
                $outside();
                self::fail('Refused outside the approval transaction.');
            } catch (\LogicException) {
            }
        }
        $calls = 0;
        $this->transaction->run(function () use (&$calls): void {
            $this->transaction->assertActive();
            $this->transaction->onRollback(static function () use (&$calls): void {
                ++$calls;
            });
        });
        self::assertSame(0, $calls, 'A commit runs no rollback listener.');
        try {
            $this->transaction->run(function () use (&$calls): void {
                $this->transaction->onRollback(static function () use (&$calls): void {
                    ++$calls;
                });
                throw new \RuntimeException('Roll back.');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $calls);
    }

    public function testEachDatabaseSerializesTheWayTheStoresNeed(): void
    {
        $other = TestDatabase::connect(static::KIND, (string) $this->location);
        TestDatabase::lockTimeout(static::KIND, $other, 200);
        if ('sqlite' === TestDatabase::platform(static::KIND)) {
            self::assertSame(['', ''], [$this->transaction->forUpdate(), $this->transaction->forShare()]);
            $this->transaction->run(static function () use ($other): void {
                // Before the callback reads anything, the database's write reservation is taken.
                try {
                    $other->insert('notes', ['id' => 9, 'note' => 'another writer']);
                    self::fail('Another writer went ahead while the approval transaction was open.');
                } catch (\Throwable $error) {
                    self::assertTrue(TestDatabase::isLockTimeout($error), $error->getMessage());
                }
            });

            return;
        }
        $mysql = 'mysql' === TestDatabase::platform(static::KIND);
        self::assertSame(['FOR UPDATE', $mysql ? 'LOCK IN SHARE MODE' : 'FOR SHARE'], [$this->transaction->forUpdate(), $this->transaction->forShare()]);
        // A session default of REPEATABLE READ does not reach the approval transaction, and is kept for the rest.
        $this->connection->executeStatement($mysql ? 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' : 'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        // The behavior itself, not a level the server reports (MySQL's InnoDB tables answer from a cache that can
        // still list the previous transaction): whether a second read sees what another connection committed after
        // the first. Under READ COMMITTED it does; under REPEATABLE READ the transaction keeps its first snapshot.
        $this->note(11);
        $this->note(12);
        $seesLaterCommits = function (int $note) use ($other): bool {
            $first = $this->connection->fetchOne('SELECT note FROM notes WHERE id = ?', [$note]);
            $other->update('notes', ['note' => 'changed by another connection'], ['id' => $note]);

            return $first !== $this->connection->fetchOne('SELECT note FROM notes WHERE id = ?', [$note]);
        };
        self::assertTrue($this->transaction->run(static fn (): bool => $seesLaterCommits(11)), 'The approval transaction reads what committed before each statement.');
        $this->connection->beginTransaction();
        self::assertFalse($seesLaterCommits(12), 'The application\'s own transaction keeps the session\'s REPEATABLE READ.');
        $this->connection->rollBack();
        // And no lock is taken that a writer of other rows would wait for.
        $this->transaction->run(static fn () => $other->insert('notes', ['id' => 9, 'note' => 'another writer']));
        self::assertSame([9, 11, 12], $this->notes());
    }

    public function testAConnectionOutsideAutoCommitIsRefused(): void
    {
        $connection = TestDatabase::connect(static::KIND, (string) $this->location);
        $connection->setAutoCommit(false);
        try {
            (new DbalTransaction($connection))->run(static fn () => null);
            self::fail('The approval transaction ran without auto-commit.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('auto-commit', $error->getMessage());
        }
    }

    private function note(int $id): void
    {
        $this->connection->insert('notes', ['id' => $id, 'note' => 'note '.$id]);
    }

    /** @return list<int> the notes, read on a connection of its own */
    private function notes(): array
    {
        $fresh = TestDatabase::connect(static::KIND, (string) $this->location);
        $ids = array_map(intval(...), $fresh->fetchFirstColumn('SELECT id FROM notes ORDER BY id'));
        $fresh->close();

        return $ids;
    }

    private function connectionWhoseRollbackFails(): Connection
    {
        $template = TestDatabase::connect(static::KIND, (string) $this->location);
        $configuration = new Configuration();
        $configuration->setMiddlewares([new class implements Driver\Middleware {
            public function wrap(Driver $driver): Driver
            {
                return new class($driver) extends AbstractDriverMiddleware {
                    public function connect(array $params): DriverConnection
                    {
                        return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                            public function rollBack(): void
                            {
                                throw new \RuntimeException('Injected: the rollback failed.');
                            }
                        };
                    }
                };
            }
        }]);
        $connection = DriverManager::getConnection($template->getParams(), $configuration);
        TestDatabase::lockTimeout(static::KIND, $connection, 15000);

        return $connection;
    }
}
