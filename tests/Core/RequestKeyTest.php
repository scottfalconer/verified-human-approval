<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Tests\Support\CoreFixture;

/** A request key replays its outcome only while the content it named is unchanged. */
final class RequestKeyTest extends TestCase
{
    public function testAReplayedKeyReturnsTheRecordedOutcomeWhileTheContentIsUnchanged(): void
    {
        $fixture = new CoreFixture();
        $pending = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1], 'key-1');
        $ceremony = $fixture->service->begin($fixture->reviewer, $pending->request->id);
        $fixture->service->complete($fixture->reviewer, $ceremony->id, $fixture->proof($ceremony));

        $replay = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1], 'key-1');
        self::assertSame('completed', $replay->status);
        self::assertSame($pending->request->id, $replay->request->id);
        self::assertSame(1, $fixture->effectCount());
    }

    public function testAKeyCannotReplayAnEarlierOutcomeForChangedContent(): void
    {
        $fixture = new CoreFixture();
        $pending = $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1], 'key-1');
        $ceremony = $fixture->service->begin($fixture->reviewer, $pending->request->id);
        $fixture->service->complete($fixture->reviewer, $ceremony->id, $fixture->proof($ceremony));
        $fixture->pdo->exec("UPDATE test_articles SET body = 'A new draft', revision = revision + 1 WHERE id = 1");

        try {
            $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1], 'key-1');
            self::fail('A stale key reported the earlier outcome as if it covered the new content.');
        } catch (ApprovalException $error) {
            self::assertSame('idempotency_conflict', $error->reason);
        }
        self::assertSame('pending', $fixture->service->request($fixture->actor, 'article.publish', ['id' => 1], 'key-2')->status, 'A new key asks for the new content.');
    }
}
