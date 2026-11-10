<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Approval\CurrentAuthority;
use App\Entity\Page;
use App\Workflow\Drafts;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ApprovalFlow;

final readonly class PageTools
{
    public function __construct(private ActorProvider $actors, private CurrentAuthority $authority, private Drafts $drafts,
        private EntityManagerInterface $entities, private ApprovalService $approvals, private ApprovalFlow $flow)
    {
    }

    #[McpTool(name: 'list_pages', description: 'Read working drafts and current published snapshots. Draft content is never public.', annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, openWorldHint: false))]
    public function list(RequestContext $context): CallToolResult
    {
        try {
            $this->scope($context, 'pages.read');
            $this->authority->assertReader($this->actors->current());

            return self::result(['pages' => array_map(static fn (Page $p): array => $p->toArray(), $this->entities->getRepository(Page::class)->findBy([], ['draftTitle' => 'ASC']))]);
        } catch (ApprovalException $error) {
            return ApprovalFlow::errorResult($error);
        }
    }

    #[McpTool(name: 'save_draft', description: 'Save a new or existing working draft, including while its page is live. This leaves the public snapshot unchanged and needs no passkey.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: false))]
    public function save(RequestContext $context, string $title, string $body, ?string $page_id = null): CallToolResult
    {
        try {
            $this->scope($context, 'pages.write');
            $actor = $this->actors->current();
            $page = null === $page_id ? $this->drafts->create($actor, $title, $body) : $this->drafts->save($actor, $page_id, $title, $body);

            return self::result($page->toArray());
        } catch (ApprovalException $error) {
            return ApprovalFlow::errorResult($error);
        }
    }

    #[McpTool(name: 'publish_page', description: 'Request publication of the exact saved working draft. Editors must approve the review_url with their passkey. Pending means the public version has not changed.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: false), meta: ApprovalCard::TOOL_META, outputSchema: ApprovalFlow::OUTPUT_SCHEMA)]
    public function publish(RequestContext $context, #[Schema(description: 'Page ID from list_pages or save_draft.')] string $page_id, ?string $request_key = null): CallToolResult|InputRequiredResult
    {
        return $this->ask($context, 'publish_page', 'page.publish', ['page_id' => $page_id], $request_key);
    }

    #[McpTool(name: 'edit_live_page', description: 'Request an exact direct edit to published content. The saved working draft remains separate. Show review_url while pending; read approval_status after approval.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: false), meta: ApprovalCard::TOOL_META, outputSchema: ApprovalFlow::OUTPUT_SCHEMA)]
    public function edit(RequestContext $context, string $page_id, string $title, string $body, ?string $request_key = null): CallToolResult|InputRequiredResult
    {
        return $this->ask($context, 'edit_live_page', 'page.edit_live', compact('page_id', 'title', 'body'), $request_key);
    }

    #[McpTool(name: 'archive_page', description: 'Request removal of the current published page from public view. Its working draft and approval history remain. Pending means it is still public.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: false), meta: ApprovalCard::TOOL_META, outputSchema: ApprovalFlow::OUTPUT_SCHEMA)]
    public function archive(RequestContext $context, string $page_id, ?string $request_key = null): CallToolResult|InputRequiredResult
    {
        return $this->ask($context, 'archive_page', 'page.archive', ['page_id' => $page_id], $request_key);
    }

    #[McpTool(name: 'delete_page', description: 'Request permanent deletion of a page and its working draft. Approval evidence is retained. Pending means no deletion occurred.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: false), meta: ApprovalCard::TOOL_META, outputSchema: ApprovalFlow::OUTPUT_SCHEMA)]
    public function delete(RequestContext $context, string $page_id, ?string $request_key = null): CallToolResult|InputRequiredResult
    {
        return $this->ask($context, 'delete_page', 'page.delete', ['page_id' => $page_id], $request_key);
    }

    private function ask(RequestContext $context, string $tool, string $action, array $input, ?string $key): CallToolResult|InputRequiredResult
    {
        try {
            $this->scope($context, 'pages.write', 'approval.read');
            $actor = $this->actors->current();

            return $this->flow->respond($context, $actor, $tool, $input + ['request_key' => $key],
                fn (): ApprovalState => $this->approvals->request($actor, $action, $input, $key));
        } catch (ApprovalException $error) {
            return ApprovalFlow::errorResult($error);
        }
    }

    private function scope(RequestContext $context, string ...$scopes): void
    {
        $token = $context->getAccessToken() ?? throw new ApprovalException('unauthenticated', 'A valid personal token is required.');
        foreach ($scopes as $scope) {
            if (!$token->hasScope($scope)) {
                throw new ApprovalException('scope_denied', 'Missing scope: '.$scope);
            }
        }
    }

    private static function result(array $data): CallToolResult
    {
        return new CallToolResult([new TextContent(json_encode($data, \JSON_THROW_ON_ERROR))], structuredContent: $data);
    }
}
