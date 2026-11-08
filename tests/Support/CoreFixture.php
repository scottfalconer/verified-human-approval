<?php

declare(strict_types=1);

namespace ExactApproval\Tests\Support;

use ExactApproval\Core\ActionAdapter;
use ExactApproval\Core\ActionRegistry;
use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalPolicy;
use ExactApproval\Core\ApprovalService;
use ExactApproval\Core\ApprovalVerifier;
use ExactApproval\Core\Authorizer;
use ExactApproval\Core\Ceremony;
use ExactApproval\Core\ExecutionGuard;
use ExactApproval\Core\PolicyProvider;
use ExactApproval\Core\PreparedAction;
use ExactApproval\Core\Storage\PdoApprovalStore;
use ExactApproval\Core\VerifiedApproval;
use PDO;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/**
 * A real SQLite application boundary for the approval core tests.
 *
 * TestProofVerifier is intentionally NOT cryptography. It exercises the core's
 * verifier seam; production WebAuthn behavior has a separate test suite.
 */
final class CoreFixture
{
    public readonly PDO $pdo;
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
        $this->pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Missing fixture article.');
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
        $this->pdo->exec(<<<'SQL'
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
            SQL);
    }
}

final class FixturePolicies implements PolicyProvider
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $actionId, ?Actor $actor = null): ApprovalPolicy
    {
        $query = $this->pdo->prepare('SELECT * FROM test_policies WHERE action_id = ?');
        $query->execute([$actionId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApprovalException('unknown_policy', 'No configured policy.');
        }

        return new ApprovalPolicy($actionId, (string) $row['version'], (string) $row['statement'], 600, (bool) $row['required']);
    }
}

final class FixtureAuthorizer implements Authorizer
{
    public int $checks = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        ++$this->checks;
        $query = $this->pdo->prepare('SELECT enabled, can_publish FROM test_users WHERE user_id = ? AND tenant = ?');
        $query->execute([$actor->userId, $actor->tenantId]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || !(bool) $user['enabled'] || !(bool) $user['can_publish'] || $action->target['tenant'] !== $actor->tenantId) {
            throw new ApprovalException('permission_denied', 'Current application permission does not permit publication.');
        }
        if ($actor->delegationId !== null) {
            $query = $this->pdo->prepare('SELECT * FROM test_delegations WHERE id = ? AND user_id = ? AND tenant = ?');
            $query->execute([$actor->delegationId, $actor->userId, $actor->tenantId]);
            $delegation = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($delegation) || !(bool) $delegation['active']) {
                throw new ApprovalException('delegation_revoked', 'The initiating client no longer has an active delegation.');
            }
            if (!(bool) $delegation['can_publish']) {
                throw new ApprovalException('scope_denied', 'The initiating client no longer has delegated publication authority.');
            }
        }
    }
}

final class FixtureAction implements ActionAdapter
{
    public string $implementationVersion = '1';
    public bool $failAfterWrite = false;
    public bool $alterAfterWrite = false;
    public int $executeCalls = 0;

    public function __construct(private readonly PDO $pdo, private readonly ExecutionGuard $guard)
    {
    }

    public function id(): string
    {
        return 'article.publish';
    }

