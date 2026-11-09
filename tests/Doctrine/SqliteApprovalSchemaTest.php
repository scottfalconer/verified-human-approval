<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** The approval tables on SQLite, rollback journal. */
final class SqliteApprovalSchemaTest extends ApprovalSchemaTestCase
{
    protected const KIND = 'sqlite';
}
