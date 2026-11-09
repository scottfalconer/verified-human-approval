<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The SQLite reference audit store in write-ahead-log mode, as the demo runs it. */
final class SqliteWalAuditHarness extends SqliteAuditHarness
{
    protected const JOURNAL = 'wal';
}
