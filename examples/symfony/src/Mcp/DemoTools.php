<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Mcp;

use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalService;
use ExactApproval\Demo\DemoDatabase;
use ExactApproval\Symfony\ActorProvider;
use ExactApproval\Symfony\ApprovalPresenter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

final readonly class DemoTools
{
    public function __construct(private ApprovalService $approvals, private ActorProvider $actors, private ApprovalPresenter $presenter, private DemoDatabase $database) {}

    #[McpTool(
        name: 'publish_announcement',
        description: 'Request publication of one existing announcement revision as the currently authenticated user. Returns a browser approval URL when protected. It does not publish before application approval. Reuse request_key for retries, then call approval_status.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function publish(string $announcement_id, ?string $request_key = null): CallToolResult
    {
        try {
            $actor = $this->actors->current();
            // A retried request may return an existing result; check its read grant before that lookup.
            if ($actor->delegationId !== null) {
                $grant = $this->database->grant($actor->delegationId);
                if ($grant === null || !(bool) $grant['active'] || !\in_array('approval.read', json_decode($grant['scopes'], true, 8, JSON_THROW_ON_ERROR), true)) {
                    throw new ApprovalException('result_read_denied', 'This personal connection is not allowed to receive approval results.');
                }
            }
            return $this->presenter->toolResult($this->approvals->request($actor, 'announcement.publish', ['announcement_id' => $announcement_id], $request_key));
        } catch (ApprovalException $error) {
            $data = ['status' => 'rejected', 'reason' => $error->reason, 'message' => $error->getMessage()];

            return new CallToolResult([new TextContent(json_encode($data, JSON_THROW_ON_ERROR))], isError: true, structuredContent: $data);
        }
    }

    #[McpTool(name: 'read_announcement', description: 'Read one announcement in the current user’s application tenant, including draft and published revision.', annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true))]
    public function read(string $announcement_id): CallToolResult
    {
        $actor = $this->actors->current();
        if ($actor->delegationId !== null) {
            $grant = $this->database->grant($actor->delegationId);
            if ($grant === null || !(bool) $grant['active'] || !\in_array('announcement.read', json_decode($grant['scopes'], true, 8, JSON_THROW_ON_ERROR), true)) {
                return CallToolResult::error([new TextContent('Your personal connection does not allow reading announcements.')]);
            }
        }
        $row = $this->database->announcement($announcement_id);
        if ($row === null || $row['tenant_id'] !== $actor->tenantId) return CallToolResult::error([new TextContent('Announcement unavailable for this account.')]);
        unset($row['tenant_id']);

        return new CallToolResult([new TextContent(json_encode($row, JSON_THROW_ON_ERROR))], structuredContent: $row);
    }
}
