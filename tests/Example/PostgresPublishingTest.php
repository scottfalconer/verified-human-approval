<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Example;

/** The publishing example on PostgreSQL, the server VERIFIED_HUMAN_APPROVAL_POSTGRES_URL names. */
final class PostgresPublishingTest extends PublishingTestCase
{
    protected const KIND = 'postgresql';
}
