<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\AuditStoreContract;

/** The audit storage contract on the SQLite reference adapter, rollback journal. */
final class SqliteAuditStoreContractTest extends AuditStoreContract
{
    protected static function harness(): string
    {
        return SqliteAuditHarness::class;
    }
}
