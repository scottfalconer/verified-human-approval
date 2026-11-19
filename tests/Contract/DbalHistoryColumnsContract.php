<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\Core\UnreadableApproval;
use VerifiedHumanApproval\Doctrine\DbalApprovalStore;

/**
 * DbalApprovalStore finds a history by columns of its own, the tenant and the resource. An edit to them moves no request
 * whose proposal reads back into another tenant's lists, even when the rest of its record cannot be read.
 *
 * @phpstan-require-extends \VerifiedHumanApproval\StoreContracts\ApprovalStoreContract
 */
trait DbalHistoryColumnsContract
{
    public function testAnEditedColumnMovesNoRequestIntoAnotherTenantsLists(): void
    {
        $harness = $this->connect();
        self::assertInstanceOf(DbalStoreHarness::class, $harness);
        $intact = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'refiled-intact');
        $damaged = $harness->service()->request($harness->actor(), 'article.publish', ['id' => 1], 'refiled-damaged');
        $harness->editRecord($damaged->request->id, 'status', static fn (): string => 'done');
        $harness->refile($intact->request->id, 'tenant-b', '1');
        $harness->refile($damaged->request->id, 'tenant-b', '1');

        $store = $this->connect()->store();
        self::assertInstanceOf(DbalApprovalStore::class, $store);
        self::assertSame([], $store->forResource('tenant-b', '1'), 'Both proposals name tenant-a.');
        $listed = $store->recent();
        self::assertInstanceOf(UnreadableApproval::class, $listed[0]);
        self::assertSame($damaged->request->id, $listed[0]->requestId);
        self::assertSame('tenant-a', $listed[0]->tenantId, 'The tenant its proposal names, not the edited column.');
    }
}
