<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle\Doctrine;

use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalState;

/** The fixture's person, for a kernel that serves no requests. */
final class FixedActorProvider implements ActorProvider
{
    public function current(): Actor
    {
        return new Actor('alice', 'tenant-a', 'agent-1');
    }

    public function interactive(): Actor
    {
        return new Actor('alice', 'tenant-a');
    }

    public function assertCanReadApproval(ApprovalState $state): void
    {
    }
}
