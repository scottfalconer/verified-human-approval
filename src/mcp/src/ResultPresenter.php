<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Mcp;

use Mcp\Schema\Result\CallToolResult;
use VerifiedHumanApproval\Core\ApprovalState;

/** How one platform shows an approval state as a tool result. */
interface ResultPresenter
{
    /** The application's own review page for this request. */
    public function reviewUrl(ApprovalState $state): string;

    /**
     * The normal result: status, review link, the card's view, and an optional message for the model.
     *
     * @param bool $earlier whether this tool call's request key named an earlier request, so nothing ran for this call
     */
    public function toolResult(ApprovalState $state, ?string $message = null, bool $earlier = false): CallToolResult;

    /** The read-only tool the model and the card call to read the recorded outcome. */
    public function statusTool(): string;
}
