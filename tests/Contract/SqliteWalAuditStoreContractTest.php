<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\AuditStoreContract;

/** The audit storage contract on the SQLite reference adapter, write-ahead log. */
final class SqliteWalAuditStoreContractTest extends AuditStoreContract
{
    protected static function harness(): string
    {
        return SqliteWalAuditHarness::class;
    }
}
