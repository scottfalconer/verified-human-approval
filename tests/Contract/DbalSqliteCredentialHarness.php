<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalCredentialRepository on SQLite, rollback journal. */
final class DbalSqliteCredentialHarness extends DbalCredentialHarness
{
    protected const KIND = 'sqlite';
}
