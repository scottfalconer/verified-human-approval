<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Audit\AuditLog;
use VerifiedHumanApproval\Core\Evidence\SodiumEvidenceSigner;
use VerifiedHumanApproval\Core\Evidence\TrustedKeys;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAction;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAuthorizer;
use VerifiedHumanApproval\StoreContracts\Fixture\FixturePolicies;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureSchema;
use VerifiedHumanApproval\StoreContracts\Fixture\TestProofVerifier;

/**
 * The SQLite reference stores on one PDO connection: the approval transaction is one that PDO reports, so the audit
 * store and the application's own writes join it, and it still holds SQLite's write reservation from the start.
 */
final class PdoApprovalTransactionTest extends TestCase
{
    private ?string $path = null;

    protected function tearDown(): void
    {
        if (null !== $this->path) {
            foreach ([$this->path, $this->path.'-wal', $this->path.'-shm', $this->path.'-journal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testAnApprovedActionRecordsASignedAuditEntryInTheSameTransaction(): void
    {
        $pdo = $this->open();
        $signer = new SodiumEvidenceSigner('app-key', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $audit = new AuditLog(new PdoAuditStore($pdo), $signer, TrustedKeys::single($signer->keyId(), $signer->publicKey()), new MockClock('2026-11-08T10:00:00Z'));
        $guard = new ExecutionGuard();
        $publish = new FixtureAction($pdo, $guard);
        $service = $this->service($pdo, $guard, self::recording($publish, $audit), $signer);
        $agent = new Actor('alice', 'tenant-a', 'agent-1');
        $alice = new Actor('alice', 'tenant-a');

        $pending = $service->request($agent, 'article.publish', ['id' => 1]);
        $ceremony = $service->begin($alice, $pending->request->id);
        $completed = $service->complete($alice, $ceremony->id, ['test_only' => true, 'challenge' => $ceremony->challenge, 'user' => 'alice', 'tenant' => 'tenant-a', 'credential' => 'alice-key']);

        self::assertSame('completed', $completed->status);
        self::assertSame(1, (int) $pdo->query('SELECT publication_count FROM test_articles WHERE id = 1')->fetchColumn());
        self::assertSame('alice', $audit->find('article.published', '1')['data']->by ?? null, 'The entry committed with the action.');
        self::assertTrue($audit->verify()['intact']);

        // When the action fails after its entry, the entry rolls back with it.
        $second = $service->request($agent, 'article.publish', ['id' => 2]);
        $publish->failAfterWrite = true;
        $ceremony = $service->begin($alice, $second->request->id);
        try {
            $service->complete($alice, $ceremony->id, ['test_only' => true, 'challenge' => $ceremony->challenge, 'user' => 'alice', 'tenant' => 'tenant-a', 'credential' => 'alice-key']);
            self::fail('A failed action was reported as completed.');
        } catch (ApprovalException $error) {
            self::assertSame('execution_failed', $error->reason);
        }
        self::assertNull($audit->find('article.published', '2'));
        self::assertSame(1, $audit->verify()['newest']);
    }

    /** @return iterable<string, array{string}> */
    public static function journals(): iterable
    {
        yield 'rollback journal' => ['DELETE'];
        yield 'WAL' => ['WAL'];
    }

    #[DataProvider('journals')]
    public function testTheTransactionHoldsTheWriteReservationBeforeItsCallbackReadsAnything(string $journal): void
    {
        $pdo = $this->open($journal);
        $other = new \PDO('sqlite:'.$this->path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $other->exec('PRAGMA busy_timeout = 0');
        $store = new PdoApprovalStore($pdo);

        $store->transaction(static function () use ($pdo, $other): void {
            self::assertTrue($pdo->inTransaction(), 'PDO reports the transaction.');
            try {
                $other->exec('UPDATE test_articles SET body = body');
                self::fail('Another writer got in while the approval transaction was open.');
            } catch (\PDOException $error) {
                self::assertStringContainsString('locked', $error->getMessage());
            }
        });
        self::assertFalse($pdo->inTransaction());
        self::assertSame(3, $other->exec('UPDATE test_articles SET body = body'), 'The reservation ends with the transaction.');
    }

    public function testATransactionBegunWithPlainSqlIsRefusedAsOneTheStoreDoesNotOwn(): void
    {
        $pdo = $this->open();
        $store = new PdoApprovalStore($pdo);
        $pdo->exec('BEGIN');
        $ran = false;
        try {
            $store->transaction(static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('The approval ran inside a transaction it does not own.');
        } catch (\LogicException $error) {
            self::assertSame('Approval execution must own the outer database transaction.', $error->getMessage());
        }
        self::assertFalse($ran);
        $pdo->exec('ROLLBACK');
        self::assertSame('ran', $store->transaction(static fn (): string => 'ran'));
    }

    public function testATransactionThatSqliteEndedUnderneathLeavesTheConnectionUsable(): void
    {
        $pdo = $this->open();
        $store = new PdoApprovalStore($pdo);
        // As SQLite does by itself after some errors, such as a full disk.
        foreach (['the callback fails' => true, 'the callback returns' => false] as $how => $fails) {
            try {
                $store->transaction(static function () use ($pdo, $fails): void {
                    $pdo->exec('ROLLBACK');
                    if ($fails) {
                        throw new \RuntimeException('The disk is full.');
                    }
                });
                self::fail('A transaction that ended underneath was reported as committed when '.$how.'.');
            } catch (\Throwable $error) {
                // The callback's own failure is the one reported; otherwise the store says its transaction ended.
                self::assertInstanceOf($fails ? \RuntimeException::class : \LogicException::class, $error, $how);
            }
            self::assertFalse($pdo->inTransaction(), $how);
            self::assertSame('ran', $store->transaction(static fn (): string => 'ran'), 'The next transaction begins when '.$how.'.');
        }
    }

    /** The fixture action, recording a signed audit entry for what it published, as an approved action may. */
    private static function recording(FixtureAction $publish, AuditLog $audit): ActionAdapter
    {
        return new class($publish, $audit) implements ActionAdapter {
            public function __construct(private readonly FixtureAction $inner, private readonly AuditLog $audit)
            {
            }

            public function id(): string
            {
                return $this->inner->id();
            }

            public function version(): string
            {
                return $this->inner->version();
            }

            public function prepare(Actor $actor, array $input): PreparedAction
            {
                return $this->inner->prepare($actor, $input);
            }

            public function assertPreconditions(Actor $actor, PreparedAction $action): void
            {
                $this->inner->assertPreconditions($actor, $action);
            }

            public function execute(Actor $actor, PreparedAction $action): array
            {
                $this->audit->append($actor->tenantId, 'article.published', (string) $action->target['resource'], ['by' => $actor->userId]);

                return $this->inner->execute($actor, $action);
            }

            public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
            {
                $this->inner->assertPostconditions($actor, $action, $result);
            }
        };
    }

    private function service(\PDO $pdo, ExecutionGuard $guard, ActionAdapter $action, SodiumEvidenceSigner $signer): ApprovalService
    {
        return new ApprovalService(new ActionRegistry([$action]), new FixturePolicies($pdo), new FixtureAuthorizer($pdo), new PdoApprovalStore($pdo), new TestProofVerifier($pdo), new MockClock('2026-11-08T10:00:00Z'), $guard, $signer);
    }

    /** A database file with the approval and audit tables and the fixture application. */
    private function open(string $journal = 'DELETE'): \PDO
    {
        $this->path = sys_get_temp_dir().'/verified-human-approval-transaction-'.bin2hex(random_bytes(6)).'.sqlite';
        $pdo = new \PDO('sqlite:'.$this->path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode = '.$journal);
        (new PdoApprovalStore($pdo))->initialize();
        (new PdoAuditStore($pdo))->initialize();
        foreach (FixtureSchema::statements('sqlite') as $statement) {
            $pdo->exec($statement);
        }

        return $pdo;
    }
}
