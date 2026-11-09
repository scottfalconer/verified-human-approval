<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Tests\Support\CoreFixture;

/**
 * Lifecycle rules around one proposal's ceremonies. The storage transaction rules themselves, with their
 * rollback, recovery and concurrency checks, are the shared contract in tests/Contract.
 */
final class TransactionTest extends TestCase
{
    public function testASecondReviewDoesNotCancelTheFirstAndTheActionRunsOnce(): void
    {
        $fixture = new CoreFixture();
        $pending = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1]);
        $first = $fixture->service->begin($fixture->reviewer, $pending->request->id);
        $second = $fixture->service->begin($fixture->reviewer, $pending->request->id);
        self::assertSame('completed', $fixture->service->complete($fixture->reviewer, $first->id, $fixture->proof($first))->status, 'Another session starting a review cannot cancel one already in progress.');
        self::assertSame('completed', $fixture->service->complete($fixture->reviewer, $second->id, $fixture->proof($second))->status, 'The other review reads the recorded outcome.');
        self::assertSame(1, $fixture->effectCount(), 'The action runs once.');
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
