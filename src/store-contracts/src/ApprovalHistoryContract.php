<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts;

use PHPUnit\Framework\Attributes\DataProvider;
use VerifiedHumanApproval\Core\ApprovalHistory;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\UnreadableApproval;

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

    /**
     * A record edited in the database keeps its place in the history: as its state when it still reads back, for the
     * caller to check, or as an UnreadableApproval naming it. The requests around it are listed as before.
     *
     * @param \Closure(StoreHarness, string): void $edit
     */
    #[DataProvider('editedRecords')]
    public function testAHistoryListsAnEditedRecordInItsPlace(\Closure $edit): void
    {
        $harness = $this->connect();
        $edited = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'history-edited');
        $ceremony = $harness->service()->begin($harness->reviewer(), $edited->request->id);
        $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
        $newer = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'history-newer');
        $other = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 2]);
        $edit($harness, $edited->request->id);

        $history = $this->history();
        $listed = $history->forResource('tenant-a', '1');
        self::assertSame([$newer->request->id, $edited->request->id], self::listedIds($listed));
        if ($listed[1] instanceof UnreadableApproval) {
            self::assertSame('record_integrity_error', $listed[1]->error->reason);
        }
        self::assertSame([$other->request->id], self::listedIds($history->forResource('tenant-a', '2')));
    }

    /** A proposal that is not JSON at all fails no history: the requests about each resource are still listed. */
    public function testAProposalThatIsNotJsonFailsNoHistory(): void
    {
        $harness = $this->connect();
        $kept = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'history-kept');
        $other = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 2]);
        $damaged = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'history-damaged');
        $harness->editRecord($damaged->request->id, 'proposal', static fn (string $proposal): string => substr($proposal, 0, 40));

        $history = $this->history();
        // A store that reads the resource from the proposal cannot tell which history it belongs to.
        self::assertContains(self::listedIds($history->forResource('tenant-a', '1')), [[$damaged->request->id, $kept->request->id], [$kept->request->id]]);
        self::assertSame([$other->request->id], self::listedIds($history->forResource('tenant-a', '2')));
    }

    private function history(): ApprovalHistory
    {
        $history = $this->connect()->store();
        self::assertInstanceOf(ApprovalHistory::class, $history, 'A store that takes the history contract keeps a history by resource.');

        return $history;
    }

    /**
     * @param list<ApprovalState|UnreadableApproval> $listed
     *
     * @return list<string>
     */
    private static function listedIds(array $listed): array
    {
        return array_map(static fn (ApprovalState|UnreadableApproval $entry): string => $entry instanceof UnreadableApproval ? $entry->requestId : $entry->request->id, $listed);
    }
}
