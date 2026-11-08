<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Security;

use ExactApproval\Core\{Actor, ApprovalException, PreparedAction};

final class RejectUnknownDelegation implements DelegationAuthorizerInterface
{
    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        if ($actor->delegationId !== null) {
            throw new ApprovalException('delegation_not_configured', 'This delegated authentication method needs its own current-grant authorizer.');
        }
    }
}

