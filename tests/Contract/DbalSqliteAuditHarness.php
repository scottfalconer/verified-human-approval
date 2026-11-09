<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalAuditStore on SQLite, rollback journal. */
final class DbalSqliteAuditHarness extends DbalAuditHarness
{
    protected const KIND = 'sqlite';
}
