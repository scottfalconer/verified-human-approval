<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Bundle\Handoff\ApprovalHandoff;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ApprovalFlow;

/**
 * "Approve on your phone": a one-time QR code that opens one pending approval on the person's phone.
 *
 * An ordinary tool linked to the card with app-only visibility, so conforming hosts hide it from the
 * model and let only the card call it. Declared with `#[McpTool]` rather than the bundle's
 * `#[AsMcpAppTool]` so it can carry annotations and an output schema. Add it to `registry.tools`.
 */
final readonly class ApprovalHandoffTool
{
    public const NAME = 'approval_handoff';

    public const OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'requestId' => ['type' => 'string'],
            'url' => ['type' => 'string', 'description' => 'The one-time link the code encodes. It opens only this approval’s review page.'],
            'expiresAt' => ['type' => 'integer', 'description' => 'Unix time after which the code no longer works.'],
            'expiresIn' => ['type' => 'integer', 'description' => 'Seconds until then.'],
            'modules' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[01]+$'], 'description' => 'The QR code: one row of 0 and 1 per entry, quiet zone included. The card draws it itself.'],
            'status' => ['type' => 'string', 'enum' => ['integrity_error'], 'description' => 'Sent in an error result, instead of a code, when the stored record fails its integrity check. It does not establish whether the action ran.'],
            'reason' => ['type' => 'string'],
            'message' => ['type' => 'string'],
        ],
        'required' => ['requestId'],
        // A code, or the reason there is none: a client that checks error results against this schema accepts both.
        'anyOf' => [
            ['required' => ['url', 'expiresAt', 'expiresIn', 'modules']],
            ['required' => ['status', 'reason', 'message']],
        ],
        'additionalProperties' => false,
    ];

    public function __construct(
        private ApprovalService $approvals,
        private ActorProvider $actors,
        private ApprovalHandoff $handoff,
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[McpTool(
        name: self::NAME,
        title: 'Phone approval code',
        description: 'Show a one-time QR code that opens this pending approval on the same person’s phone. It only opens the review page; approving still needs their passkey.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        // The literal form of the SDK's UiToolMeta: this tool belongs to the card, and only the card may call it.
        meta: ['ui' => ['resourceUri' => ApprovalCard::URI, 'visibility' => ['app']]],
        outputSchema: self::OUTPUT_SCHEMA,
    )]
    public function handoff(
        #[Schema(description: 'The pending approval request shown in the card.')] string $request_id,
    ): CallToolResult {
        try {
            $actor = $this->actors->current();
            $state = $this->approvals->get($actor, $request_id);
            // "App only" is a convention hosts follow, not a server boundary: any client holding the
            // person's token can call this. So it gets the same read check as approval_status, and a budget.
            $this->actors->assertCanReadApproval($state);
            if (!$this->limiter->create('handoff|'.hash('sha256', $actor->tenantId."\0".$actor->userId))->consume()->isAccepted()) {
                throw new ApprovalException('rate_limited', 'Too many phone codes. Wait a minute, then show a new code.');
            }
            if ('pending' !== $state->status) {
                throw new ApprovalException($state->status, 'This request is no longer waiting for approval.');
            }
            if (!$this->handoff->reachable()) {
                throw new ApprovalException('phone_unreachable', 'A phone needs the site on a public HTTPS address. Approve here instead.');
            }
            $link = $this->handoff->issue($actor, $state);
            $data = ['requestId' => $request_id, 'url' => $link['url'], 'expiresAt' => $link['expiresAt'], 'expiresIn' => $link['expiresIn'], 'modules' => ApprovalHandoff::modules($link['url'])];

            return new CallToolResult([new TextContent('Scan this code with your phone to approve there. It works once and expires in two minutes.')], structuredContent: $data);
        } catch (ApprovalException $error) {
            // A damaged record gets no code, and the card is told why in a form it can read.
            if ('record_integrity_error' === $error->reason) {
                return ApprovalFlow::errorResult($error, $request_id);
            }

            return CallToolResult::error([new TextContent($error->getMessage())]);
        } catch (AccessDeniedException) {
            return CallToolResult::error([new TextContent('This connection is not allowed to read this approval.')]);
        }
    }
}
