<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The approval storage contract on the Doctrine DBAL adapter, PostgreSQL. */
final class DbalPostgresApprovalStoreContractTest extends ApprovalStoreContract
{
    protected static function harness(): string
    {
        return DbalPostgresStoreHarness::class;
    }
}
