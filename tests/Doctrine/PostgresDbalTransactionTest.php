<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** The approval transaction on PostgreSQL. */
final class PostgresDbalTransactionTest extends DbalTransactionTestCase
{
    protected const KIND = 'postgresql';
}
