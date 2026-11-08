<?php

declare(strict_types=1);

namespace ExactApproval\Symfony;

use ExactApproval\Core\Actor;

/**
 * Optional: names who made a request, for the approval sheet, so the person can tell an agent's
 * request from their own. Implement it to name the agent connection, such as "Claude (claude.ai),
 * an agent connected to your account"; return null for a request made on the site itself.
 */
interface RequesterLabel
{
    public function describe(Actor $requester): ?string;
}
