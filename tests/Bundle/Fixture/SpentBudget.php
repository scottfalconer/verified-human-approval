<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle\Fixture;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Mcp\ProposalBudget;

/** An application's own budget, with nothing left in it: every call that asks for an action is refused. */
final class SpentBudget implements ProposalBudget
{
    public const MESSAGE = 'This site takes no requests for approval today.';

    public function spend(Actor $person): void
    {
        throw new ApprovalException('rate_limited', self::MESSAGE);
    }
}
