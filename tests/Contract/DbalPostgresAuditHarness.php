<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

/** DbalAuditStore on PostgreSQL. */
final class DbalPostgresAuditHarness extends DbalAuditHarness
{
    protected const KIND = 'postgresql';
}
