<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\AuditStoreContract;

/** The audit storage contract on the Doctrine DBAL adapter, SQLite, rollback journal. */
final class DbalSqliteAuditStoreContractTest extends AuditStoreContract
{
    protected static function harness(): string
    {
        return DbalSqliteAuditHarness::class;
    }
}
