<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The approval storage contract on the Doctrine DBAL adapter, SQLite, write-ahead log. */
final class DbalSqliteWalApprovalStoreContractTest extends ApprovalStoreContract
{
    protected static function harness(): string
    {
        return DbalSqliteWalStoreHarness::class;
    }
}
