<?php

declare(strict_types=1);

namespace ExactApproval\Mcp;

use ExactApproval\Core\ApprovalState;
use Mcp\Schema\Result\CallToolResult;

/** How one platform shows an approval state as a tool result. */
interface ResultPresenter
{
    /** The application's own review page for this request. */
    public function reviewUrl(ApprovalState $state): string;

    /** The normal result: status, review link, the card's view, and an optional message for the model. */
    public function toolResult(ApprovalState $state, ?string $message = null): CallToolResult;

    /** The read-only tool the model and the card call to read the recorded outcome. */
    public function statusTool(): string;
}
