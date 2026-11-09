<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts;

use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\ApprovalState;

/**
 * The history contract (ApprovalHistory::forResource()), for a store that keeps a history by resource: add this
 * trait to the store's ApprovalStoreContract test class. A store without one leaves it out, so its contract runs
 * with no test skipped.
 *
 * @phpstan-require-extends ApprovalStoreContract
 */
trait ApprovalHistoryContract
{
    public function testTheHistoryOfAResourceListsItsRequestsNewestFirst(): void
    {
        $harness = $this->connect();
        $history = $harness->store();
        self::assertInstanceOf(ApprovalHistory::class, $history, 'A store that takes the history contract keeps a history by resource.');
        $first = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'history-1');
        $second = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'history-2');
        $other = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 2]);
        $ids = static fn (array $states): array => array_map(static fn (ApprovalState $state): string => $state->request->id, $states);
        self::assertSame([$second->request->id, $first->request->id], $ids($history->forResource('tenant-a', '1')));
        self::assertSame([$other->request->id], $ids($history->forResource('tenant-a', '2')));
        self::assertSame([], $history->forResource('tenant-b', '1'), 'Another tenant has no history here.');
        self::assertSame([], $history->forResource('Tenant-A', '1'), 'Tenants compare exactly.');
        self::assertSame([$second->request->id], $ids($history->forResource('tenant-a', '1', 1)));
    }
}
