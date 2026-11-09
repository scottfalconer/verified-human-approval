<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\AuditStoreContract;

/** The audit storage contract on the Doctrine DBAL adapter, SQLite, write-ahead log. */
final class DbalSqliteWalAuditStoreContractTest extends AuditStoreContract
{
    protected static function harness(): string
    {
        return DbalSqliteWalAuditHarness::class;
    }
}
