<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalCredentialRepository on MySQL or MariaDB. */
final class DbalMysqlCredentialHarness extends DbalCredentialHarness
{
    protected const KIND = 'mysql';
}
