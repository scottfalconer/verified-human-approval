<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\WebAuthn;

use VerifiedHumanApproval\Core\Actor;

/** Raw credential persistence used beneath signed enrollment verification. */
interface RevocableCredentialRepository extends CredentialRepository
{
    /**
     * Revoke an active credential owned by this user and tenant in the host's
     * transaction. Return false if absent, already revoked or differently owned.
     */
    public function revoke(Actor $owner, string $id): bool;
}
