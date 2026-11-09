<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\AuditStoreContract;

/** The audit storage contract on the Doctrine DBAL adapter, PostgreSQL. */
final class DbalPostgresAuditStoreContractTest extends AuditStoreContract
{
    protected static function harness(): string
    {
        return DbalPostgresAuditHarness::class;
    }
}
