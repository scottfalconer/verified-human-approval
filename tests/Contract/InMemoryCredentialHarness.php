<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\Tests\Support\InMemoryCredentialRepository;
use VerifiedHumanApproval\WebAuthn\RevocableCredentialRepository;

/** The in-memory stand-in for a host's own passkey storage. One location is one repository, for this process only. */
final class InMemoryCredentialHarness extends CredentialHarness
{
    /** @var array<string, InMemoryCredentialRepository> */
    private static array $repositories = [];

    public function __construct(private readonly InMemoryCredentialRepository $repository)
    {
    }

    public static function prepare(): string
    {
        $location = bin2hex(random_bytes(8));
        self::$repositories[$location] = new InMemoryCredentialRepository();

        return $location;
    }

    public static function destroy(string $location): void
    {
        unset(self::$repositories[$location]);
    }

    public static function connect(string $location): static
    {
        return new static(self::$repositories[$location]);
    }

    public function repository(): RevocableCredentialRepository
    {
        return $this->repository;
    }

    public function transaction(callable $work): mixed
    {
        return $work();
    }
}
