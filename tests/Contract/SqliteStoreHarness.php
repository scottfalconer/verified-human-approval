<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalStore;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\StoreContracts\FaultingSigner;
use VerifiedHumanApproval\StoreContracts\FaultingStore;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAction;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAuthorizer;
use VerifiedHumanApproval\StoreContracts\Fixture\FixturePolicies;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureSchema;
use VerifiedHumanApproval\StoreContracts\Fixture\TestProofVerifier;
use VerifiedHumanApproval\StoreContracts\StoreHarness;

/** The SQLite reference adapter, PdoApprovalStore, in SQLite's default rollback journal. The location is a database file. */
class SqliteStoreHarness extends StoreHarness
{
    protected const JOURNAL = 'delete';

    private readonly \PDO $pdo;
    private readonly PdoApprovalStore $adapter;
    private readonly FixtureAction $action;
    private readonly SodiumEvidenceSigner $signer;
    private readonly ApprovalService $service;

    final public function __construct(private readonly string $location)
    {
        parent::__construct();
        $this->pdo = self::open($location);
        $this->adapter = new PdoApprovalStore($this->pdo);
        $guard = new ExecutionGuard();
        $this->action = new FixtureAction($this->pdo, $guard);
        $authorizer = new FixtureAuthorizer($this->pdo);
        $authorizer->afterCheck = fn () => $this->faults->reach('authority_checked');
        // A real Ed25519 key: the same for every connection to this database, and new for every database.
        $this->signer = new SodiumEvidenceSigner('contract-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hash('sha256', 'contract receipts '.$location, true))));
        $this->service = new ApprovalService(
            new ActionRegistry([$this->action]),
            new FixturePolicies($this->pdo),
            $authorizer,
            new FaultingStore($this->adapter, $this->faults),
            new TestProofVerifier($this->pdo),
            new MockClock('2026-11-08T10:00:00+00:00'),
            $guard,
            new FaultingSigner($this->signer, $this->faults),
        );
    }

    public static function prepare(): string
    {
        $location = sys_get_temp_dir().'/verified-human-approval-contract-'.bin2hex(random_bytes(8)).'.sqlite';
        $pdo = self::open($location);
        if (static::JOURNAL !== $pdo->query('PRAGMA journal_mode = '.static::JOURNAL)->fetchColumn()) {
            throw new \RuntimeException('SQLite did not switch to the '.static::JOURNAL.' journal.');
        }
        (new PdoApprovalStore($pdo))->initialize();
        foreach (FixtureSchema::statements('sqlite') as $statement) {
            $pdo->exec($statement);
        }

        return $location;
    }

    public static function destroy(string $location): void
    {
        foreach ([$location, $location.'-wal', $location.'-shm', $location.'-journal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
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
        $fresh = self::open($this->location);
        $count = static fn (string $sql): int => (int) $fresh->query($sql)->fetchColumn();

        return [
            'requests' => $count('SELECT COUNT(*) FROM verified_human_approval_requests'),
            'effects' => $count('SELECT COALESCE(SUM(publication_count), 0) FROM test_articles'),
            'executions' => $count('SELECT COUNT(*) FROM verified_human_approval_executions'),
            'receipts' => $count('SELECT COUNT(*) FROM verified_human_approval_receipts'),
            'credentialUses' => $count("SELECT verification_count FROM test_credentials WHERE id = 'alice-key'"),
        ];
    }

    public function article(int $id = 1): array
    {
        $query = self::open($this->location)->prepare('SELECT revision, body, published FROM test_articles WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
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
        [$sql, $parameters] = self::change($change, '%s || %s');
        // An ordinary writer, outside the approval service, on its own connection.
        $writer = self::open($this->location);
        $writer->exec('PRAGMA busy_timeout = '.$milliseconds);
        try {
            $writer->prepare($sql)->execute($parameters);

            return true;
        } catch (\PDOException $error) {
            if (str_contains($error->getMessage(), 'database is locked')) {
                return false;
            }
            throw $error;
        }
    }

    public function writeArticle(int $id, string $title, string $body): void
    {
        self::open($this->location)->prepare('UPDATE test_articles SET title = ?, body = ?, revision = revision + 1 WHERE id = ?')->execute([$title, $body, $id]);
    }

    public function editRecord(string $requestId, string $part, \Closure $edit): void
    {
        [$table, $column, $key] = match ($part) {
            'proposal', 'digest', 'status', 'result', 'proof' => ['verified_human_approval_requests', $part, 'id'],
            'execution' => ['verified_human_approval_executions', 'record', 'request_id'],
            'signature' => ['verified_human_approval_receipts', 'signature', 'request_id'],
            default => throw new \InvalidArgumentException('Unknown part: '.$part),
        };
        $writer = self::open($this->location);
        $read = $writer->prepare('SELECT '.$column.' FROM '.$table.' WHERE '.$key.' = ?');
        $read->execute([$requestId]);
        $stored = $read->fetchColumn();
        if (!\is_string($stored)) {
            throw new \RuntimeException('Nothing is stored there.');
        }
        $writer->prepare('UPDATE '.$table.' SET '.$column.' = ? WHERE '.$key.' = ?')->execute([$edit($stored), $requestId]);
    }

    public function failInserts(string $what): void
    {
        $table = match ($what) {
            'execution' => 'verified_human_approval_executions',
            'receipt' => 'verified_human_approval_receipts',
            default => throw new \InvalidArgumentException('Unknown insert: '.$what),
        };
        $this->pdo->exec('CREATE TRIGGER contract_reject_'.$what.' BEFORE INSERT ON '.$table." BEGIN SELECT RAISE(ABORT, 'injected ".$what." insert failure'); END");
    }

    public function clearInsertFailures(): void
    {
        foreach (['execution', 'receipt'] as $what) {
            $this->pdo->exec('DROP TRIGGER IF EXISTS contract_reject_'.$what);
        }
    }

    public function withAmbientTransaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            return $work();
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function holdWriteLock(): callable
    {
        $other = self::open($this->location);
        $other->exec('BEGIN IMMEDIATE');

        return static function () use ($other): void {
            $other->exec('ROLLBACK');
        };
    }

    public function lockTimeout(int $milliseconds): void
    {
        $this->pdo->exec('PRAGMA busy_timeout = '.$milliseconds);
    }

    private static function open(string $location): \PDO
    {
        $pdo = new \PDO('sqlite:'.$location, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 15000');

        return $pdo;
    }
}
