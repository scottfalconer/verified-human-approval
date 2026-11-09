<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts\Fixture;

/** The fixture application on a PDO connection. Lock clauses default to none, as SQLite needs. */
final readonly class PdoFixtureDatabase implements FixtureDatabase
{
    public function __construct(private \PDO $pdo, private string $forUpdate = '', private string $forShare = '')
    {
    }

    public function row(string $sql, array $parameters = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? $row : null;
    }

    public function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    public function forUpdate(): string
    {
        return $this->forUpdate;
    }

    public function forShare(): string
    {
        return $this->forShare;
    }
}
