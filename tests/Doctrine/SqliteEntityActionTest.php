<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** An approved ORM action on SQLite, rollback journal. */
final class SqliteEntityActionTest extends EntityActionTestCase
{
    protected const KIND = 'sqlite';
}
