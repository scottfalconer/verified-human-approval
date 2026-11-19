<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\WebAuthn;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;

/** SQLite reference adapter. Supply the very same PDO connection used by the approval store. */
final readonly class PdoCredentialRepository implements RevocableCredentialRepository
{
    public function __construct(private \PDO $pdo)
    {
        // update() counts an identical write-back, as a passkey whose counter stays at zero makes, as a changed row:
        // SQLite does, and MySQL, which counts only rows whose values changed, would refuse every such passkey.
        if ('sqlite' !== $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            throw new \InvalidArgumentException('This reference repository supports SQLite only; supply another RevocableCredentialRepository for other databases, such as the Doctrine storage\'s.');
        }
    }

    public function initialize(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS verified_human_approval_credentials (
            credential_id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            tenant_id TEXT NOT NULL,
            source_json TEXT NOT NULL,
            revoked INTEGER NOT NULL DEFAULT 0,
            label TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS verified_human_approval_credentials_owner
            ON verified_human_approval_credentials (tenant_id, user_id, revoked)');
    }

    public function find(string $id): ?Credential
    {
        $statement = $this->pdo->prepare('SELECT * FROM verified_human_approval_credentials WHERE credential_id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function forActor(Actor $actor): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM verified_human_approval_credentials
            WHERE user_id = :user AND tenant_id = :tenant AND revoked = 0 ORDER BY credential_id');
        $statement->execute(['user' => $actor->userId, 'tenant' => $actor->tenantId]);

        return array_values(array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function add(Credential $credential): void
    {
        $statement = $this->pdo->prepare('INSERT INTO verified_human_approval_credentials
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
            if ('23000' === (string) $exception->getCode()) {
                throw new ApprovalException('credential_exists', 'This credential is already registered.');
            }
            throw $exception;
        }
    }

    public function update(Credential $credential, string $expectedSourceJson): void
    {
        $statement = $this->pdo->prepare('UPDATE verified_human_approval_credentials SET source_json = :source
            WHERE credential_id = :id AND user_id = :user AND tenant_id = :tenant
            AND revoked = 0 AND source_json = :expected');
        $statement->execute([
            'source' => $credential->sourceJson,
            'id' => $credential->id,
            'user' => $credential->userId,
            'tenant' => $credential->tenantId,
            'expected' => $expectedSourceJson,
        ]);
        if (1 !== $statement->rowCount()) {
            throw new ApprovalException('credential_changed', 'The approval credential changed or was revoked.');
        }
    }

    /**
     * Every passkey in a tenant, revoked ones included, for an administrator's audit view.
     *
     * @return list<Credential>
     */
    public function forTenant(string $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM verified_human_approval_credentials WHERE tenant_id = :tenant ORDER BY user_id, credential_id');
        $statement->execute(['tenant' => $tenantId]);

        return array_values(array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /**
     * Revokes one of the owner's passkeys; another account's ID matches nothing. Call only from a
     * trusted host account-management flow. Returns false if no active passkey matched.
     */
    public function revoke(Actor $owner, string $id): bool
    {
        $statement = $this->pdo->prepare('UPDATE verified_human_approval_credentials SET revoked = 1
            WHERE credential_id = :id AND user_id = :user AND tenant_id = :tenant AND revoked = 0');
        $statement->execute(['id' => $id, 'user' => $owner->userId, 'tenant' => $owner->tenantId]);

        return 1 === $statement->rowCount();
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
