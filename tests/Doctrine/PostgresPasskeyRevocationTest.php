<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** A revoked passkey on the Doctrine stores, on PostgreSQL. */
final class PostgresPasskeyRevocationTest extends PasskeyRevocationTestCase
{
    protected const KIND = 'postgresql';
}
