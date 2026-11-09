<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\ApprovalHistoryContract;
use VerifiedHumanApproval\StoreContracts\ApprovalStoreContract;

/** The approval storage contract on the Doctrine DBAL adapter, SQLite, rollback journal. */
final class DbalSqliteApprovalStoreContractTest extends ApprovalStoreContract
{
    use ApprovalHistoryContract;

    protected static function harness(): string
    {
        return DbalSqliteStoreHarness::class;
    }
}
