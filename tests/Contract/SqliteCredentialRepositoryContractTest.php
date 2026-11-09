<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\CredentialRepositoryContract;

/** The passkey storage contract on the SQLite reference adapter. */
final class SqliteCredentialRepositoryContractTest extends CredentialRepositoryContract
{
    protected static function harness(): string
    {
        return SqliteCredentialHarness::class;
    }
}
