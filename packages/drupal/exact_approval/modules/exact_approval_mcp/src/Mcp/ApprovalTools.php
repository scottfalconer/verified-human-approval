<?php

declare(strict_types=1);

namespace Drupal\exact_approval_mcp\Mcp;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\exact_approval\Security\ActorProvider;
use ExactApproval\Core\{ApprovalException, ApprovalService, ApprovalState};
use ExactApproval\Mcp\{ApprovalCard, ApprovalFlow, ResultPresenter, UrlPrompt};
use Mcp\Capability\Attribute\{McpResource, McpTool, Schema};
use Mcp\Schema\Content\{TextContent, TextResourceContents};
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Result\{CallToolResult, InputRequiredResult};
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;

/**
 * Exact publish/delete approval tools on Drupal's existing MCP endpoint.
 *
 * A request never changes content. It returns the review link, the MCP App approval card's view
 * and, for clients the site lists, a URL-mode elicitation. The person approves on the site with
 * their registered key, and that approval executes the stored action. MCP and the Drupal UI share
 * the same approval service; there is no alternate executor.
 */
final class ApprovalTools implements ResultPresenter
{
    private readonly ApprovalFlow $flow;

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ActorProvider $actors,
        private readonly ConfigFactoryInterface $config,
        UrlPrompt $prompt,
    ) {
        $this->flow = new ApprovalFlow($approvals, $this, $prompt);
    }

    public static function urlPrompt(ConfigFactoryInterface $config): UrlPrompt
    {
        $settings = (array) $config->get('exact_approval_mcp.settings')->get('url_elicitation');

        return new UrlPrompt((bool) ($settings['enabled'] ?? false), array_values((array) ($settings['clients'] ?? [])));
    }

    #[McpTool(
        name: 'exact_approval_publish',
        title: 'Request exact publication approval',
        description: 'Prepare publication of a saved Drupal node revision. Returns a browser review URL; content changes only after the same user approves the exact frozen action with a passkey. Respects native permissions and moderation. It cannot edit content or approve on the human’s behalf.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        meta: ApprovalCard::TOOL_META,
    )]
    public function publish(
        RequestContext $context,
        #[Schema(minimum: 1)] int $node_id,
        #[Schema(minimum: 1, description: 'Optional saved revision; defaults to the latest revision.')] ?int $revision_id = null,
        #[Schema(description: 'Exact publishing transition when the workflow has more than one valid choice.')] ?string $transition_id = null,
        #[Schema(minLength: 1, maxLength: 128, description: 'Reuse for retries of this same request only.')] ?string $idempotency_key = null,
    ): CallToolResult|InputRequiredResult {
        $input = array_filter(['node_id' => $node_id, 'revision_id' => $revision_id, 'transition_id' => $transition_id], static fn (mixed $value): bool => $value !== null);

        return $this->protect($context, 'exact_approval_publish', 'node.publish', $input, $idempotency_key);
    }

    #[McpTool(
        name: 'exact_approval_delete',
        title: 'Request exact deletion approval',
        description: 'Prepare deletion of one entire saved Drupal node, all its revisions and translations. Nothing is deleted by this request. Returns a review URL for the same user to approve with a passkey; that approval executes the frozen deletion. Evidence survives the content deletion.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        meta: ApprovalCard::TOOL_META,
    )]
    public function delete(
        RequestContext $context,
        #[Schema(minimum: 1)] int $node_id,
        #[Schema(minLength: 1, maxLength: 128, description: 'Reuse for retries of this same request only.')] ?string $idempotency_key = null,
    ): CallToolResult|InputRequiredResult {
        return $this->protect($context, 'exact_approval_delete', 'node.delete', ['node_id' => $node_id], $idempotency_key);
    }

    #[McpTool(
        name: 'exact_approval_status',
        title: 'Read my approval outcome',
        description: 'Return the stored status and result of this user’s approval request. This cannot approve or execute a content action and cannot read another user’s requests.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function status(string $request_id): CallToolResult
    {
        try {
            return $this->toolResult($this->approvals->get($this->actors->current(), $request_id));
        } catch (ApprovalException $error) {
            return $this->rejected($error);
        }
    }

    #[McpResource(uri: ApprovalCard::URI, name: 'exact-approval-card', title: 'Approval', description: 'The approval card shown with exact_approval_publish and exact_approval_delete.', mimeType: McpApps::MIME_TYPE)]
    public function card(): TextResourceContents
    {
        return ApprovalCard::resource();
    }

    public function statusTool(): string
    {
        return 'exact_approval_status';
    }

    public function reviewUrl(ApprovalState $state): string
    {
        $path = Url::fromRoute('exact_approval.review', ['id' => $state->request->id])->toString();

        return rtrim((string) $this->config->get('exact_approval.settings')->get('origin'), '/').$path;
    }

    public function toolResult(ApprovalState $state, ?string $message = null): CallToolResult
    {
        $data = $state->toArray();
        $data['reviewUrl'] = $this->reviewUrl($state);
        $data['message'] = $message ?? ($state->status === 'pending'
            ? 'No content has changed. The initiating user must review this exact action in their Drupal browser session and approve with their registered key. Successful approval executes the stored action immediately.'
            : 'This is the stored outcome. Do not repeat the content operation to retrieve its result.');
        $data['approval'] = ApprovalCard::view($state, $data['reviewUrl'], $this->statusTool());
        $error = \in_array($state->status, ['denied', 'invalidated', 'expired', 'cancelled'], true);

        return new CallToolResult([new TextContent(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))], $error, $data);
    }

    /** @param array<string, int|string> $input */
    private function protect(RequestContext $context, string $tool, string $action, array $input, ?string $key): CallToolResult|InputRequiredResult
    {
        try {
            $actor = $this->actors->current();

            return $this->flow->respond(
                $context, $actor, $tool, $input + ($key === null ? [] : ['idempotency_key' => $key]),
                fn (): ApprovalState => $this->approvals->request($actor, $action, $input, $key),
            );
        } catch (ApprovalException $error) {
            return $this->rejected($error);
        }
    }

    private function rejected(ApprovalException $error): CallToolResult
    {
        $data = ['status' => 'rejected', 'reason' => $error->reason, 'message' => $error->getMessage()];

        return new CallToolResult([new TextContent(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))], true, $data);
    }
}
