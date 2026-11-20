<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Mcp;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;

/**
 * How often one person's agents may ask for a protected action, as the MCP tools specification asks a server to
 * rate limit tool calls. {@see ApprovalFlow::respond()} spends one for each call that may open a proposal. Reading
 * an outcome, through the status tool or a URL prompt's retry, spends nothing.
 */
interface ProposalBudget
{
    /**
     * @throws ApprovalException with the reason rate_limited when the person has none left
     */
    public function spend(Actor $person): void;
}
