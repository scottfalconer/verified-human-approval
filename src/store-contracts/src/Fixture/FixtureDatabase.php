<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts\Fixture;

/**
 * The fixture application's database access: plain, portable SQL on the connection the approval store under test
 * uses, so the application's reads and writes share its transaction. On a database that locks rows, the fixture's
 * reads lock what the decision depends on, as an application must: the content it changes for writing, and the
 * permissions, grants and policy it reads against change.
 */
interface FixtureDatabase
{
    /**
     * @param list<mixed> $parameters
     *
     * @return array<string, mixed>|null the first row, or null
     */
    public function row(string $sql, array $parameters = []): ?array;

    /**
     * @param list<mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $parameters = []): array;

    /**
     * @param list<mixed> $parameters
     *
     * @return int the rows changed
     */
    public function execute(string $sql, array $parameters = []): int;

    /** The clause that locks selected rows for writing until commit, or '' where the transaction already holds a database-wide write lock. */
    public function forUpdate(): string;

    /** The clause that keeps selected rows from changing until commit, or ''. */
    public function forShare(): string;
}
