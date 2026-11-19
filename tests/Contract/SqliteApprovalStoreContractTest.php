<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\ApprovalHistoryContract;
use VerifiedHumanApproval\StoreContracts\ApprovalStoreContract;

/** The approval storage contract on the SQLite reference adapter, rollback journal. */
final class SqliteApprovalStoreContractTest extends ApprovalStoreContract
{
    use ApprovalHistoryContract;
    use RecentApprovalsContract;

    protected static function harness(): string
    {
        return SqliteStoreHarness::class;
    }
}
