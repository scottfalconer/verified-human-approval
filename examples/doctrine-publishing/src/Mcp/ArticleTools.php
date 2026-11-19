<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Example\Publishing\Action\ArchiveArticles;
use VerifiedHumanApproval\Example\Publishing\Action\DeleteArticle;
use VerifiedHumanApproval\Example\Publishing\Action\EditLiveArticle;
use VerifiedHumanApproval\Example\Publishing\Action\PublishArticle;
use VerifiedHumanApproval\Example\Publishing\Approval\EditorialPermissions;
use VerifiedHumanApproval\Example\Publishing\Editing\Drafts;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;
use VerifiedHumanApproval\Example\Publishing\Entity\User;
use VerifiedHumanApproval\Mcp\ApprovalCard;
use VerifiedHumanApproval\Mcp\ApprovalFlow;

/**
 * The newsroom for an agent. Reading and drafting need no approval. The four changes to what visitors see make the
 * same ApprovalService::request() call as the newsroom's web pages, so an agent can ask for nothing a person's
 * browser could not, and nothing it asks for happens before that person approves it with their passkey. A pending
 * result is not a change: the agent shows the review link, and reads approval_status afterwards.
 */
final readonly class ArticleTools
{
    private const ARTICLES = [
        'type' => 'object',
        'properties' => ['articles' => ['type' => 'array', 'items' => self::ARTICLE]],
        'required' => ['articles'],
    ];

    private const ARTICLE = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => [Article::DRAFT, Article::LIVE, Article::ARCHIVED], 'description' => 'Only a live article is on the public page.'],
            'revision' => ['type' => ['integer', 'null'], 'description' => 'One more for every approved change to what visitors see; null until first published.'],
            'draft' => ['type' => 'object', 'description' => 'The working copy. Visitors never see it.'],
            'live' => ['type' => ['object', 'null'], 'description' => 'What visitors see while the article is live (the archived copy once archived), and the approval that last changed it.'],
        ],
        'required' => ['id', 'status', 'revision', 'draft', 'live'],
    ];

    private const ASKS = 'Nothing changes yet: the result is pending, with a review_url. Show it to the person, who approves the exact change on the site with their passkey. Then call approval_status. To retry, call again with the same arguments: while the request is pending, this returns the same request.';

    public function __construct(
        private ApprovalService $approvals,
        private ApprovalFlow $flow,
        private ActorProvider $actors,
        private Drafts $drafts,
        private EntityManagerInterface $entities,
        private Security $security,
    ) {
    }

    #[McpTool(
        name: 'list_articles',
        title: 'List articles',
        description: 'Read every article in the newsroom: its draft, whether it is live, and the live text visitors see. Reading needs no approval.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: self::ARTICLES,
    )]
    public function list(RequestContext $context): CallToolResult
    {
        try {
            self::assertScopes($context, EditorialPermissions::READ);
        } catch (ApprovalException $error) {
            return CallToolResult::error([new TextContent($error->getMessage())]);
        }
        $articles = array_map(static fn (Article $article): array => $article->toArray(), $this->entities->getRepository(Article::class)->findBy([], ['draftTitle' => 'ASC']));

        return self::result(['articles' => $articles]);
    }

    #[McpTool(
        name: 'save_draft',
        title: 'Save a draft',
        description: 'Write a new draft, or change the draft of an article that is not live. Visitors never see drafts, so this needs no approval. A live article changes only through edit_live_article.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: self::ARTICLE,
    )]
    public function saveDraft(
        RequestContext $context,
        #[Schema(description: 'The title, up to 200 characters.')] string $title,
        #[Schema(description: 'The text. Line breaks are kept.')] string $body,
        #[Schema(description: 'The article whose draft to change; leave it out to write a new one.')] ?string $article_id = null,
    ): CallToolResult {
        try {
            self::assertScopes($context, EditorialPermissions::WRITE);
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                throw new ApprovalException('unauthenticated', 'Use your agent token.');
            }
            $article = null === $article_id ? $this->drafts->create($user, $title, $body) : $this->drafts->save($user, $article_id, $title, $body);
        } catch (ApprovalException $error) {
            return CallToolResult::error([new TextContent($error->getMessage())]);
        }

        return self::result($article->toArray());
    }

    #[McpTool(
        name: 'publish_article',
        title: 'Publish an article',
        description: 'Ask to publish an article’s draft, exactly as it is now, on the public page. '.self::ASKS,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        meta: ApprovalCard::TOOL_META,
        outputSchema: ApprovalFlow::OUTPUT_SCHEMA,
    )]
    public function publish(
        RequestContext $context,
        #[Schema(description: 'The article’s id, as list_articles reports it.')] string $article_id,
        #[Schema(description: ApprovalFlow::REQUEST_KEY_DESCRIPTION)] ?string $request_key = null,
    ): CallToolResult|InputRequiredResult {
        return $this->ask($context, 'publish_article', PublishArticle::ID, ['article_id' => $article_id], $request_key);
    }

    #[McpTool(
        name: 'edit_live_article',
        title: 'Change a live article',
        description: 'Ask to replace the text visitors see on a live article. The person reviews it side by side with the live text, which stays until they approve; if the live text changes first, this request stops. '.self::ASKS,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        meta: ApprovalCard::TOOL_META,
        outputSchema: ApprovalFlow::OUTPUT_SCHEMA,
    )]
    public function editLive(
        RequestContext $context,
        #[Schema(description: 'The live article’s id, as list_articles reports it.')] string $article_id,
        #[Schema(description: 'The new title, up to 200 characters.')] string $title,
        #[Schema(description: 'The new text.')] string $body,
        #[Schema(description: ApprovalFlow::REQUEST_KEY_DESCRIPTION)] ?string $request_key = null,
    ): CallToolResult|InputRequiredResult {
        return $this->ask($context, 'edit_live_article', EditLiveArticle::ID, ['article_id' => $article_id, 'title' => $title, 'body' => $body], $request_key);
    }

    /** @param list<string> $article_ids */
    #[McpTool(
        name: 'archive_articles',
        title: 'Archive articles',
        description: 'Ask to take one or more live articles off the public page, under one approval: all of them, or none if any changes first. '.self::ASKS,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        meta: ApprovalCard::TOOL_META,
        outputSchema: ApprovalFlow::OUTPUT_SCHEMA,
    )]
    public function archive(
        RequestContext $context,
        #[Schema(description: 'The live articles’ ids, as list_articles reports them.', items: ['type' => 'string'], minItems: 1, maxItems: ArchiveArticles::LIMIT, uniqueItems: true)] array $article_ids,
        #[Schema(description: ApprovalFlow::REQUEST_KEY_DESCRIPTION)] ?string $request_key = null,
    ): CallToolResult|InputRequiredResult {
        return $this->ask($context, 'archive_articles', ArchiveArticles::ID, ['article_ids' => $article_ids], $request_key);
    }

    #[McpTool(
        name: 'delete_article',
        title: 'Delete an article',
        description: 'Ask to delete an article for good: its draft and any live text. '.self::ASKS,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        meta: ApprovalCard::TOOL_META,
        outputSchema: ApprovalFlow::OUTPUT_SCHEMA,
    )]
    public function delete(
        RequestContext $context,
        #[Schema(description: 'The article’s id, as list_articles reports it.')] string $article_id,
        #[Schema(description: ApprovalFlow::REQUEST_KEY_DESCRIPTION)] ?string $request_key = null,
    ): CallToolResult|InputRequiredResult {
        return $this->ask($context, 'delete_article', DeleteArticle::ID, ['article_id' => $article_id], $request_key);
    }

    /**
     * Requests the change, or returns the request a retry names, as the card and the review link present it.
     *
     * @param array<string, mixed> $input
     */
    private function ask(RequestContext $context, string $tool, string $action, array $input, ?string $requestKey): CallToolResult|InputRequiredResult
    {
        try {
            $actor = $this->actors->current();
            // Asking needs the write scope; a retry can return a stored result, so reading results needs its own.
            self::assertScopes($context, EditorialPermissions::WRITE, EditorialPermissions::RESULTS);

            return $this->flow->respond($context, $actor, $tool, $input + ['request_key' => $requestKey],
                fn (): ApprovalState => $this->approvals->request($actor, $action, $input, $requestKey));
        } catch (ApprovalException $error) {
            // One shape for every refusal, and for a stored record that fails its integrity check, which is
            // reported as integrity_error and never as a refusal.
            return ApprovalFlow::errorResult($error);
        } catch (AccessDeniedException) {
            return ApprovalFlow::errorResult(new ApprovalException('unauthenticated', 'Use your agent token.'));
        }
    }

    private static function assertScopes(RequestContext $context, string ...$scopes): void
    {
        $token = $context->getAccessToken() ?? throw new ApprovalException('unauthenticated', 'Use your agent token.');
        foreach ($scopes as $scope) {
            if (!$token->hasScope($scope)) {
                throw new ApprovalException('scope_denied', 'This agent connection does not have the '.$scope.' scope.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function result(array $data): CallToolResult
    {
        return new CallToolResult([new TextContent(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE))], structuredContent: $data);
    }
}
