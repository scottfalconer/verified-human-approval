<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The audit storage contract on the Doctrine DBAL adapter, SQLite, write-ahead log. */
final class DbalSqliteWalAuditStoreContractTest extends AuditStoreContract
{
    protected static function harness(): string
    {
        return DbalSqliteWalAuditHarness::class;
    }
}
