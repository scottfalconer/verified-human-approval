<?php

declare(strict_types=1);

namespace ExactApproval\Core;

interface Authorizer
{
    /** Re-read current permissions and the stored delegation's current scope. Fail closed. */
    public function assertAllowed(Actor $actor, PreparedAction $action): void;
}
