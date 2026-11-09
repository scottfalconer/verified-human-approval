<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalAuditStore on MySQL or MariaDB. */
final class DbalMysqlAuditHarness extends DbalAuditHarness
{
    protected const KIND = 'mysql';
}
