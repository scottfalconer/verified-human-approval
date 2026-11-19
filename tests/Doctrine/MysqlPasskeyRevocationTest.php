<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine;

/** A revoked passkey on the Doctrine stores, on MySQL or MariaDB. */
final class MysqlPasskeyRevocationTest extends PasskeyRevocationTestCase
{
    protected const KIND = 'mysql';
}
