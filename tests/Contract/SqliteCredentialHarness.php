<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\StoreContracts\CredentialHarness;
use VerifiedHumanApproval\WebAuthn\PdoCredentialRepository;
use VerifiedHumanApproval\WebAuthn\RevocableCredentialRepository;

/** The SQLite reference passkey repository, PdoCredentialRepository. The location is a database file. */
final class SqliteCredentialHarness extends CredentialHarness
{
    private readonly \PDO $pdo;

    public function __construct(string $location)
    {
        $this->pdo = new \PDO('sqlite:'.$location, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA busy_timeout = 15000');
    }

    public static function prepare(): string
    {
        $location = sys_get_temp_dir().'/verified-human-approval-credentials-'.bin2hex(random_bytes(8)).'.sqlite';
        (new PdoCredentialRepository(new \PDO('sqlite:'.$location, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION])))->initialize();

        return $location;
    }

    public static function destroy(string $location): void
    {
        foreach ([$location, $location.'-journal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public static function connect(string $location): static
    {
        return new static($location);
    }

    public function repository(): RevocableCredentialRepository
    {
        return new PdoCredentialRepository($this->pdo);
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }
}
