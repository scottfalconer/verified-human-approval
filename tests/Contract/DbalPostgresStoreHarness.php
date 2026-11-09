<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalApprovalStore on PostgreSQL. */
final class DbalPostgresStoreHarness extends DbalStoreHarness
{
    protected const KIND = 'postgresql';
}
