<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\ApprovalStoreContract;

/** The approval storage contract on the SQLite reference adapter, write-ahead log. */
final class SqliteWalApprovalStoreContractTest extends ApprovalStoreContract
{
    protected static function harness(): string
    {
        return SqliteWalStoreHarness::class;
    }
}
