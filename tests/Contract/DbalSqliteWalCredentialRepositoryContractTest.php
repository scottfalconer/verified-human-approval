<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The passkey storage contract on the Doctrine DBAL adapter, SQLite write-ahead log. */
final class DbalSqliteWalCredentialRepositoryContractTest extends CredentialRepositoryContract
{
    protected static function harness(): string
    {
        return DbalSqliteWalCredentialHarness::class;
    }
}
