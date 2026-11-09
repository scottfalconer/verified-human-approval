<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\RequestContext;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ApprovalFlow;

final readonly class DemoTools
{
    private const ALERT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string'],
            'revision' => ['type' => 'integer', 'description' => 'The draft’s revision. An approval binds to the revision the person reviewed.'],
            'title' => ['type' => 'string', 'description' => 'The draft title.'],
            'body' => ['type' => 'string', 'description' => 'The draft body.'],
            'published_revision' => ['type' => ['integer', 'null'], 'description' => 'The revision residents see, or null when no alert is live.'],
            'published_title' => ['type' => ['string', 'null']],
            'published_body' => ['type' => ['string', 'null']],
            'mutation_count' => ['type' => 'integer', 'description' => 'How many times the live alert has changed.'],
        ],
        'required' => ['id', 'revision', 'title', 'body', 'published_revision', 'published_title', 'published_body', 'mutation_count'],
        'additionalProperties' => false,
    ];

    public function __construct(private ApprovalService $approvals, private ActorProvider $actors, private DemoDatabase $database, private ApprovalFlow $flow)
    {
    }

    #[McpTool(
        name: 'publish_alert',
        title: 'Issue a public alert',
        description: 'Ask to issue a draft public alert, such as an evacuation order, as the signed-in person. The administrator decides per role whether it is issued directly or only after that person approves the exact alert on the site with their passkey; then this returns a review URL and nothing is live yet. Reuse request_key for retries, then call approval_status. If a retried call is refused, call approval_status with the request id from the prompt or the review URL instead of asking again.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        meta: ApprovalCard::TOOL_META,
        outputSchema: ApprovalFlow::OUTPUT_SCHEMA,
    )]
    public function publish(
        RequestContext $context,
        #[Schema(description: 'The alert’s id, as read_alert reports it.')] string $alert_id,
        #[Schema(description: 'Your own id for this request. Reuse it to retry safely: the same key returns the same request instead of asking again.')] ?string $request_key = null,
    ): CallToolResult|InputRequiredResult {
        try {
            $actor = $this->actors->current();
            // A retried request may return an existing result, so the connection needs the read scope too.
            if (!self::token($context)->hasScope('approval.read')) {
                throw new ApprovalException('result_read_denied', 'This personal connection is not allowed to receive approval results.');
            }

            return $this->flow->respond(
                $context, $actor, 'publish_alert', ['alert_id' => $alert_id, 'request_key' => $request_key],
                fn (): ApprovalState => $this->approvals->request($actor, 'alert.publish', ['alert_id' => $alert_id], $request_key),
            );
        } catch (ApprovalException $error) {
            $data = ['status' => 'record_integrity_error' === $error->reason ? 'integrity_error' : 'rejected', 'reason' => $error->reason, 'message' => $error->getMessage()];

            return new CallToolResult([new TextContent(json_encode($data, \JSON_THROW_ON_ERROR))], isError: true, structuredContent: $data);
        }
    }

    #[McpTool(
        name: 'read_alert',
        title: 'Read an alert',
        description: 'Read one public alert in the current user’s organization, including its draft and the live version.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: self::ALERT_SCHEMA,
    )]
    public function read(
        RequestContext $context,
        #[Schema(description: 'The alert’s id. The draft evacuation alert is riverside-evacuation.')] string $alert_id,
    ): CallToolResult {
        try {
            $actor = $this->actors->current();
            if (!self::token($context)->hasScope('alert.read')) {
                return CallToolResult::error([new TextContent('Your personal connection does not allow reading alerts.')]);
            }
        } catch (ApprovalException $error) {
            return CallToolResult::error([new TextContent($error->getMessage())]);
        }
        $row = $this->database->alert($alert_id);
        if (null === $row || $row['tenant_id'] !== $actor->tenantId) {
            return CallToolResult::error([new TextContent('This alert is unavailable for this account.')]);
        }
        $alert = [
            'id' => (string) $row['id'], 'revision' => (int) $row['revision'], 'title' => (string) $row['title'], 'body' => (string) $row['body'],
            'published_revision' => null === $row['published_revision'] ? null : (int) $row['published_revision'],
            'published_title' => $row['published_title'], 'published_body' => $row['published_body'], 'mutation_count' => (int) $row['mutation_count'],
        ];

        return new CallToolResult([new TextContent(json_encode($alert, \JSON_THROW_ON_ERROR))], structuredContent: $alert);
    }

    /**
     * The connection's validated token, which the /mcp firewall hands the SDK on every request. Its
     * scopes decide what the connection may do; the authorizer rechecks the stored grant at execution.
     */
    private static function token(RequestContext $context): AccessToken
    {
        return $context->getAccessToken() ?? throw new ApprovalException('unauthenticated', 'This tool needs a personal connection token.');
    }
}
