<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts\Fixture;

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
    /** @var array<string, mixed> added to every proposal's effect, to give it a particular shape */
    public array $extraEffect = [];
    /** @var array<string, mixed> added to every execution's result */
    public array $extraResult = [];
    /** Run once the action's postconditions hold, still inside the approval transaction: to end it there, say. */
    public ?\Closure $afterPostconditions = null;

    private readonly FixtureDatabase $database;

    public function __construct(\PDO|FixtureDatabase $database, private readonly ExecutionGuard $guard)
    {
        $this->database = $database instanceof \PDO ? new PdoFixtureDatabase($database) : $database;
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
            ['title' => $row['title'], 'body' => $row['body'], 'asset_version' => $row['asset_version'], 'published' => true] + $this->extraEffect,
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
        $changed = $this->database->execute('UPDATE test_articles SET published = 1, revision = revision + 1, publication_count = publication_count + 1 WHERE id = ? AND tenant = ? AND revision = ?', [$action->target['resource'], $actor->tenantId, $action->preconditions['revision']]);
        if (1 !== $changed) {
            throw new ApprovalException('stale_resource', 'Conditional publication failed.');
        }
        if ($this->alterAfterWrite) {
            // The row is locked by this transaction, so reading and writing it back is safe on every database.
            $body = (string) $this->row((int) $action->target['resource'])['body'];
            $this->database->execute('UPDATE test_articles SET body = ? WHERE id = ?', [$body.' changed by a hook', $action->target['resource']]);
        }
        if ($this->failAfterWrite) {
            throw new \RuntimeException('Injected failure after the business write and before commit.');
        }

        return ['resource' => $action->target['resource'], 'source_revision' => $action->preconditions['revision'], 'result_revision' => $action->preconditions['revision'] + 1, 'published' => true] + $this->extraResult;
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $row = $this->row((int) $action->target['resource']);
        if ($row['title'] !== $action->effect['title'] || $row['body'] !== $action->effect['body']
            || $row['asset_version'] !== $action->effect['asset_version'] || !(bool) $row['published']
            || (int) $row['revision'] !== $result['result_revision']) {
            throw new ApprovalException('postcondition_failed', 'The application did not produce the approved effect.');
        }
        if (null !== $this->afterPostconditions) {
            ($this->afterPostconditions)();
        }
    }

    /** @return array<string, mixed> the article as stored now, locked for writing where the database locks rows */
    private function row(int $id): array
    {
        $row = $this->database->row('SELECT * FROM test_articles WHERE id = ? '.$this->database->forUpdate(), [$id]);
        if (null === $row) {
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
