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

/**
 * A real SQLite application boundary for the approval core tests.
 *
 * TestProofVerifier is intentionally NOT cryptography. It exercises the core's
 * verifier seam; production WebAuthn behavior has a separate test suite.
 */
final class CoreFixture
{
    /** The fixture application's tables and seed rows: articles, users, delegations, policies and test credentials. */
    public const APPLICATION_SCHEMA = <<<'SQL'
            CREATE TABLE IF NOT EXISTS test_articles (
                id INTEGER PRIMARY KEY,
                tenant TEXT NOT NULL,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                revision INTEGER NOT NULL,
                asset_version TEXT NOT NULL,
                published INTEGER NOT NULL DEFAULT 0,
                publication_count INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS test_users (
                user_id TEXT NOT NULL,
                tenant TEXT NOT NULL,
                enabled INTEGER NOT NULL,
                can_publish INTEGER NOT NULL,
                PRIMARY KEY (user_id, tenant)
            );
            CREATE TABLE IF NOT EXISTS test_delegations (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                tenant TEXT NOT NULL,
                active INTEGER NOT NULL,
                can_publish INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS test_policies (
                action_id TEXT PRIMARY KEY,
                version TEXT NOT NULL,
                statement TEXT NOT NULL,
                required INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS test_credentials (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                tenant TEXT NOT NULL,
                active INTEGER NOT NULL,
                verification_count INTEGER NOT NULL DEFAULT 0
            );
            INSERT OR IGNORE INTO test_articles (id, tenant, title, body, revision, asset_version)
            VALUES (1, 'tenant-a', 'Summer Campaign', 'Closing date: June 20.', 17, 'sha256:asset-v1');
            INSERT OR IGNORE INTO test_articles (id, tenant, title, body, revision, asset_version)
            VALUES (2, 'tenant-a', 'Another Campaign', 'Closing date: July 20.', 4, 'sha256:asset-v2');
            INSERT OR IGNORE INTO test_articles (id, tenant, title, body, revision, asset_version)
            VALUES (3, 'tenant-b', 'Other Organization', 'Private tenant B content.', 3, 'sha256:asset-v3');
            INSERT OR IGNORE INTO test_users VALUES ('alice', 'tenant-a', 1, 1);
            INSERT OR IGNORE INTO test_users VALUES ('bob', 'tenant-a', 1, 1);
            INSERT OR IGNORE INTO test_users VALUES ('alice', 'tenant-b', 1, 1);
            INSERT OR IGNORE INTO test_delegations VALUES ('agent-1', 'alice', 'tenant-a', 1, 1);
            INSERT OR IGNORE INTO test_delegations VALUES ('agent-2', 'alice', 'tenant-a', 1, 1);
            INSERT OR IGNORE INTO test_credentials (id, user_id, tenant, active) VALUES ('alice-key', 'alice', 'tenant-a', 1);
            INSERT OR IGNORE INTO test_credentials (id, user_id, tenant, active) VALUES ('bob-key', 'bob', 'tenant-a', 1);
            INSERT OR IGNORE INTO test_policies VALUES ('article.publish', '1', 'I approve publishing {{ title }}, revision {{ revision }}.', 1);
        SQL;

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
        $this->pdo->exec(self::APPLICATION_SCHEMA);
    }
}
