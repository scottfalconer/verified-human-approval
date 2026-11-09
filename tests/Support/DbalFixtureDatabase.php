<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use Doctrine\DBAL\Connection;
use VerifiedHumanApproval\Doctrine\DbalTransaction;

/**
 * The fixture application on a DBAL connection, the way an application on Doctrine reads and writes: through the
 * connection the approval transaction owns, with its lock clauses.
 */
final readonly class DbalFixtureDatabase implements FixtureDatabase
{
    private Connection $connection;

    public function __construct(private DbalTransaction $transaction)
    {
        $this->connection = $transaction->connection();
    }

    public function row(string $sql, array $parameters = []): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $parameters);

        return false === $row ? null : $row;
    }

    public function rows(string $sql, array $parameters = []): array
    {
        return $this->connection->fetchAllAssociative($sql, $parameters);
    }

    public function execute(string $sql, array $parameters = []): int
    {
        return (int) $this->connection->executeStatement($sql, $parameters);
    }

    public function forUpdate(): string
    {
        return $this->transaction->forUpdate();
    }

    public function forShare(): string
    {
        return $this->transaction->forShare();
    }
}
