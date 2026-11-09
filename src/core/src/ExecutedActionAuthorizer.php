<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/**
 * An authorizer that checks authority once more after the action ran, knowing what it did. The service checks
 * authority before the action, and again after it, before the commit; with this interface it calls
 * assertStillAllowed() for that second check instead of assertAllowed().
 *
 * Implement it when the check needs the action's target and the action may remove it, as a deletion does: decide
 * from the frozen proposal and the result rather than loading what is gone. It still runs inside the approval's
 * transaction, so whatever it reads is what will commit. Fail closed, as assertAllowed() does.
 */
interface ExecutedActionAuthorizer extends Authorizer
{
    /** @param array<string, mixed> $result what the action returned */
    public function assertStillAllowed(Actor $actor, PreparedAction $action, array $result): void;
}
