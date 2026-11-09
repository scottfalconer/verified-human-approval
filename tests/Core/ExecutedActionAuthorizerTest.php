<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\Authorizer;
use VerifiedHumanApproval\Core\ExecutedActionAuthorizer;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Tests\Support\CoreFixture;

/**
 * Authority is checked again once an action ran. An authorizer that checks the target itself cannot find a target
 * the action deleted; one that implements ExecutedActionAuthorizer decides that second check from the frozen
 * proposal and the result, and fails closed as before.
 */
final class ExecutedActionAuthorizerTest extends TestCase
{
    private CoreFixture $fixture;
    private ExecutionGuard $guard;

    protected function setUp(): void
    {
        $this->fixture = new CoreFixture();
        $this->guard = new ExecutionGuard();
        $this->fixture->pdo->exec("INSERT INTO test_policies (action_id, version, statement, required) VALUES ('article.delete', 1, 'I approve deleting {{ title }}.', 1)");
    }

    public function testAnAuthorizerThatChecksTheTargetItselfBlocksADeletionWithoutTheInterface(): void
    {
        $done = $this->approve(new TargetAuthorizer($this->fixture->pdo));
        self::assertSame('failed', $done['outcome'], 'The second check found no article, so the deletion rolled back.');
        self::assertSame(1, $this->articles(), 'Nothing was deleted.');
    }

    public function testAnExecutedActionAuthorizerDecidesTheSecondCheckFromWhatTheActionDid(): void
    {
        $authorizer = new DeletionAuthorizer($this->fixture->pdo);
        $done = $this->approve($authorizer);
        self::assertSame('completed', $done['outcome']);
        self::assertSame(0, $this->articles());
        self::assertSame('after:2', end($authorizer->calls), 'The last check ran once the article was gone, with the result.');
        self::assertSame(['after:2'], array_values(array_filter($authorizer->calls, static fn (string $call): bool => str_starts_with($call, 'after:'))), 'Once, and every earlier check on the article itself.');
    }

    public function testTheSecondCheckStillFailsClosed(): void
    {
        $authorizer = new DeletionAuthorizer($this->fixture->pdo, refuseAfter: true);
        $done = $this->approve($authorizer);
        self::assertSame('failed', $done['outcome']);
        self::assertSame(1, $this->articles(), 'A refusal after the action ran rolls it back.');
    }

    /** @return array{outcome: string} */
    private function approve(Authorizer $authorizer): array
    {
        $service = new ApprovalService(
            new ActionRegistry([new DeleteArticle($this->fixture->pdo, $this->guard)]),
            $this->fixture->policies,
            $authorizer,
            $this->fixture->store,
            $this->fixture->verifier,
            $this->fixture->clock,
            $this->guard,
        );
        $pending = $service->request($this->fixture->actor, 'article.delete', ['id' => 2]);
        $ceremony = $service->begin($this->fixture->reviewer, $pending->request->id);
        try {
            return ['outcome' => $service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony))->status];
        } catch (ApprovalException) {
            return ['outcome' => 'failed'];
        }
    }

    private function articles(): int
    {
        return (int) $this->fixture->pdo->query('SELECT COUNT(*) FROM test_articles WHERE id = 2')->fetchColumn();
    }
}

/** Deletes one article of the actor's tenant. */
final readonly class DeleteArticle implements ActionAdapter
{
    public function __construct(private \PDO $pdo, private ExecutionGuard $guard)
    {
    }

    public function id(): string
    {
        return 'article.delete';
    }

    public function version(): string
    {
        return '1';
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $row = $this->row((int) $input['id']) ?? throw new ApprovalException('not_found', 'No such article.');

        return new PreparedAction(
            $this->id(),
            $this->version(),
            ['application' => 'fixture', 'tenant' => $actor->tenantId, 'resource' => (int) $input['id']],
            ['deleted' => true],
            ['tenant' => $row['tenant'], 'revision' => (int) $row['revision']],
            ['title' => 'Delete '.$row['title']],
            ['title' => $row['title']],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $row = $this->row($action->target['resource']);
        if (null === $row || (int) $row['revision'] !== $action->preconditions['revision']) {
            throw new ApprovalException('stale_resource', 'The article changed.');
        }
    }

    public function execute(Actor $actor, PreparedAction $action): array
    {
        $this->guard->assertExecuting($actor, $action);
        $this->pdo->prepare('DELETE FROM test_articles WHERE id = ?')->execute([$action->target['resource']]);

        return ['deleted' => $action->target['resource']];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        if (null !== $this->row($action->target['resource'])) {
            throw new ApprovalException('unexpected_effect', 'The article is still there.');
        }
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM test_articles WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? $row : null;
    }
}

/** Allows a change only to an article of the actor's tenant, as stored now: the usual check on the target itself. */
class TargetAuthorizer implements Authorizer
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(protected \PDO $pdo)
    {
    }

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        $this->calls[] = 'before:'.$action->target['resource'];
        $query = $this->pdo->prepare('SELECT tenant FROM test_articles WHERE id = ?');
        $query->execute([$action->target['resource']]);
        if ($query->fetchColumn() !== $actor->tenantId) {
            throw new ApprovalException('permission_denied', 'This account cannot change this article.');
        }
    }
}

/** The same check, and after the action ran, the same decision from what was frozen and what the action did. */
final class DeletionAuthorizer extends TargetAuthorizer implements ExecutedActionAuthorizer
{
    public function __construct(\PDO $pdo, private bool $refuseAfter = false)
    {
        parent::__construct($pdo);
    }

    public function assertStillAllowed(Actor $actor, PreparedAction $action, array $result): void
    {
        $this->calls[] = 'after:'.$action->target['resource'];
        if ($this->refuseAfter || $action->preconditions['tenant'] !== $actor->tenantId || $result !== ['deleted' => $action->target['resource']]) {
            throw new ApprovalException('permission_denied', 'This account cannot delete this article.');
        }
    }
}
