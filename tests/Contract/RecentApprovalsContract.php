<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Core\UnreadableApproval;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;
use VerifiedHumanApproval\StoreContracts\StoreHarness;

/**
 * recent(), the audit list both supplied stores offer beside the history: a record edited in the database keeps its
 * place in it, so an audit page can flag it instead of failing.
 *
 * @phpstan-require-extends \VerifiedHumanApproval\StoreContracts\ApprovalStoreContract
 */
trait RecentApprovalsContract
{
    /** @param \Closure(StoreHarness, string): void $edit */
    #[DataProvider('editedRecords')]
    public function testTheAuditListShowsAnEditedRecordInItsPlace(\Closure $edit, bool $proposalReadsBack): void
    {
        $harness = $this->connect();
        $edited = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'recent-edited');
        $ceremony = $harness->service()->begin($harness->reviewer(), $edited->request->id);
        $harness->service()->complete($harness->reviewer(), $ceremony->id, $harness->proof($ceremony));
        $newer = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 2]);
        $edit($harness, $edited->request->id);

        $listed = $this->recent();
        self::assertSame([$newer->request->id, $edited->request->id], array_map(static fn (ApprovalState|UnreadableApproval $entry): string => $entry instanceof UnreadableApproval ? $entry->requestId : $entry->request->id, $listed));
        if ($listed[1] instanceof UnreadableApproval) {
            self::assertSame('record_integrity_error', $listed[1]->error->reason);
            self::assertSame('tenant-a', $listed[1]->tenantId, 'The tenant the row names, so the host can decide who sees it.');
            self::assertSame($proposalReadsBack ? $edited->request->id : null, $listed[1]->error->request?->id);
        }
    }

    public function testTheAuditListShowsAProposalThatIsNotJson(): void
    {
        $harness = $this->connect();
        $damaged = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1]);
        $harness->editRecord($damaged->request->id, 'proposal', static fn (string $proposal): string => substr($proposal, 0, 40));

        [$listed] = $this->recent();
        self::assertInstanceOf(UnreadableApproval::class, $listed);
        self::assertSame($damaged->request->id, $listed->requestId);
        self::assertSame('record_integrity_error', $listed->error->reason);
    }

    /** @return list<ApprovalState|UnreadableApproval> */
    private function recent(): array
    {
        $store = $this->connect()->store();
        if (!$store instanceof PdoApprovalStore && !$store instanceof DbalApprovalStore) {
            self::fail('recent() belongs to the supplied stores.');
        }

        return $store->recent();
    }
}
