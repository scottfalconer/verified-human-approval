<?php

declare(strict_types=1);

namespace ExactApproval\Symfony\Mcp;

use ExactApproval\Core\ApprovalService;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Symfony\ActorProvider;
use ExactApproval\Symfony\ApprovalPresenter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class ApprovalStatusTool
{
    public function __construct(private ApprovalService $approvals, private ActorProvider $actors, private ApprovalPresenter $presenter) {}

    #[McpTool(
        name: 'approval_status',
        description: 'Get the authoritative result of one action approval request belonging to the current user. This never executes or approves an action.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true),
    )]
    public function status(string $request_id): CallToolResult
    {
        try {
            $state = $this->approvals->get($this->actors->current(), $request_id);
            $this->actors->assertCanReadApproval($state);

            return $this->presenter->toolResult($state);
        } catch (ApprovalException | AccessDeniedException) {
            return CallToolResult::error([new TextContent('This approval result is unavailable for the current account or personal connection.')]);
        }
    }
}
