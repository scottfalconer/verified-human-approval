<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Assert;

/**
 * Throwaway databases for the Doctrine tests: a SQLite file in either journal, or a fresh database on the PostgreSQL
 * or MySQL server that VERIFIED_HUMAN_APPROVAL_POSTGRES_URL or VERIFIED_HUMAN_APPROVAL_MYSQL_URL names, such as
 * pdo-pgsql://user:password@127.0.0.1:5432/postgres or pdo-mysql://root:root@127.0.0.1:3306/mysql. Without the
 * variable, a test on that server is skipped, and says so.
 *
 * A location is the SQLite file's path, or the server database's name.
 */
final class TestDatabase
{
    public const KINDS = ['sqlite', 'sqlite-wal', 'postgresql', 'mysql'];

    private const SERVERS = ['postgresql' => 'VERIFIED_HUMAN_APPROVAL_POSTGRES_URL', 'mysql' => 'VERIFIED_HUMAN_APPROVAL_MYSQL_URL'];

    /** Creates an empty database and returns its location. Skips the test when the server is not configured. */
    public static function create(string $kind): string
    {
        if ('sqlite' === $kind || 'sqlite-wal' === $kind) {
            $location = sys_get_temp_dir().'/verified-human-approval-doctrine-'.bin2hex(random_bytes(8)).'.sqlite';
            $journal = 'sqlite' === $kind ? 'delete' : 'wal';
            if ($journal !== self::connect($kind, $location)->fetchOne('PRAGMA journal_mode = '.$journal)) {
                throw new \RuntimeException('SQLite did not switch to the '.$journal.' journal.');
            }

            return $location;
        }
        $name = 'vha_'.bin2hex(random_bytes(8));
        $server = self::server($kind);
        $server->executeStatement('mysql' === $kind
            ? 'CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'
            : 'CREATE DATABASE '.$name);
        $server->close();

        return $name;
    }

    public static function drop(string $kind, string $location): void
    {
        if ('sqlite' === $kind || 'sqlite-wal' === $kind) {
            foreach ([$location, $location.'-wal', $location.'-shm', $location.'-journal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            return;
        }
        if (!preg_match('/^vha_[0-9a-f]{16}$/D', $location)) {
            throw new \InvalidArgumentException('Not a test database: '.$location);
        }
        $server = self::server($kind);
        if ('postgresql' === $kind) {
            // Any connection a test left open is ended with it.
            $server->executeStatement('DROP DATABASE IF EXISTS '.$location.' WITH (FORCE)');
        } else {
            // A transaction a failed test left open would otherwise hold the drop for MySQL's default of a year.
            $server->executeStatement('SET SESSION lock_wait_timeout = 60');
            $server->executeStatement('DROP DATABASE IF EXISTS '.$location);
        }
        $server->close();
    }

    /** A new connection to a location, with a 15-second lock wait. */
    public static function connect(string $kind, string $location): Connection
    {
        $connection = match ($kind) {
            'sqlite', 'sqlite-wal' => DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $location]),
            default => DriverManager::getConnection(['dbname' => $location] + self::parameters($kind)),
        };
        self::lockTimeout($kind, $connection, 15000);

        return $connection;
    }

    /** How long a connection waits for a lock before it fails. MySQL counts whole seconds. */
    public static function lockTimeout(string $kind, Connection $connection, int $milliseconds): void
    {
        $connection->executeStatement(match ($kind) {
            'sqlite', 'sqlite-wal' => 'PRAGMA busy_timeout = '.$milliseconds,
            'postgresql' => "SET lock_timeout = '".$milliseconds."ms'",
            'mysql' => 'SET SESSION innodb_lock_wait_timeout = '.max(1, (int) ceil($milliseconds / 1000)),
            default => throw new \InvalidArgumentException('Unknown kind: '.$kind),
        });
    }

    /** Whether an error is a lock wait that ran out: SQLite's busy timeout, PostgreSQL's lock_timeout, MySQL's lock wait. */
    public static function isLockTimeout(\Throwable $error): bool
    {
        for (; null !== $error; $error = $error->getPrevious()) {
            if ($error instanceof LockWaitTimeoutException || ($error instanceof DriverException && '55P03' === $error->getSQLState())) {
                return true;
            }
        }

        return false;
    }

    /** The fixture schema's name for the kind's SQL. */
    public static function platform(string $kind): string
    {
        return match ($kind) {
            'sqlite', 'sqlite-wal' => 'sqlite',
            'postgresql', 'mysql' => $kind,
            default => throw new \InvalidArgumentException('Unknown kind: '.$kind),
        };
    }

    private static function server(string $kind): Connection
    {
        return DriverManager::getConnection(self::parameters($kind));
    }

    /** @return array<string, mixed> */
    private static function parameters(string $kind): array
    {
        $variable = self::SERVERS[$kind] ?? throw new \InvalidArgumentException('Unknown kind: '.$kind);
        $url = getenv($variable);
        if (!\is_string($url) || '' === $url) {
            Assert::markTestSkipped('Set '.$variable.' to run this test against '.$kind.'.');
        }
        $parameters = (new DsnParser())->parse($url);
        if ('mysql' === $kind) {
            $parameters['charset'] ??= 'utf8mb4';
        }

        return $parameters;
    }
}
