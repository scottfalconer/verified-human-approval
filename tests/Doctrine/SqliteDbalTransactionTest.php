<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** The approval transaction on SQLite, rollback journal. */
final class SqliteDbalTransactionTest extends DbalTransactionTestCase
{
    protected const KIND = 'sqlite';
}
