<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The passkey storage contract on the Doctrine DBAL adapter, PostgreSQL. */
final class DbalPostgresCredentialRepositoryContractTest extends CredentialRepositoryContract
{
    protected static function harness(): string
    {
        return DbalPostgresCredentialHarness::class;
    }
}
