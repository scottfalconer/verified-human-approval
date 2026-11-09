<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use Symfony\Component\Clock\MockClock;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAction;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureAuthorizer;
use VerifiedHumanApproval\StoreContracts\Fixture\FixturePolicies;
use VerifiedHumanApproval\StoreContracts\Fixture\FixtureSchema;
use VerifiedHumanApproval\StoreContracts\Fixture\TestProofVerifier;

/**
 * A real SQLite application boundary for the approval core tests.
 *
 * TestProofVerifier is intentionally NOT cryptography. It exercises the core's
 * verifier seam; production WebAuthn behavior has a separate test suite.
 */
final class CoreFixture
{
    public readonly \PDO $pdo;
    public readonly PdoApprovalStore $store;
    public readonly MockClock $clock;
    public readonly ExecutionGuard $guard;
    public readonly FixtureAction $action;
    public readonly FixturePolicies $policies;
    public readonly FixtureAuthorizer $authorizer;
    public readonly TestProofVerifier $verifier;
    public readonly ApprovalService $service;
    public readonly Actor $actor;
    public readonly Actor $reviewer;

    public function __construct(string $database = ':memory:')
    {
        $this->pdo = new \PDO('sqlite:'.$database, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA busy_timeout = 15000');
        $this->store = new PdoApprovalStore($this->pdo);
        $this->store->initialize();
        $this->initializeApplication();
        $this->clock = new MockClock('2026-11-08T10:00:00+00:00');
        $this->guard = new ExecutionGuard();
        $this->action = new FixtureAction($this->pdo, $this->guard);
        $this->policies = new FixturePolicies($this->pdo);
        $this->authorizer = new FixtureAuthorizer($this->pdo);
        $this->verifier = new TestProofVerifier($this->pdo);
        $this->service = new ApprovalService(
            new ActionRegistry([$this->action]),
            $this->policies,
            $this->authorizer,
            $this->store,
            $this->verifier,
            $this->clock,
            $this->guard,
        );
        $this->actor = new Actor('alice', 'tenant-a', 'agent-1');
        $this->reviewer = new Actor('alice', 'tenant-a');
    }

    /** @return array<string, mixed> */
    public function article(int $id = 1): array
    {
        $query = $this->pdo->prepare('SELECT * FROM test_articles WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            throw new \RuntimeException('Missing fixture article.');
        }

        return $row;
    }

    public function effectCount(): int
    {
        return (int) $this->pdo->query('SELECT SUM(publication_count) FROM test_articles')->fetchColumn();
    }

    /** @return array<string, mixed> */
    public function proof(Ceremony $ceremony, string $user = 'alice', string $tenant = 'tenant-a', string $credential = 'alice-key'): array
    {
        return [
            'test_only' => true,
            'challenge' => $ceremony->challenge,
            'user' => $user,
            'tenant' => $tenant,
            'credential' => $credential,
        ];
    }

    private function initializeApplication(): void
    {
        foreach (FixtureSchema::statements('sqlite') as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
