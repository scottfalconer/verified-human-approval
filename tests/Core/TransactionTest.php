<?php

declare(strict_types=1);

namespace ExactApproval\Tests\Core;

use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalState;
use ExactApproval\Core\Ceremony;
use ExactApproval\Tests\Support\CoreFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rollbackPoints(): iterable
    {
        yield 'business action throws after write' => ['execute'];
        yield 'post-save hook changes approved content' => ['postcondition'];
        yield 'execution record insert fails' => ['receipt'];
    }

    #[DataProvider('rollbackPoints')]
    public function testFailureRollsBackBusinessStateProofAndReceiptAndRequiresFreshCeremony(string $point): void
    {
        $fixture = new CoreFixture();
        $request = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $fixture->service->begin($fixture->reviewer, $request->request->id);
        $initial = $fixture->article();
        match ($point) {
            'execute' => $fixture->action->failAfterWrite = true,
            'postcondition' => $fixture->action->alterAfterWrite = true,
            'receipt' => $fixture->pdo->exec("CREATE TRIGGER reject_receipt BEFORE INSERT ON exact_approval_executions BEGIN SELECT RAISE(ABORT, 'injected receipt persistence failure'); END"),
        };

        try {
            $fixture->service->complete($fixture->reviewer, $ceremony->id, $fixture->proof($ceremony));
            self::fail('An injected transaction failure was reported as successful.');
        } catch (ApprovalException $exception) {
            self::assertSame('execution_failed', $exception->reason);
        }
        self::assertSame($initial, $fixture->article(), 'The real business row must roll back, including the revision and effect counter.');
        self::assertSame(0, $fixture->effectCount());
        self::assertSame(0, (int) $fixture->pdo->query('SELECT COUNT(*) FROM exact_approval_executions')->fetchColumn());
        self::assertSame(0, (int) $fixture->pdo->query("SELECT verification_count FROM test_credentials WHERE id = 'alice-key'")->fetchColumn());
        $stored = $fixture->service->get($fixture->actor, $request->request->id);
        self::assertSame('pending', $stored->status);
        self::assertNull($stored->proof);
        self::assertNull($stored->execution);
        self::assertTrue($fixture->store->getCeremony($ceremony->id)->consumed);
        try {
            $fixture->service->complete($fixture->reviewer, $ceremony->id, $fixture->proof($ceremony));
            self::fail('A failed attempt reused the original approval ceremony.');
        } catch (ApprovalException $exception) {
            self::assertSame('ceremony_consumed', $exception->reason);
        }

        $fixture->action->failAfterWrite = false;
        $fixture->action->alterAfterWrite = false;
        if ($point === 'receipt') {
            $fixture->pdo->exec('DROP TRIGGER reject_receipt');
        }
        $fresh = $fixture->service->begin($fixture->reviewer, $request->request->id);
        $result = $fixture->service->complete($fixture->reviewer, $fresh->id, $fixture->proof($fresh));
        self::assertSame('completed', $result->status);
        self::assertSame(1, $fixture->effectCount());
        self::assertSame(1, (int) $fixture->pdo->query('SELECT COUNT(*) FROM exact_approval_executions')->fetchColumn());
        self::assertSame(1, (int) $fixture->pdo->query("SELECT verification_count FROM test_credentials WHERE id = 'alice-key'")->fetchColumn());
    }

    public function testCompletedRecordCannotBeOverwritten(): void
    {
        $fixture = new CoreFixture();
        $pending = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
        $ceremony = $fixture->service->begin($fixture->reviewer, $pending->request->id);
        $completed = $fixture->service->complete($fixture->reviewer, $ceremony->id, $fixture->proof($ceremony));
        try {
            $fixture->store->transaction(fn () => $fixture->store->save(new ApprovalState($pending->request, 'cancelled', 'overwrite')));
            self::fail('An immutable terminal record was overwritten.');
        } catch (ApprovalException $exception) {
            self::assertSame('immutable_approval', $exception->reason);
        }
        self::assertSame('completed', $fixture->service->get($fixture->actor, $completed->request->id)->status);
        self::assertSame(1, $fixture->effectCount());
    }

    public function testStoreRefusesWritesOutsideTheOwnedOuterTransaction(): void
    {
        $fixture = new CoreFixture();
        $pending = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
        $this->expectException(\LogicException::class);
        $fixture->store->save($pending->stopped('cancelled', 'unsafe_write'));
    }

    public function testExistingApplicationTransactionCannotSilentlyBreakTheAtomicBoundary(): void
    {
        $fixture = new CoreFixture();
        $fixture->pdo->beginTransaction();
        try {
            $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
            self::fail('The approval engine nested within a transaction it does not own.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('outer database transaction', $exception->getMessage());
        } finally {
            $fixture->pdo->rollBack();
        }
        self::assertSame(0, $fixture->effectCount());
    }

    public function testNewReviewRetiresOldChallengeButAllowsTheNewOne(): void
    {
        $fixture = new CoreFixture();
        $pending = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
        $old = $fixture->service->begin($fixture->reviewer, $pending->request->id);
        $new = $fixture->service->begin($fixture->reviewer, $pending->request->id);
        try {
            $fixture->service->complete($fixture->reviewer, $old->id, $fixture->proof($old));
            self::fail('An older review challenge remained active.');
        } catch (ApprovalException $exception) {
            self::assertSame('ceremony_consumed', $exception->reason);
        }
        self::assertSame('completed', $fixture->service->complete($fixture->reviewer, $new->id, $fixture->proof($new))->status);
        self::assertSame(1, $fixture->effectCount());
    }

    public function testCeremonyExpirationDoesNotConsumeTheLongerLivedProposal(): void
    {
        $fixture = new CoreFixture();
        $pending = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
        $old = $fixture->service->begin($fixture->reviewer, $pending->request->id);
        $fixture->clock->sleep(300);
        try {
            $fixture->service->complete($fixture->reviewer, $old->id, $fixture->proof($old));
            self::fail('An expired ceremony was accepted.');
        } catch (ApprovalException $exception) {
            self::assertSame('ceremony_expired', $exception->reason);
        }
        self::assertSame('pending', $fixture->service->get($fixture->actor, $pending->request->id)->status);
        $fresh = $fixture->service->begin($fixture->reviewer, $pending->request->id);
        self::assertSame('completed', $fixture->service->complete($fixture->reviewer, $fresh->id, $fixture->proof($fresh))->status);
        self::assertSame(1, $fixture->effectCount());
    }
}