    public function version(): string
    {
        return $this->implementationVersion;
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        if (!isset($input['id']) || !is_int($input['id']) || array_diff(array_keys($input), ['id']) !== []) {
            throw new ApprovalException('invalid_input', 'An integer article id is required.');
        }
        $row = $this->row($input['id']);
        if ($row['tenant'] !== $actor->tenantId) {
            throw new ApprovalException('permission_denied', 'The article belongs to another tenant.');
        }

        return new PreparedAction(
            $this->id(),
            $this->version(),
            ['application' => 'fixture', 'environment' => 'test', 'tenant' => $actor->tenantId, 'resource' => $input['id']],
            ['title' => $row['title'], 'body' => $row['body'], 'asset_version' => $row['asset_version'], 'published' => true],
            ['revision' => (int) $row['revision'], 'published' => (bool) $row['published'], 'content_digest' => $this->contentDigest($row), 'asset_version' => $row['asset_version']],
            ['title' => $row['title'], 'body' => $row['body'], 'consequence' => 'This exact content will become publicly visible.'],
            ['title' => $row['title'], 'revision' => (string) $row['revision']],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $row = $this->row((int) $action->target['resource']);
        if ($row['tenant'] !== $actor->tenantId
            || (int) $row['revision'] !== $action->preconditions['revision']
            || (bool) $row['published'] !== $action->preconditions['published']
            || $row['asset_version'] !== $action->preconditions['asset_version']
            || $this->contentDigest($row) !== $action->preconditions['content_digest']) {
            throw new ApprovalException('stale_resource', 'The approved source or one of its dependencies changed.');
        }
    }

    public function execute(Actor $actor, PreparedAction $action): array
    {
        // This is the application mutation boundary, not an MCP-only check.
        $this->guard->assertExecuting($actor, $action);
        ++$this->executeCalls;
        $update = $this->pdo->prepare('UPDATE test_articles SET published = 1, revision = revision + 1, publication_count = publication_count + 1 WHERE id = ? AND tenant = ? AND revision = ?');
        $update->execute([$action->target['resource'], $actor->tenantId, $action->preconditions['revision']]);
        if ($update->rowCount() !== 1) {
            throw new ApprovalException('stale_resource', 'Conditional publication failed.');
        }
        if ($this->alterAfterWrite) {
            $update = $this->pdo->prepare("UPDATE test_articles SET body = body || ' changed by a hook' WHERE id = ?");
            $update->execute([$action->target['resource']]);
        }
        if ($this->failAfterWrite) {
            throw new RuntimeException('Injected failure after the business write and before commit.');
        }

        return ['resource' => $action->target['resource'], 'source_revision' => $action->preconditions['revision'], 'result_revision' => $action->preconditions['revision'] + 1, 'published' => true];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $row = $this->row((int) $action->target['resource']);
        if ($row['title'] !== $action->effect['title'] || $row['body'] !== $action->effect['body']
            || $row['asset_version'] !== $action->effect['asset_version'] || !(bool) $row['published']
            || (int) $row['revision'] !== $result['result_revision']) {
            throw new ApprovalException('postcondition_failed', 'The application did not produce the approved effect.');
        }
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $query = $this->pdo->prepare('SELECT * FROM test_articles WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApprovalException('missing_resource', 'The article does not exist.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function contentDigest(array $row): string
    {
        return hash('sha256', json_encode([$row['title'], $row['body']], JSON_THROW_ON_ERROR));
    }
}

/** Test double only. Never register this verifier in an application container. */
final class TestProofVerifier implements ApprovalVerifier
{
    public int $verifyCalls = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function requestOptions(Actor $actor, string $challenge): array
    {
        $query = $this->pdo->prepare('SELECT id FROM test_credentials WHERE user_id = ? AND tenant = ? AND active = 1');
        $query->execute([$actor->userId, $actor->tenantId]);
        $ids = $query->fetchAll(PDO::FETCH_COLUMN);
        if ($ids === []) {
            throw new ApprovalException('credential_unavailable', 'No accepted approval credential.');
        }

        return ['challenge' => self::encode($challenge), 'allowCredentials' => array_map(static fn (string $id): array => ['id' => $id, 'type' => 'public-key'], $ids), 'userVerification' => 'required'];
    }

    public function verify(Actor $actor, string $challenge, array $assertion): VerifiedApproval
    {
        ++$this->verifyCalls;
        if (($assertion['test_only'] ?? null) !== true
            || ($assertion['challenge'] ?? '') !== self::encode($challenge)
            || ($assertion['user'] ?? '') !== $actor->userId
            || ($assertion['tenant'] ?? '') !== $actor->tenantId) {
            throw new ApprovalException('invalid_assertion', 'Test proof does not bind this proposal and account.');
        }
        $query = $this->pdo->prepare('SELECT * FROM test_credentials WHERE id = ? AND user_id = ? AND tenant = ? AND active = 1');
        $query->execute([$assertion['credential'] ?? '', $actor->userId, $actor->tenantId]);
        $credential = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($credential)) {
            throw new ApprovalException('credential_denied', 'The approval credential is no longer accepted for this person.');
        }
        $update = $this->pdo->prepare('UPDATE test_credentials SET verification_count = verification_count + 1 WHERE id = ?');
        $update->execute([$credential['id']]);

        return new VerifiedApproval((string) $credential['id'], ['test_only' => true, 'assertion' => $assertion]);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
