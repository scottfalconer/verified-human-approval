<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** The approval tables on PostgreSQL. */
final class PostgresApprovalSchemaTest extends ApprovalSchemaTestCase
{
    protected const KIND = 'postgresql';
}
