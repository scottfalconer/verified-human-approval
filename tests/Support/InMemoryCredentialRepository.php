<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\WebAuthn\Credential;
use VerifiedHumanApproval\WebAuthn\RevocableCredentialRepository;

/** Passkey storage with no database, standing in for a host's own, to show the anchored wrapper needs no PDO. */
final class InMemoryCredentialRepository implements RevocableCredentialRepository
{
    /** @var array<string, Credential> */
    public array $credentials = [];

    public function find(string $id): ?Credential
    {
        return $this->credentials[$id] ?? null;
    }

    public function forActor(Actor $actor): array
    {
        return array_values(array_filter($this->credentials, static fn (Credential $credential): bool => !$credential->revoked && $credential->belongsTo($actor)));
    }

    public function add(Credential $credential): void
    {
        if (isset($this->credentials[$credential->id])) {
            throw new ApprovalException('credential_exists', 'This credential is already registered.');
        }
        $this->credentials[$credential->id] = $credential;
    }

    public function update(Credential $credential, string $expectedSourceJson): void
    {
        $current = $this->credentials[$credential->id] ?? null;
        // What counts is matching the expected active row, not whether anything changed: a zero-counter
        // passkey writes back the very same source.
        if (null === $current || $current->revoked || !$current->belongsTo(new Actor($credential->userId, $credential->tenantId))
            || !hash_equals($current->sourceJson, $expectedSourceJson)) {
            throw new ApprovalException('credential_changed', 'The approval credential changed or was revoked.');
        }
        $this->credentials[$credential->id] = new Credential($current->id, $current->userId, $current->tenantId, $credential->sourceJson, false, $current->label);
    }

    public function revoke(Actor $owner, string $id): bool
    {
        $current = $this->credentials[$id] ?? null;
        if (null === $current || $current->revoked || !$current->belongsTo($owner)) {
            return false;
        }
        $this->credentials[$id] = new Credential($current->id, $current->userId, $current->tenantId, $current->sourceJson, true, $current->label);

        return true;
    }
}
