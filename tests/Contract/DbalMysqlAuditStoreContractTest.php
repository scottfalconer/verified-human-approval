<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The audit storage contract on the Doctrine DBAL adapter, MySQL or MariaDB. */
final class DbalMysqlAuditStoreContractTest extends AuditStoreContract
{
    protected static function harness(): string
    {
        return DbalMysqlAuditHarness::class;
    }
}
