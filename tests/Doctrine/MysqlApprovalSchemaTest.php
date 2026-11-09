<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** The approval tables on MySQL or MariaDB. */
final class MysqlApprovalSchemaTest extends ApprovalSchemaTestCase
{
    protected const KIND = 'mysql';
}
