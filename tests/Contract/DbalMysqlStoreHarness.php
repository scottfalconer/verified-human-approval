<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalApprovalStore on MySQL or MariaDB. */
final class DbalMysqlStoreHarness extends DbalStoreHarness
{
    protected const KIND = 'mysql';
}
