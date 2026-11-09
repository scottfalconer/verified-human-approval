<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ExecutingApproval;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;

final class FixtureAction implements ActionAdapter
{
    public string $implementationVersion = '1';
    public bool $failAfterWrite = false;
    public bool $alterAfterWrite = false;
    public int $executeCalls = 0;
    /** The approval the last execution ran under, as the guard reported it. */
    public ?ExecutingApproval $executing = null;

    public function __construct(private readonly \PDO $pdo, private readonly ExecutionGuard $guard)
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
        if (!isset($input['id']) || !\is_int($input['id']) || [] !== array_diff(array_keys($input), ['id'])) {
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
        $this->executing = $this->guard->current();
        $update = $this->pdo->prepare('UPDATE test_articles SET published = 1, revision = revision + 1, publication_count = publication_count + 1 WHERE id = ? AND tenant = ? AND revision = ?');
        $update->execute([$action->target['resource'], $actor->tenantId, $action->preconditions['revision']]);
        if (1 !== $update->rowCount()) {
            throw new ApprovalException('stale_resource', 'Conditional publication failed.');
        }
        if ($this->alterAfterWrite) {
            $update = $this->pdo->prepare("UPDATE test_articles SET body = body || ' changed by a hook' WHERE id = ?");
            $update->execute([$action->target['resource']]);
        }
        if ($this->failAfterWrite) {
            throw new \RuntimeException('Injected failure after the business write and before commit.');
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
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            throw new ApprovalException('missing_resource', 'The article does not exist.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function contentDigest(array $row): string
    {
        return hash('sha256', json_encode([$row['title'], $row['body']], \JSON_THROW_ON_ERROR));
    }
}
