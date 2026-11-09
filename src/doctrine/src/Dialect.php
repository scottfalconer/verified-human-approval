<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * What the stores need to know about a supported database: how it serializes writers, and how it compares text.
 *
 * SQLite has one writer at a time, so the approval transaction takes that reservation as it begins and no row
 * lock is needed. PostgreSQL, MySQL and MariaDB lock rows, so the stores lock their own rows and the application
 * locks the rows its decision reads.
 *
 * @internal
 */
final readonly class Dialect
{
    private function __construct(
        public bool $sqlite,
        public bool $postgresql,
        public bool $mysql,
        /** A binary, no-pad collation for text, so identifiers compare byte for byte; null where that is the default. */
        public ?string $collation,
    ) {
    }

    public static function of(AbstractPlatform $platform): self
    {
        return match (true) {
            $platform instanceof SQLitePlatform => new self(true, false, false, null),
            $platform instanceof PostgreSQLPlatform => new self(false, true, false, null),
            // MariaDB's binary collation that does not ignore trailing spaces.
            $platform instanceof MariaDBPlatform => new self(false, false, true, 'utf8mb4_nopad_bin'),
            // MySQL 8's: utf8mb4_bin would treat "alice" and "alice " as the same identifier.
            $platform instanceof MySQL80Platform => new self(false, false, true, 'utf8mb4_0900_bin'),
            $platform instanceof AbstractMySQLPlatform => throw new \LogicException('Verified Human Approval needs MySQL 8.0.17 or later, or MariaDB: older MySQL has no binary collation that keeps trailing spaces apart.'),
            default => throw new \LogicException(\sprintf('Verified Human Approval supports SQLite, PostgreSQL, MySQL and MariaDB, not %s.', $platform::class)),
        };
    }

    /** Whether the stores lock rows (every database but SQLite). */
    public function locksRows(): bool
    {
        return !$this->sqlite;
    }

    /** The clause that locks the selected rows for writing until commit; empty on SQLite, which holds the database's write lock. */
    public function forUpdate(): string
    {
        return $this->sqlite ? '' : 'FOR UPDATE';
    }

    /** The clause that keeps the selected rows from changing until commit; empty on SQLite, which holds the database's write lock. */
    public function forShare(): string
    {
        return match (true) {
            $this->sqlite => '',
            $this->mysql => 'LOCK IN SHARE MODE',
            default => 'FOR SHARE',
        };
    }
}
