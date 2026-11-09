<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The passkey storage contract on the Doctrine DBAL adapter, MySQL or MariaDB. */
final class DbalMysqlCredentialRepositoryContractTest extends CredentialRepositoryContract
{
    protected static function harness(): string
    {
        return DbalMysqlCredentialHarness::class;
    }
}
