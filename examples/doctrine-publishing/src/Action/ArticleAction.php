<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Action;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Doctrine\DbalTransaction;
use VerifiedHumanApproval\Doctrine\Orm\EntityAction;
use VerifiedHumanApproval\Doctrine\Orm\ProtectedEntityListener;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;

/**
 * What the four approved changes share. Each is an EntityAction: the approval service runs prepare(), the
 * preconditions, execute() and the postconditions inside one database transaction, and execute() loads the
 * articles it changes locked for writing, lets apply() change them and flushes. The articles are read with
 * current(), which locks them too (FOR UPDATE on PostgreSQL and MySQL), so nothing changes them between the checks
 * and the commit.
 */
abstract class ArticleAction extends EntityAction
{
    /** Every action this site approves: the authorizer allows nothing else. */
    public const IDS = [PublishArticle::ID, EditLiveArticle::ID, ArchiveArticles::ID, DeleteArticle::ID];

    /** How the site is named on the review page and in the statement the person approves. */
    public const APPLICATION = 'Newsroom website';

    private ?string $layout = null;

    public function __construct(
        ManagerRegistry $doctrine,
        private readonly ExecutionGuard $guard,
        DbalTransaction $transaction,
        ProtectedEntityListener $protection,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        /** The template that shows an article to visitors, and in the review page's preview. */
        #[Autowire('%kernel.project_dir%/templates/public/_article.html.twig')]
        private readonly string $articleTemplate,
    ) {
        parent::__construct($doctrine, $guard, $transaction, $protection);
    }

    /**
     * What a proposal is about: this site and the article (for a batch, the articles) it changes.
     *
     * @return array<string, mixed>
     */
    protected function target(Actor $actor, string $resource): array
    {
        return ['application' => self::APPLICATION, 'environment' => $this->environment, 'tenant_id' => $actor->tenantId, 'resource' => $resource];
    }

    /**
     * A version that names the template visitors see the text through: if a deploy changes how an article looks
     * after the person reviewed its preview, the request stops (`implementation_changed`) instead of going live.
     */
    protected function withLayout(string $version): string
    {
        if (null === $this->layout) {
            $digest = hash_file('sha256', $this->articleTemplate);
            $this->layout = false === $digest ? throw new \RuntimeException('The article template is missing.') : substr($digest, 0, 16);
        }

        return $version.':'.$this->layout;
    }

    /** The article as stored now, locked for writing: for prepare(). */
    protected function article(mixed $id): Article
    {
        $article = \is_string($id) && 1 === preg_match('/^[0-9a-f]{16}$/D', $id) ? $this->current(Article::class, $id) : null;

        return $article ?? throw new ApprovalException('not_found', 'No such article.');
    }

    /** The article a frozen proposal names, as stored now and locked: for the preconditions. */
    protected function stillThere(mixed $id): Article
    {
        $article = \is_string($id) ? $this->current(Article::class, $id) : null;

        return $article ?? throw new ApprovalException('missing_resource', 'The article was deleted after the request was made.');
    }

    /** The approval this change runs under, which the article records so the public page can check it. */
    protected function approvalId(): string
    {
        return $this->guard->current()->requestId ?? throw new \LogicException('Only an approval that is executing has a request id.');
    }

    /**
     * The tool's or the form's input, with exactly these fields.
     *
     * @param array<string, mixed> $input
     * @param list<string>         $fields
     *
     * @return array<string, mixed>
     */
    protected static function input(array $input, array $fields): array
    {
        $given = array_keys($input);
        sort($given);
        sort($fields);
        if ($given !== $fields) {
            throw new ApprovalException('invalid_input', 'Give exactly: '.implode(', ', $fields).'.');
        }

        return $input;
    }

    /**
     * For publishing and live edits, read back after the flush: the article is live with exactly the approved text,
     * under this approval.
     *
     * @param array<string, mixed> $result
     */
    protected function assertLiveAsApproved(PreparedAction $action, array $result): void
    {
        $id = $action->target['resource'];
        $article = \is_string($id) ? $this->current(Article::class, $id) : null;
        if (null === $article || Article::LIVE !== $article->status() || $article->liveTitle() !== $action->effect['title']
            || $article->liveBody() !== $action->effect['body'] || $article->revision() !== $action->effect['revision']
            || $article->liveRequestId() !== $this->approvalId()
            || $result !== ['article_id' => $id, 'status' => Article::LIVE, 'revision' => $action->effect['revision']]) {
            throw new ApprovalException('unexpected_effect', 'What visitors would see is not what was approved, so nothing changed.');
        }
    }
}
