<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The approval storage contract on the Doctrine DBAL adapter, MySQL or MariaDB. */
final class DbalMysqlApprovalStoreContractTest extends ApprovalStoreContract
{
    protected static function harness(): string
    {
        return DbalMysqlStoreHarness::class;
    }
}
