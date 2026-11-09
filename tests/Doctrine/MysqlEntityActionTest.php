<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** An approved ORM action on MySQL or MariaDB. */
final class MysqlEntityActionTest extends EntityActionTestCase
{
    protected const KIND = 'mysql';
}
