<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** The SQLite reference adapter in write-ahead-log mode, as the demo runs it. */
final class SqliteWalStoreHarness extends SqliteStoreHarness
{
    protected const JOURNAL = 'wal';
}
