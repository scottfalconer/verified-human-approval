<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VerifiedHumanApproval\Core\Storage\PdoApprovalStore;
use VerifiedHumanApproval\Core\Storage\PdoAuditStore;

/**
 * The SQLite stores work on the application's own connection as it is: they change none of its settings, and refuse
 * the one setting they cannot work with, a connection that does not throw on errors.
 */
final class PdoConnectionSettingsTest extends TestCase
{
    /** @return iterable<string, array{\Closure(\PDO): object}> */
    public static function stores(): iterable
    {
        yield 'the approval store' => [static fn (\PDO $pdo): object => new PdoApprovalStore($pdo)];
        yield 'the audit store' => [static fn (\PDO $pdo): object => new PdoAuditStore($pdo)];
    }

    /** @param \Closure(\PDO): object $store */
    #[DataProvider('stores')]
    public function testTheApplicationsConnectionKeepsItsSettings(\Closure $store): void
    {
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 2500');
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $store($pdo);
        self::assertSame(['busy_timeout' => 2500, 'foreign_keys' => 0], [
            'busy_timeout' => (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn(),
            'foreign_keys' => (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn(),
        ]);
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    /** @param \Closure(\PDO): object $store */
    #[DataProvider('stores')]
    public function testAConnectionThatDoesNotThrowOnErrorsIsRefused(\Closure $store): void
    {
        foreach ([\PDO::ERRMODE_SILENT, \PDO::ERRMODE_WARNING] as $mode) {
            $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => $mode]);
            try {
                $store($pdo);
                self::fail('A connection that does not throw on errors was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('PDO::ERRMODE_EXCEPTION', $error->getMessage());
            }
            self::assertSame($mode, $pdo->getAttribute(\PDO::ATTR_ERRMODE), 'The connection is left as it was.');
        }
    }
}
