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
 *
 * Revocation is recorded in the same log, and the whole chain is read to show it, never the store's lookup: a
 * passkey approves nothing once any entry or row names its revocation, intact or damaged, nor while an entry
 * since its enrollment is one the log refuses, because its signature, key, window or link fails or it comes
 * after the rotation away from its key. So no edit to a revocation's row, no deletion of it and no rewrite of
 * it, even one signed with a retired key, restores the passkey. Two edits are the exception: the newest entries
 * cut off, and, by someone holding a retired key's private half, the newest entries replaced with entries signed
 * with that key, reaching back to the rotation away from it once that is recorded. Only a checkpoint of the log's
 * head kept where the application cannot write (AuditLog::checkpoint()) shows that a revocation among them is gone.
 *
 * An entry the log refuses stops every passkey enrolled before it until its owner enrolls again, even one that a
 * host or worker wrote while still signing with a retired key: from the chain alone, it could be a rewritten
 * revocation.
 *
 * It wraps any RevocableCredentialRepository, so a host can keep passkeys in its own storage. Wire the
 * verifier's CredentialRepository to this wrapper and give the wrapper the raw adapter, never itself.
 */
final readonly class AnchoredCredentialRepository implements CredentialRepository
{
    public const ENROLLED = 'passkey.enrolled';
    public const REVOKED = 'passkey.revoked';

    /** The parts of a registration that never change after enrollment; the counter does. */
    private const IDENTITY = ['publicKeyCredentialId', 'credentialPublicKey', 'userHandle'];

    public function __construct(private RevocableCredentialRepository $credentials, private AuditLog $audit)
    {
    }

    public function find(string $id): ?Credential
    {
        $credential = $this->credentials->find($id);

        return null !== $credential && $this->anchored($credential) ? $credential : null;
    }

    public function forActor(Actor $actor): array
    {
        return $this->vouchedFor($this->credentials->forActor($actor));
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
     * Revokes one of the owner's passkeys and records who did it. Call it inside the host's transaction:
     * the revocation and its signed entry must commit together, and a failure must undo both.
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

        return null === $record ? null : self::credential($id, $record);
    }

    /** @return array<string, mixed>|null the signed enrollment entry */
    public function enrollmentRecord(string $id): ?array
    {
        return $this->audit->find(self::ENROLLED, $id);
    }

    /**
     * The owner's stored passkeys that no signed enrollment vouches for now, so they cannot approve: for example,
     * passkeys enrolled under a signing key whose window has closed before the rotation away from it was recorded
     * (AuditLog::unvouchedKeys() lists such keys), a record that was damaged, or a log that holds an entry since
     * the enrollment that is damaged or refused (AuditLog::verify() says which).
     *
     * @return list<Credential>
     */
    public function unvouched(Actor $actor): array
    {
        return $this->weighed($actor)['unvouched'];
    }

    /**
     * The owner's stored passkeys, split into those forActor() returns and those unvouched() does, both from one
     * reading of the chain.
     *
     * @return array{vouched: list<Credential>, unvouched: list<Credential>}
     */
    public function weighed(Actor $actor): array
    {
        $credentials = $this->credentials->forActor($actor);
        $vouched = $this->vouchedFor($credentials);

        return ['vouched' => $vouched, 'unvouched' => array_values(array_filter($credentials, static fn (Credential $credential): bool => !\in_array($credential, $vouched, true)))];
    }

    public function anchored(Credential $credential): bool
    {
        return [] !== $this->vouchedFor([$credential]);
    }

    /**
     * The credentials, in their order, that a signed enrollment vouches for and no revocation has undone, all of
     * them weighed from one reading of the chain.
     *
     * @param list<Credential> $credentials
     *
     * @return list<Credential>
     */
    private function vouchedFor(array $credentials): array
    {
        // A revocation only takes trust away, so any trace of one counts; and as one could be hidden, every entry
        // recorded since an enrollment must be one the log accepts.
        $standing = $this->audit->standing(self::ENROLLED, array_map(static fn (Credential $credential): string => $credential->id, $credentials), self::REVOKED);

        return array_values(array_filter($credentials, static function (Credential $credential) use ($standing): bool {
            $enrolled = isset($standing[$credential->id]) ? self::credential($credential->id, $standing[$credential->id]) : null;

            return null !== $enrolled && $enrolled->belongsTo(new Actor($credential->userId, $credential->tenantId)) && self::sameIdentity($enrolled, $credential);
        }));
    }

    /** Whether the stored passkey is still the one enrolled: only its counter may have moved since. */
    private static function sameIdentity(Credential $enrolled, Credential $stored): bool
    {
        $then = json_decode($enrolled->sourceJson, true);
        $now = json_decode($stored->sourceJson, true);
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

    /**
     * The passkey a signed enrollment entry describes, or null if the entry lacks what one needs.
     *
     * @param array<string, mixed> $record
     */
    private static function credential(string $id, array $record): ?Credential
    {
        $data = $record['data'] ?? null;
        if (!$data instanceof \stdClass || !\is_string($data->sourceJson ?? null) || !\is_string($data->userId ?? null) || !\is_string($record['tenantId'] ?? null)) {
            return null;
        }

        return new Credential($id, $data->userId, $record['tenantId'], $data->sourceJson, false, (string) ($data->label ?? 'Passkey'));
    }
}
