<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalCredentialRepository on PostgreSQL. */
final class DbalPostgresCredentialHarness extends DbalCredentialHarness
{
    protected const KIND = 'postgresql';
}
