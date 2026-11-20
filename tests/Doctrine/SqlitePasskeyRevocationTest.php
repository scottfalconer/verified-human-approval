<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** A revoked passkey on the Doctrine stores, on SQLite. */
final class SqlitePasskeyRevocationTest extends PasskeyRevocationTestCase
{
    protected const KIND = 'sqlite';
}
