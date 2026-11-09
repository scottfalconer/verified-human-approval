<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\CredentialRepositoryContract;

/** The passkey storage contract on the in-memory stand-in for a host's own storage. */
final class InMemoryCredentialRepositoryContractTest extends CredentialRepositoryContract
{
    protected static function harness(): string
    {
        return InMemoryCredentialHarness::class;
    }
}
