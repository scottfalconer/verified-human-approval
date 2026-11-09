<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The passkey storage contract on the Doctrine DBAL adapter, SQLite rollback journal. */
final class DbalSqliteCredentialRepositoryContractTest extends CredentialRepositoryContract
{
    protected static function harness(): string
    {
        return DbalSqliteCredentialHarness::class;
    }
}
