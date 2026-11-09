<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalApprovalStore on SQLite, rollback journal. */
final class DbalSqliteStoreHarness extends DbalStoreHarness
{
    protected const KIND = 'sqlite';
}
