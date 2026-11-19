<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts;

use VerifiedHumanApproval\Core\Audit\AuditStore;

/**
 * One audit store under the shared audit contract (AuditStoreContract): the store over a connection of its own,
 * the host's transaction on that connection, and plain SQL to inspect or damage the rows as an attacker with
 * database access could. Every connection to one location shares the same rows.
 *
 * @phpstan-import-type AuditRecord from AuditStore
 */
abstract class AuditHarness
{
    public const TABLE = 'verified_human_approval_audit';

    /** Creates a new, isolated store with its tables and a business table the host writes, and returns where it is. */
    abstract public static function prepare(): string;

    abstract public static function destroy(string $location): void;

    /** A new connection to prepared data; it runs no DDL, so concurrent workers can use it. */
    abstract public static function connect(string $location): static;

    /**
     * Closes the connection connect() opened. The contract closes every harness a test connected when the test ends,
     * before destroy(), rather than wait for PHP to free it, which for a harness caught in a reference cycle happens
     * only when PHP next collects cycles. The default does nothing, for a store with no connection to close.
     */
    public function close(): void
    {
    }

    abstract public function store(): AuditStore;

    /**
     * Begins the host's own transaction on the store's connection, the way the host's code begins one: with the
     * database API the host uses, in whatever mode that API begins it. PDO and Doctrine DBAL begin a SQLite
     * transaction deferred; Drupal 11.4 begins one IMMEDIATE, reserving the writer at once.
     */
    abstract public function begin(): void;

    abstract public function commit(): void;

    abstract public function rollBack(): void;

    abstract public function inTransaction(): bool;

    /** The host's own write in its transaction, standing for the change the entry records. */
    abstract public function writeBusinessRow(): void;

    /** The business rows, read on a connection of its own. */
    abstract public function businessRows(): int;

    /** Reads the log on the store's connection, so a transaction the host has open holds a view of it. */
    abstract public function read(): void;

    /** Waits at most this long for a lock before failing. */
    abstract public function lockTimeout(int $milliseconds): void;

    /** Whether an error is a lock wait that ran out. */
    abstract public function isLockTimeout(\Throwable $error): bool;

    /**
     * Whether the host's open transaction, once it has read the log, keeps another connection's append from
     * committing until it ends: on SQLite's rollback journal, where the read holds a shared lock, or wherever
     * begin() reserves the writer, as an IMMEDIATE SQLite transaction does in either journal. The host can then
     * never hold an older view of the log than the newest committed entry, and the test of an append from an older
     * view is skipped.
     */
    abstract public function readersBlockWriters(): bool;

    /**
     * Whether an append in a transaction that read the log before another connection appended fails (SQLite cannot
     * turn an older snapshot into a writer), rather than follow the newest row read under a lock.
     */
    abstract public function appendsFromAnOlderViewFail(): bool;

    /** Makes every insert into the log fail inside the storage itself, until cleared. */
    abstract public function failInserts(): void;

    abstract public function clearInsertFailures(): void;

    /**
     * Inserts a row exactly as given, outside the store, as rows written by an older version were.
     *
     * @param AuditRecord $row
     */
    abstract public function insertRaw(array $row): void;

    /** Changes one stored row with an SQL assignment, such as "type = 'x'", as someone with database access could. */
    abstract public function damage(int $seq, string $assignment): void;

    /** @return string one stored column, read directly */
    abstract public function column(int $seq, string $column): string;
}
