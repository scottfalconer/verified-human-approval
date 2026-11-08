<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Security;

use ExactApproval\Core\{Actor, PreparedAction};

/** OAuth integrations recheck stored grant, audience, scope and revocation here, on every boundary. */
interface DelegationAuthorizerInterface
{
    public function assertAllowed(Actor $actor, PreparedAction $action): void;
}

