<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\StoreContracts\FaultingSigner;
use VerifiedHumanApproval\StoreContracts\FaultingStore;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAction;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAuthorizer;
use VerifiedHumanApproval\StoreContracts\Fixture\FixturePolicies;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureSchema;
use VerifiedHumanApproval\StoreContracts\Fixture\TestProofVerifier;
use VerifiedHumanApproval\StoreContracts\StoreHarness;
use VerifiedHumanApproval\Tests\Support\DbalFixtureDatabase;
use VerifiedHumanApproval\Tests\Support\TestDatabase;

/**
 * DbalApprovalStore on one database, with the fixture application on the same DBAL connection, locking what its
 * decision reads with DbalTransaction's clauses. The location is a SQLite file or a server database's name.
 */
abstract class DbalStoreHarness extends StoreHarness
{
    /** One of TestDatabase::KINDS. */
    protected const KIND = 'sqlite';

    private readonly Connection $connection;
    private readonly DbalApprovalStore $adapter;
    private readonly FixtureAction $action;
    private readonly SodiumEvidenceSigner $signer;
    private readonly ApprovalService $service;

    final public function __construct(private readonly string $location)
    {
        parent::__construct();
        $this->connection = TestDatabase::connect(static::KIND, $location);
        $transaction = new DbalTransaction($this->connection);
        $this->adapter = new DbalApprovalStore($transaction);
        $database = new DbalFixtureDatabase($transaction);
        $guard = new ExecutionGuard();
        $this->action = new FixtureAction($database, $guard);
        $authorizer = new FixtureAuthorizer($database);
        $authorizer->afterCheck = fn () => $this->faults->reach('authority_checked');
        $this->signer = new SodiumEvidenceSigner('contract-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hash('sha256', 'contract receipts '.$location, true))));
        $this->service = new ApprovalService(
            new ActionRegistry([$this->action]),
            new FixturePolicies($database),
            $authorizer,
            new FaultingStore($this->adapter, $this->faults),
            new TestProofVerifier($database),
            new MockClock('2026-11-08T10:00:00+00:00'),
            $guard,
            new FaultingSigner($this->signer, $this->faults),
        );
    }

    public static function prepare(): string
    {
        $location = TestDatabase::create(static::KIND);
        $connection = TestDatabase::connect(static::KIND, $location);
        ApprovalSchema::install($connection);
        foreach (FixtureSchema::statements(TestDatabase::platform(static::KIND)) as $statement) {
            $connection->executeStatement($statement);
        }
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

    public function service(): ApprovalService
    {
        return $this->service;
    }

    public function store(): ApprovalStore
    {
        return $this->adapter;
    }

    public function action(): FixtureAction
    {
        return $this->action;
    }

    public function receiptPublicKey(): string
    {
        return $this->signer->publicKey();
    }

    public function receiptKeyId(): string
    {
        return $this->signer->keyId();
    }

    public function executeCalls(): int
    {
        return $this->action->executeCalls;
    }

    public function counts(): array
    {
        // Read the way an observer would: on a connection of its own.
        $fresh = $this->observer();
        $count = static fn (string $sql): int => (int) $fresh->fetchOne($sql);
        $counts = [
            'requests' => $count('SELECT COUNT(*) FROM '.ApprovalSchema::REQUESTS),
            'effects' => $count('SELECT COALESCE(SUM(publication_count), 0) FROM test_articles'),
            'executions' => $count('SELECT COUNT(*) FROM '.ApprovalSchema::EXECUTIONS),
            'receipts' => $count('SELECT COUNT(*) FROM '.ApprovalSchema::RECEIPTS),
            'credentialUses' => $count("SELECT verification_count FROM test_credentials WHERE id = 'alice-key'"),
        ];
        $fresh->close();

        return $counts;
    }

    public function article(int $id = 1): array
    {
        $fresh = $this->observer();
        $row = $fresh->fetchAssociative('SELECT revision, body, published FROM test_articles WHERE id = ?', [$id]);
        $fresh->close();
        if (false === $row) {
            throw new \RuntimeException('No such article.');
        }

        return ['revision' => (int) $row['revision'], 'body' => (string) $row['body'], 'published' => (bool) $row['published']];
    }

    public function breakAction(?string $how): void
    {
        $this->action->failAfterWrite = 'after_write' === $how;
        $this->action->alterAfterWrite = 'postcondition' === $how;
    }

    public function attempt(string $change, int $milliseconds): bool
    {
        [$sql, $parameters] = self::change($change, $this->connection->getDatabasePlatform()->getConcatExpression('%s', '%s'));
        // An ordinary writer, outside the approval service, on its own connection.
        $writer = $this->observer();
        TestDatabase::lockTimeout(static::KIND, $writer, $milliseconds);
        try {
            $writer->executeStatement($sql, $parameters);

            return true;
        } catch (\Throwable $error) {
            if (TestDatabase::isLockTimeout($error)) {
                return false;
            }
            throw $error;
        } finally {
            $writer->close();
        }
    }

    public function writeArticle(int $id, string $title, string $body): void
    {
        $writer = $this->observer();
        $writer->executeStatement('UPDATE test_articles SET title = ?, body = ?, revision = revision + 1 WHERE id = ?', [$title, $body, $id]);
        $writer->close();
    }

    public function editRecord(string $requestId, string $part, \Closure $edit): void
    {
        [$table, $column, $key] = match ($part) {
            'proposal', 'digest', 'status', 'result', 'proof' => [ApprovalSchema::REQUESTS, $part, 'id'],
            'execution' => [ApprovalSchema::EXECUTIONS, 'record', 'request_id'],
            'signature' => [ApprovalSchema::RECEIPTS, 'signature', 'request_id'],
            default => throw new \InvalidArgumentException('Unknown part: '.$part),
        };
        $writer = $this->observer();
        try {
            $stored = $writer->fetchOne('SELECT '.$column.' FROM '.$table.' WHERE '.$key.' = ?', [$requestId]);
            if (!\is_string($stored)) {
                throw new \RuntimeException('Nothing is stored there.');
            }
            $writer->executeStatement('UPDATE '.$table.' SET '.$column.' = ? WHERE '.$key.' = ?', [$edit($stored), $requestId]);
        } finally {
            $writer->close();
        }
    }

    public function failInserts(string $what): void
    {
        $table = match ($what) {
            'execution' => ApprovalSchema::EXECUTIONS,
            'receipt' => ApprovalSchema::RECEIPTS,
            default => throw new \InvalidArgumentException('Unknown insert: '.$what),
        };
        $name = 'contract_reject_'.$what;
        $message = 'injected '.$what.' insert failure';
        $statements = match (TestDatabase::platform(static::KIND)) {
            'sqlite' => ['CREATE TRIGGER '.$name.' BEFORE INSERT ON '.$table." BEGIN SELECT RAISE(ABORT, '".$message."'); END"],
            'postgresql' => [
                'CREATE OR REPLACE FUNCTION '.$name."() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION '".$message."'; END \$\$",
                'CREATE TRIGGER '.$name.' BEFORE INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION '.$name.'()',
            ],
            'mysql' => ['CREATE TRIGGER '.$name.' BEFORE INSERT ON '.$table." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".$message."'"],
        };
        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    public function clearInsertFailures(): void
    {
        foreach (['execution' => ApprovalSchema::EXECUTIONS, 'receipt' => ApprovalSchema::RECEIPTS] as $what => $table) {
            $this->connection->executeStatement(match (TestDatabase::platform(static::KIND)) {
                'postgresql' => 'DROP TRIGGER IF EXISTS contract_reject_'.$what.' ON '.$table,
                default => 'DROP TRIGGER IF EXISTS contract_reject_'.$what,
            });
        }
    }

    public function withAmbientTransaction(callable $work): mixed
    {
        $this->connection->beginTransaction();
        try {
            return $work();
        } finally {
            $this->connection->rollBack();
        }
    }

    public function holdWriteLock(): callable
    {
        $other = $this->observer();
        if ('sqlite' === TestDatabase::platform(static::KIND)) {
            $other->executeStatement('BEGIN IMMEDIATE');

            return static function () use ($other): void {
                $other->executeStatement('ROLLBACK');
                $other->close();
            };
        }
        // No database-wide write lock here: hold the rows an approval reads first, and the content it changes.
        $other->beginTransaction();
        foreach ([ApprovalSchema::REQUESTS, ApprovalSchema::CEREMONIES, 'test_articles'] as $table) {
            $other->fetchAllAssociative('SELECT * FROM '.$table.' FOR UPDATE');
        }

        return static function () use ($other): void {
            $other->rollBack();
            $other->close();
        };
    }

    public function lockTimeout(int $milliseconds): void
    {
        TestDatabase::lockTimeout(static::KIND, $this->connection, $milliseconds);
    }

    private function observer(): Connection
    {
        return TestDatabase::connect(static::KIND, $this->location);
    }
}
