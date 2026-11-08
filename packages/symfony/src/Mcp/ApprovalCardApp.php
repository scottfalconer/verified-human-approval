<?php

declare(strict_types=1);

namespace ExactApproval\Symfony\Mcp;

use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalService;
use ExactApproval\Mcp\ApprovalCard;
use ExactApproval\Symfony\ActorProvider;
use ExactApproval\Symfony\Handoff\ApprovalHandoff;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\McpBundle\Attribute\AsMcpApp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\AI\McpBundle\Attribute\AsMcpAppTool;

/**
 * Serves the shared approval card as an MCP App resource and enables the MCP Apps extension.
 * A protected tool links to it with `meta: ApprovalCard::TOOL_META`. Add this class to the
 * server's `registry.apps` list.
 */
#[AsMcpApp(uri: ApprovalCard::URI, title: 'Approval')]
final readonly class ApprovalCardApp
{
    public const HANDOFF_TOOL = 'approval_handoff';

    public function __construct(
        private ApprovalService $approvals,
        private ActorProvider $actors,
        private ApprovalHandoff $handoff,
        private RateLimiterFactoryInterface $limiter,
    ) {}

    public function __invoke(): TextResourceContents
    {
        return ApprovalCard::resource();
    }

    /** Only the card calls this: a one-time QR code that opens this approval on the person's phone. */
    #[AsMcpAppTool(name: self::HANDOFF_TOOL, description: 'Show a one-time QR code that opens this pending approval on the same person’s phone. It only opens the review page; approving still needs their passkey.', appOnly: true)]
    public function handoff(string $request_id): CallToolResult
    {
        try {
            $actor = $this->actors->current();
            $state = $this->approvals->get($actor, $request_id);
            // "App only" is a convention hosts follow, not a server boundary: any client holding the
            // person's token can call this. So it gets the same read check as approval_status, and a budget.
            $this->actors->assertCanReadApproval($state);
            if (!$this->limiter->create('handoff|'.hash('sha256', $actor->tenantId."\0".$actor->userId))->consume()->isAccepted()) {
                throw new ApprovalException('rate_limited', 'Too many phone codes. Wait a minute, then show a new code.');
            }
            if ($state->status !== 'pending') {
                throw new ApprovalException($state->status, 'This request is no longer waiting for approval.');
            }
            if (!$this->handoff->reachable()) {
                throw new ApprovalException('phone_unreachable', 'A phone needs the site on a public HTTPS address. Approve here instead.');
            }
            $link = $this->handoff->issue($actor, $state);
            $data = ['requestId' => $request_id, 'url' => $link['url'], 'expiresAt' => $link['expiresAt'], 'expiresIn' => $link['expiresIn'], 'modules' => ApprovalHandoff::modules($link['url'])];

            return new CallToolResult([new TextContent('Scan this code with your phone to approve there. It works once and expires in two minutes.')], structuredContent: $data);
        } catch (ApprovalException $error) {
            return CallToolResult::error([new TextContent($error->getMessage())]);
        } catch (AccessDeniedException) {
            return CallToolResult::error([new TextContent('This connection is not allowed to read this approval.')]);
        }
    }
}
