<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** An approved ORM action on PostgreSQL. */
final class PostgresEntityActionTest extends EntityActionTestCase
{
    protected const KIND = 'postgresql';
}
