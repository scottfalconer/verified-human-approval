<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Bundle\ApprovalPresenter;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Mcp\ApprovalFlow;

final readonly class ApprovalStatusTool
{
    public const NAME = 'approval_status';

    public function __construct(private ApprovalService $approvals, private ActorProvider $actors, private ApprovalPresenter $presenter)
    {
    }

    #[McpTool(
        name: self::NAME,
        title: 'Approval status',
        description: 'Get the authoritative result of one action approval request belonging to the current user. This never executes or approves an action. Call it after a pending result, or when a retried call was refused. Once the action ran, the result carries the site’s signed receipt.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: ApprovalFlow::OUTPUT_SCHEMA,
    )]
    public function status(
        #[Schema(description: 'The requestId a protected tool returned, also the last part of its review URL.')] string $request_id,
    ): CallToolResult {
        try {
            $state = $this->approvals->get($this->actors->current(), $request_id);
            $this->actors->assertCanReadApproval($state);

            return $this->presenter->toolResult($state);
        } catch (ApprovalException $error) {
            // The person's own record, damaged, or one too damaged to show whose it is: "unavailable" would read
            // as "nothing happened".
            if ('record_integrity_error' === $error->reason) {
                return ApprovalFlow::errorResult($error, $request_id);
            }
        } catch (AccessDeniedException) {
        }

        return CallToolResult::error([new TextContent('This approval result is unavailable for the current account or personal connection.')]);
    }
}
