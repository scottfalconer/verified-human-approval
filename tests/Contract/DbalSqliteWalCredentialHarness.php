<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalCredentialRepository on SQLite, write-ahead log. */
final class DbalSqliteWalCredentialHarness extends DbalCredentialHarness
{
    protected const KIND = 'sqlite-wal';
}
