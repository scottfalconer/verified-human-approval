<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\Doctrine\ApprovalSchema;
use VerifiedHumanApproval\Doctrine\DbalCredentialRepository;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\StoreContracts\CredentialHarness;
use VerifiedHumanApproval\Tests\Support\TestDatabase;
use VerifiedHumanApproval\WebAuthn\RevocableCredentialRepository;

/** DbalCredentialRepository on one database, writing in the approval transaction. */
abstract class DbalCredentialHarness extends CredentialHarness
{
    /** One of TestDatabase::KINDS. */
    protected const KIND = 'sqlite';

    private readonly DbalTransaction $transaction;

    final public function __construct(string $location)
    {
        $this->transaction = new DbalTransaction(TestDatabase::connect(static::KIND, $location));
    }

    public static function prepare(): string
    {
        $location = TestDatabase::create(static::KIND);
        $connection = TestDatabase::connect(static::KIND, $location);
        ApprovalSchema::install($connection);
        $connection->close();

        return $location;
    }

    public static function destroy(string $location): void
    {
        TestDatabase::drop(static::KIND, $location);
    }

    public static function connect(string $location): static
    {
        return new static($location);
    }

    public function close(): void
    {
        $this->transaction->connection()->close();
    }

    public function repository(): RevocableCredentialRepository
    {
        return new DbalCredentialRepository($this->transaction);
    }

    public function transaction(callable $work): mixed
    {
        return $this->transaction->run($work);
    }
}
