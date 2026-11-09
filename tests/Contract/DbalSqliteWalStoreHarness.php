<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalApprovalStore on SQLite, write-ahead log. */
final class DbalSqliteWalStoreHarness extends DbalStoreHarness
{
    protected const KIND = 'sqlite-wal';
}
