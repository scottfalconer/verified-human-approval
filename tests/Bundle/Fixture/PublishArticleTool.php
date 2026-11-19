<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Bundle\Fixture;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ApprovalFlow;

/** The fixture application's protected tool, written as the guide writes one: alice's agent asks to publish an article. */
final readonly class PublishArticleTool
{
    public function __construct(private ApprovalService $approvals, private ApprovalFlow $flow)
    {
    }

    #[McpTool(
        name: 'publish_article',
        title: 'Publish an article',
        description: 'Ask to publish an article. Nothing is published until alice approves it on the site with her passkey.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        meta: ApprovalCard::TOOL_META,
        outputSchema: ApprovalFlow::OUTPUT_SCHEMA,
    )]
    public function publish(
        RequestContext $context,
        #[Schema(description: 'The article.')] int $article_id,
        #[Schema(description: ApprovalFlow::REQUEST_KEY_DESCRIPTION)] ?string $request_key = null,
    ): CallToolResult|InputRequiredResult {
        // Alice's agent connection, as the host's validated token would name it.
        $actor = new Actor('alice', 'tenant-a', 'agent-1');
        try {
            return $this->flow->respond($context, $actor, 'publish_article', ['article_id' => $article_id, 'request_key' => $request_key],
                fn (): ApprovalState => $this->approvals->request($actor, 'article.publish', ['id' => $article_id], $request_key));
        } catch (ApprovalException $error) {
            return ApprovalFlow::errorResult($error);
        }
    }
}
