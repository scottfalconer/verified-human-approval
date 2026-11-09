<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts;

use VerifiedHumanApproval\WebAuthn\RevocableCredentialRepository;

/**
 * One raw passkey store under the shared credential contract (CredentialRepositoryContract): the repository, and
 * the transaction it writes in, the approval's where the store has one. Every connection to one location shares
 * the same passkeys.
 */
abstract class CredentialHarness
{
    abstract public static function prepare(): string;

    abstract public static function destroy(string $location): void;

    abstract public static function connect(string $location): static;

    abstract public function repository(): RevocableCredentialRepository;

    /**
     * Runs $work in a transaction on the repository's connection and commits it; rolls back if it throws.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    abstract public function transaction(callable $work): mixed;
}
