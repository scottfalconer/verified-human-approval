<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\RevocableCredentialRepository;

/**
 * Raw passkey records on the application's DBAL connection; wire the verifier to AnchoredCredentialRepository over it.
 *
 * Ids are case-sensitive and kept exactly, keyed by a digest of the id. An id is never overwritten, reassigned or
 * reactivated; ownership is user and tenant together. update() locks the row, compares the stored source in PHP and
 * then writes, so an identical write-back from a zero-counter passkey succeeds on MySQL too, where an UPDATE that
 * changes nothing reports no rows. Writes need a transaction: update() the approval's, add() and revoke() the
 * approval's or the host's, so that a passkey's enrollment or revocation commits with its signed audit entry.
 */
final readonly class DbalCredentialRepository implements RevocableCredentialRepository
{
    private Connection $connection;

    public function __construct(private DbalTransaction $transaction)
    {
        $this->connection = $transaction->connection();
    }

    public function find(string $id): ?Credential
    {
        return $this->row($id, false);
    }

    public function forActor(Actor $actor): array
    {
        $this->transaction->usePrimary();
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM '.ApprovalSchema::CREDENTIALS.' WHERE tenant_id = ? AND user_id = ? AND revoked = 0', [$actor->tenantId, $actor->userId]);
        $credentials = array_values(array_filter(array_map(self::hydrate(...), $rows), static fn (Credential $credential): bool => !$credential->revoked && $credential->belongsTo($actor)));
        usort($credentials, static fn (Credential $a, Credential $b): int => strcmp($a->id, $b->id));

        return $credentials;
    }

    public function add(Credential $credential): void
    {
        $this->assertTransaction();
        // A savepoint, so a refused duplicate leaves the transaction usable (PostgreSQL would abort it).
        $this->connection->beginTransaction();
        try {
            $this->connection->insert(ApprovalSchema::CREDENTIALS, [
                'credential_key' => self::key($credential->id), 'credential_id' => $credential->id,
                'user_id' => $credential->userId, 'tenant_id' => $credential->tenantId,
                'source_json' => $credential->sourceJson, 'revoked' => (int) $credential->revoked, 'label' => $credential->label,
            ]);
            $this->connection->commit();
        } catch (\Throwable $error) {
            try {
                $this->connection->rollBack();
            } catch (\Throwable) {
                // The savepoint stays open, so the enclosing transaction cannot commit; the insert's failure is reported.
            }
            if ($error instanceof UniqueConstraintViolationException) {
                throw new ApprovalException('credential_exists', 'This credential is already registered.');
            }
            throw $error;
        }
    }

    public function update(Credential $credential, string $expectedSourceJson): void
    {
        $this->transaction->assertActive();
        $current = $this->row($credential->id, true);
        if (null === $current || $current->revoked || $credential->revoked
            || $current->userId !== $credential->userId || $current->tenantId !== $credential->tenantId
            || !hash_equals($current->sourceJson, $expectedSourceJson)) {
            throw new ApprovalException('credential_changed', 'The approval credential changed or was revoked.');
        }
        // The row is locked (or, on SQLite, the database is), so this write cannot race; its count is not needed.
        $this->connection->executeStatement('UPDATE '.ApprovalSchema::CREDENTIALS.' SET source_json = ? WHERE credential_key = ? AND revoked = 0', [
            $credential->sourceJson, self::key($credential->id),
        ]);
    }

    /**
     * Revokes one of the owner's passkeys; another account's id matches nothing. Call it from a trusted host flow,
     * inside the transaction that also appends its signed entry. Returns false if no active passkey matched.
     */
    public function revoke(Actor $owner, string $id): bool
    {
        $this->assertTransaction();
        $current = $this->row($id, true);
        if (null === $current || $current->revoked || !$current->belongsTo($owner)) {
            return false;
        }

        return 1 === (int) $this->connection->executeStatement('UPDATE '.ApprovalSchema::CREDENTIALS.' SET revoked = 1 WHERE credential_key = ? AND user_id = ? AND tenant_id = ? AND revoked = 0', [
            self::key($id), $owner->userId, $owner->tenantId,
        ]);
    }

    /**
     * Every passkey in a tenant, revoked ones included, for an administrator's audit view.
     *
     * @return list<Credential>
     */
    public function forTenant(string $tenantId): array
    {
        $this->transaction->usePrimary();
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM '.ApprovalSchema::CREDENTIALS.' WHERE tenant_id = ?', [$tenantId]);
        $credentials = array_values(array_filter(array_map(self::hydrate(...), $rows), static fn (Credential $credential): bool => $credential->tenantId === $tenantId));
        usort($credentials, static fn (Credential $a, Credential $b): int => [$a->userId, $a->id] <=> [$b->userId, $b->id]);

        return $credentials;
    }

    private function row(string $id, bool $lock): ?Credential
    {
        $this->transaction->usePrimary();
        $clause = $lock && $this->connection->isTransactionActive() ? ' '.$this->transaction->forUpdate() : '';
        $row = $this->connection->fetchAssociative('SELECT * FROM '.ApprovalSchema::CREDENTIALS.' WHERE credential_key = ?'.$clause, [self::key($id)]);
        if (false === $row) {
            return null;
        }
        $credential = self::hydrate($row);

        return $credential->id === $id ? $credential : null;
    }

    private function assertTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Add and revoke passkeys inside a transaction, the approval\'s or your own, so the signed audit entry commits with the change.');
        }
    }

    private static function key(string $id): string
    {
        return hash('sha256', $id);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Credential
    {
        return new Credential((string) $row['credential_id'], (string) $row['user_id'], (string) $row['tenant_id'], (string) $row['source_json'], (bool) (int) $row['revoked'], (string) $row['label']);
    }
}
