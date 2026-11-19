<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Mcp;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Mcp\ProposalBudget;

/** The person's budget for agent calls that ask for a protected action, kept by a Symfony rate limiter. */
final readonly class RateLimiterProposalBudget implements ProposalBudget
{
    public function __construct(private RateLimiterFactoryInterface $limiter)
    {
    }

    public function spend(Actor $person): void
    {
        // One budget per person, whichever agent connection or tool asks, so a new connection does not reset it.
        if (!$this->limiter->create('request|'.hash('sha256', $person->tenantId."\0".$person->userId))->consume()->isAccepted()) {
            throw new ApprovalException('rate_limited', 'Too many requests for approval from this account. Wait a minute, then ask again.');
        }
    }
}
