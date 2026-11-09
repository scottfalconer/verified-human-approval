<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** The approval transaction on MySQL or MariaDB. */
final class MysqlDbalTransactionTest extends DbalTransactionTestCase
{
    protected const KIND = 'mysql';
}
