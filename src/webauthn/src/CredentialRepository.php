<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\WebAuthn;

use VerifiedHumanApproval\Core\Actor;

interface CredentialRepository
{
    /** Includes revoked records, so a verifier can fail closed. */
    public function find(string $id): ?Credential;

    /** @return list<Credential> Only active registrations belonging to this account and tenant. */
    public function forActor(Actor $actor): array;

    /** Must reject duplicate IDs; never reassign or reactivate an existing registration. */
    public function add(Credential $credential): void;

    /**
     * Compare and replace the source record only if it is unchanged and still active.
     * Must participate in the same transaction as approval consumption and the business action.
     */
    public function update(Credential $credential, string $expectedSourceJson): void;
}
