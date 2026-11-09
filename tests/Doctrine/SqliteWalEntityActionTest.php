<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** An approved ORM action on SQLite, write-ahead log. */
final class SqliteWalEntityActionTest extends EntityActionTestCase
{
    protected const KIND = 'sqlite-wal';
}
