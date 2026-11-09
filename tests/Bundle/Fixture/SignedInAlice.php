<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle\Fixture;

use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalState;

/** The review kernel's person: alice, signed in to tenant-a, with read access to her own requests. */
final class SignedInAlice implements ActorProvider
{
    public function current(): Actor
    {
        return new Actor('alice', 'tenant-a');
    }

    public function interactive(): Actor
    {
        return new Actor('alice', 'tenant-a');
    }

    public function assertCanReadApproval(ApprovalState $state): void
    {
    }
}
