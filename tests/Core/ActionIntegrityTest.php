<?php

declare(strict_types=1);

namespace ExactApproval\Tests\Core;

use ExactApproval\Core\ActionAdapter;
use ExactApproval\Core\ActionRegistry;
use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalService;
use ExactApproval\Core\ExecutionGuard;
use ExactApproval\Core\PreparedAction;
use ExactApproval\Tests\Support\CoreFixture;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActionIntegrityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function callbackMutations(): iterable
    {
        yield 'preconditions mutate nested input' => ['precondition'];
        yield 'execution mutates nested input' => ['execute'];
        yield 'postconditions mutate their baseline' => ['postcondition'];
    }

    #[DataProvider('callbackMutations')]
    public function testNestedObjectsCannotChangeTheFrozenExpectedEffectDuringCallbacks(string $stage): void
    {
        $fixture = new CoreFixture();
        $guard = new ExecutionGuard();
        $adapter = new class($fixture->pdo, $guard) implements ActionAdapter {
            public string $mutateAt = '';

            public function __construct(private readonly PDO $pdo, private readonly ExecutionGuard $guard) {}
            public function id(): string { return 'article.publish'; }
            public function version(): string { return '1'; }

            public function prepare(Actor $actor, array $input): PreparedAction
            {
                $row = $this->row();

                return new PreparedAction(
                    $this->id(), $this->version(), ['tenant' => 'tenant-a', 'resource' => 1],
                    ['content' => (object) ['body' => $row['body']], 'published' => true],
                    ['revision' => (int) $row['revision']], ['body' => $row['body']],
                    ['title' => $row['title'], 'revision' => (string) $row['revision']],
                );
            }

            public function assertPreconditions(Actor $actor, PreparedAction $action): void
            {
                if ((int) $this->row()['revision'] !== $action->preconditions['revision']) {
                    throw new ApprovalException('stale_resource', 'Revision changed.');
                }
                if ($this->mutateAt === 'precondition') {
                    $content = $action->effect['content'];
                    $content->body = 'Unapproved mutation in preconditions.';
                }
            }

            public function execute(Actor $actor, PreparedAction $action): array
            {
                $this->guard->assertExecuting($actor, $action);
                if ($this->mutateAt === 'execute') {
                    // A mutable object accidentally shared with a hook alters
                    // both the proposed write and a naive postcondition baseline.
                    $content = $action->effect['content'];
                    $content->body = 'Unapproved mutation during execution.';
                }
                $statement = $this->pdo->prepare('UPDATE test_articles SET body = ?, revision = revision + 1, published = 1, publication_count = publication_count + 1 WHERE id = 1');
                $statement->execute([$action->effect['content']->body]);

                return ['published' => true];
            }

            public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
            {
                if ($this->row()['body'] !== $action->effect['content']->body) {
                    throw new ApprovalException('postcondition_failed', 'Final content differs from the expected content.');
                }
                if ($this->mutateAt === 'postcondition') {
                    $content = $action->effect['content'];
                    $content->body = 'Changed while inspecting the postcondition.';
                }
            }

            private function row(): array
            {
                return $this->pdo->query('SELECT * FROM test_articles WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            }
        };
        $service = new ApprovalService(new ActionRegistry([$adapter]), $fixture->policies, $fixture->authorizer, $fixture->store, $fixture->verifier, $fixture->clock, $guard);
        $request = $service->request($fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $service->begin($fixture->reviewer, $request->request->id);
        $before = $fixture->article();
        $adapter->mutateAt = $stage;
        try {
            $service->complete($fixture->reviewer, $ceremony->id, $fixture->proof($ceremony));
            self::fail('A callback changed the frozen expected effect and still committed.');
        } catch (ApprovalException $exception) {
            self::assertSame('execution_failed', $exception->reason);
        }
        self::assertSame($before, $fixture->article());
        self::assertSame(0, $fixture->effectCount());
        self::assertSame(0, (int) $fixture->pdo->query('SELECT COUNT(*) FROM exact_approval_executions')->fetchColumn());
        self::assertSame($request->request->canonical, $service->get($fixture->actor, $request->request->id)->request->canonical);
    }
}
