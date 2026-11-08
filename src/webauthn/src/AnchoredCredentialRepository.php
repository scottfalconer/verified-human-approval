<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\WebAuthn;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\Audit\AuditLog;

/**
 * Accepts a passkey only while a signed enrollment record in the audit log vouches for its public key.
 * The record is signed with the application's evidence key, which lives outside the database, so a
 * credential inserted or altered directly in the database can never approve anything, and an
 * administrator can re-check any past approval against the key that was actually enrolled.
 */
final readonly class AnchoredCredentialRepository implements CredentialRepository
{
    public const ENROLLED = 'passkey.enrolled';
    public const REVOKED = 'passkey.revoked';

    /** The parts of a registration that never change after enrollment; the counter does. */
    private const IDENTITY = ['publicKeyCredentialId', 'credentialPublicKey', 'userHandle'];

    public function __construct(private PdoCredentialRepository $credentials, private AuditLog $audit) {}

    public function find(string $id): ?Credential
    {
        $credential = $this->credentials->find($id);

        return $credential !== null && $this->anchored($credential) ? $credential : null;
    }

    public function forActor(Actor $actor): array
    {
        return array_values(array_filter($this->credentials->forActor($actor), $this->anchored(...)));
    }

    public function add(Credential $credential): void
    {
        $this->credentials->add($credential);
    }

    public function update(Credential $credential, string $expectedSourceJson): void
    {
        $this->credentials->update($credential, $expectedSourceJson);
    }

    /**
     * Records a newly registered passkey. Call in the same transaction as the registration.
     *
     * @param array<string, mixed> $context how the enrollment was authorized
     */
    public function anchor(Credential $credential, array $context): void
    {
        $this->audit->append($credential->tenantId, self::ENROLLED, $credential->id, [
            'userId' => $credential->userId, 'label' => $credential->label, 'sourceJson' => $credential->sourceJson,
        ] + $context);
    }

    /**
     * Revokes one of the owner's passkeys and records who did it.
     *
     * @param array<string, mixed> $context
     */
    public function revoke(Actor $owner, string $id, array $context): bool
    {
        if (!$this->credentials->revoke($owner, $id)) {
            return false;
        }
        $this->audit->append($owner->tenantId, self::REVOKED, $id, ['userId' => $owner->userId] + $context);

        return true;
    }

    /**
     * The passkey as it was enrolled, from its signed record, or null if nothing vouches for it.
     * Use it to re-verify a past approval: its public key is the one the person registered.
     */
    public function enrolled(string $id): ?Credential
    {
        $record = $this->audit->find(self::ENROLLED, $id);
        if ($record === null || !\is_string($record['data']->sourceJson ?? null) || !\is_string($record['data']->userId ?? null)) {
            return null;
        }

        return new Credential($id, $record['data']->userId, $record['tenantId'], $record['data']->sourceJson, false, (string) ($record['data']->label ?? 'Passkey'));
    }

    /** @return array<string, mixed>|null the signed enrollment entry */
    public function enrollmentRecord(string $id): ?array
    {
        return $this->audit->find(self::ENROLLED, $id);
    }

    public function anchored(Credential $credential): bool
    {
        $enrolled = $this->enrolled($credential->id);
        if ($enrolled === null || !$enrolled->belongsTo(new Actor($credential->userId, $credential->tenantId))
            || $this->audit->find(self::REVOKED, $credential->id) !== null) {
            return false;
        }
        $then = json_decode($enrolled->sourceJson, true);
        $now = json_decode($credential->sourceJson, true);
        if (!\is_array($then) || !\is_array($now)) {
            return false;
        }
        foreach (self::IDENTITY as $field) {
            if (!\is_string($then[$field] ?? null) || !\is_string($now[$field] ?? null) || !hash_equals($then[$field], $now[$field])) {
                return false;
            }
        }

        return true;
    }
}
