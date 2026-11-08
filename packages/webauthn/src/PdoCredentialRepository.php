<?php

declare(strict_types=1);

namespace ExactApproval\WebAuthn;

use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;

/** SQLite reference adapter. Supply the very same PDO connection used by the approval store. */
final readonly class PdoCredentialRepository implements CredentialRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function initialize(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS exact_approval_credentials (
            credential_id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            tenant_id TEXT NOT NULL,
            source_json TEXT NOT NULL,
            revoked INTEGER NOT NULL DEFAULT 0,
            label TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS exact_approval_credentials_owner
            ON exact_approval_credentials (tenant_id, user_id, revoked)');
    }

    public function find(string $id): ?Credential
    {
        $statement = $this->pdo->prepare('SELECT * FROM exact_approval_credentials WHERE credential_id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function forActor(Actor $actor): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM exact_approval_credentials
            WHERE user_id = :user AND tenant_id = :tenant AND revoked = 0 ORDER BY credential_id');
        $statement->execute(['user' => $actor->userId, 'tenant' => $actor->tenantId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function add(Credential $credential): void
    {
        $statement = $this->pdo->prepare('INSERT INTO exact_approval_credentials
            (credential_id, user_id, tenant_id, source_json, revoked, label)
            VALUES (:id, :user, :tenant, :source, :revoked, :label)');
        try {
            $statement->execute([
                'id' => $credential->id,
                'user' => $credential->userId,
                'tenant' => $credential->tenantId,
                'source' => $credential->sourceJson,
                'revoked' => (int) $credential->revoked,
                'label' => $credential->label,
            ]);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ApprovalException('credential_exists', 'This credential is already registered.');
            }
            throw $exception;
        }
    }

    public function update(Credential $credential, string $expectedSourceJson): void
    {
        $statement = $this->pdo->prepare('UPDATE exact_approval_credentials SET source_json = :source
            WHERE credential_id = :id AND user_id = :user AND tenant_id = :tenant
            AND revoked = 0 AND source_json = :expected');
        $statement->execute([
            'source' => $credential->sourceJson,
            'id' => $credential->id,
            'user' => $credential->userId,
            'tenant' => $credential->tenantId,
            'expected' => $expectedSourceJson,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new ApprovalException('credential_changed', 'The approval credential changed or was revoked.');
        }
    }

    /** Call only from a trusted host account-management flow. */
    public function revoke(string $id): void
    {
        $statement = $this->pdo->prepare('UPDATE exact_approval_credentials SET revoked = 1 WHERE credential_id = :id');
        $statement->execute(['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Credential
    {
        return new Credential(
            (string) $row['credential_id'],
            (string) $row['user_id'],
            (string) $row['tenant_id'],
            (string) $row['source_json'],
            (bool) $row['revoked'],
            (string) $row['label'],
        );
    }
}
