<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use VerifiedHumanApproval\Core\ActionRegistry;
use VerifiedHumanApproval\Core\ApprovalObserver;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Tests\Contract\FaultingStore;
use VerifiedHumanApproval\Tests\Contract\Faults;
use VerifiedHumanApproval\Tests\Support\CoreFixture;
use VerifiedHumanApproval\Tests\Support\FixtureAction;

/** What an action learns about the approval it runs under, and what observers hear once it commits. */
final class AfterCommitTest extends TestCase
{
    private CoreFixture $fixture;
    private ExecutionGuard $guard;
    private FixtureAction $action;
    private Faults $faults;
    /** @var list<ApprovalState> */
    private array $heard = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->fixture = new CoreFixture();
        $this->guard = new ExecutionGuard();
        $this->action = new FixtureAction($this->fixture->pdo, $this->guard);
        $this->faults = new Faults();
    }

    public function testAnApprovedActionKnowsItsRequestAndObserversHearOnceAfterCommit(): void
    {
        $service = $this->service([$this->recorder()]);
        $pending = $service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        self::assertSame([], $this->heard, 'Nothing has run yet.');

        $ceremony = $service->begin($this->fixture->reviewer, $pending->request->id);
        $done = $service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertSame('completed', $done->status);
        self::assertNotNull($this->action->executing);
        self::assertSame($pending->request->id, $this->action->executing->requestId);
        self::assertSame('article.publish', $this->action->executing->actionId);
        self::assertTrue($this->action->executing->userVerified());
        self::assertNull($this->guard->current(), 'Outside an execution there is no current approval.');
        self::assertSame([$pending->request->id], array_map(static fn (ApprovalState $state): string => $state->request->id, $this->heard));
        self::assertSame('completed', $this->heard[0]->status);

        // A replay returns the recorded result and tells nobody again.
        $again = $service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));
        self::assertEquals($done->execution, $again->execution);
        self::assertCount(1, $this->heard);
    }

    public function testAnActionARuleLetsRunIsReportedAsNotRequiringAPasskey(): void
    {
        $this->fixture->pdo->exec("UPDATE test_policies SET required = 0 WHERE action_id = 'article.publish'");
        $done = $this->service([$this->recorder()])->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        self::assertSame('completed', $done->status);
        self::assertNotNull($this->action->executing);
        self::assertSame('not_required', $this->action->executing->approval);
        self::assertFalse($this->action->executing->userVerified());
        self::assertCount(1, $this->heard);
    }

    public function testAFailingObserverIsLoggedAndChangesNothing(): void
    {
        $failing = new class implements ApprovalObserver {
            public function completed(ApprovalState $state): void
            {
                throw new \RuntimeException('The cache purge failed.');
            }
        };
        $service = $this->service([$failing, $this->recorder()]);
        $pending = $service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $service->begin($this->fixture->reviewer, $pending->request->id);
        $done = $service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));

        self::assertSame('completed', $done->status, 'The change committed; an observer cannot undo it.');
        self::assertCount(1, $this->heard, 'The next observer still hears about it.');
        self::assertSame(['An approval observer failed after the action committed.'], $this->logged);
        self::assertSame(1, $this->fixture->effectCount());
    }

    public function testAnAmbiguousCommitIsStillReportedOnceRecovered(): void
    {
        $service = $this->service([$this->recorder()]);
        $pending = $service->request($this->fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $service->begin($this->fixture->reviewer, $pending->request->id);
        // The completing transaction commits, and then its answer is lost.
        $this->faults->transactions = ['after_commit'];
        $done = $service->complete($this->fixture->reviewer, $ceremony->id, $this->fixture->proof($ceremony));

        self::assertSame('completed', $done->status);
        self::assertSame(1, $this->fixture->effectCount());
        self::assertCount(1, $this->heard, 'The recovered commit is reported.');
    }

    public function testHistoryListsTheRequestsAboutOneResourceNewestFirst(): void
    {
        $service = $this->service();
        $first = $service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'first');
        $other = $service->request($this->fixture->actor, 'article.publish', ['id' => 2], 'other');
        $second = $service->request($this->fixture->actor, 'article.publish', ['id' => 1], 'second');

        $ids = static fn (array $states): array => array_map(static fn (ApprovalState $state): string => $state->request->id, $states);
        self::assertSame([$second->request->id, $first->request->id], $ids($this->fixture->store->forResource('tenant-a', '1')));
        self::assertSame([$other->request->id], $ids($this->fixture->store->forResource('tenant-a', '2')));
        self::assertSame([], $this->fixture->store->forResource('tenant-b', '1'), 'Another tenant has no history here.');
        self::assertSame([$second->request->id], $ids($this->fixture->store->forResource('tenant-a', '1', 1)));
    }

    /** @param list<ApprovalObserver> $observers */
    private function service(array $observers = []): ApprovalService
    {
        $logger = new class($this->logged) extends AbstractLogger {
            /** @param list<string> $messages */
            public function __construct(private array &$messages)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        return new ApprovalService(
            new ActionRegistry([$this->action]),
            $this->fixture->policies,
            $this->fixture->authorizer,
            new FaultingStore($this->fixture->store, $this->faults),
            $this->fixture->verifier,
            $this->fixture->clock,
            $this->guard,
            null,
            $observers,
            $logger,
        );
    }

    private function recorder(): ApprovalObserver
    {
        return new class($this->heard) implements ApprovalObserver {
            /** @param list<ApprovalState> $heard */
            public function __construct(private array &$heard)
            {
            }

            public function completed(ApprovalState $state): void
            {
                $this->heard[] = $state;
            }
        };
    }
}
