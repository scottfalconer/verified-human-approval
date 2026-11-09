<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalAuditStore on SQLite, write-ahead log. */
final class DbalSqliteWalAuditHarness extends DbalAuditHarness
{
    protected const KIND = 'sqlite-wal';
}
