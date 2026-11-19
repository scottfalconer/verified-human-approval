<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\WebAuthn;

use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;

/**
 * The SQLite reference repository refuses other databases, as the other reference adapters do. What it keeps on
 * SQLite is the passkey storage contract in tests/Contract.
 */
final class PdoCredentialRepositoryTest extends TestCase
{
    public function testAConnectionToAnotherDatabaseIsRefused(): void
    {
        $mysql = $this->createMock(\PDO::class);
        $mysql->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('mysql');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This reference repository supports SQLite only');
        new PdoCredentialRepository($mysql);
    }
}
