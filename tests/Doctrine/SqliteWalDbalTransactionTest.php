<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** The approval transaction on SQLite, write-ahead log. */
final class SqliteWalDbalTransactionTest extends DbalTransactionTestCase
{
    protected const KIND = 'sqlite-wal';
}
