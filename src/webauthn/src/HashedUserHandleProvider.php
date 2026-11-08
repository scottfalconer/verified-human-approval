<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\WebAuthn;

use VerifiedHumanApproval\Core\Actor;

/** Default for new registrations. Existing passkey systems should inject their own provider. */
final class HashedUserHandleProvider implements UserHandleProvider
{
    public function forActor(Actor $actor): string
    {
        return hash('sha256', "verified-human-approval.user-handle.v1\0"
            .pack('N', \strlen($actor->tenantId)).$actor->tenantId
            .pack('N', \strlen($actor->userId)).$actor->userId, true);
    }
}
