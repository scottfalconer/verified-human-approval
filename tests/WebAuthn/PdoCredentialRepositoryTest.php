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

    /** A duplicate passkey must fail loudly: an insert that failed quietly would be anchored as an enrollment. */
    public function testAConnectionThatDoesNotThrowOnErrorsIsRefusedAndLeftAsItWas(): void
    {
        $silent = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        try {
            new PdoCredentialRepository($silent);
            self::fail('A connection that does not throw on errors was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('PDO::ERRMODE_EXCEPTION', $error->getMessage());
        }
        self::assertSame(\PDO::ERRMODE_SILENT, $silent->getAttribute(\PDO::ATTR_ERRMODE));
    }
}
