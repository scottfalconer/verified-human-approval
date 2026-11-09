<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Action;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;

/**
 * Publishes an article's draft: a draft never published, or an archived article's. The request freezes the draft's
 * exact title and text, and approval puts exactly those live. If the draft, or what visitors see, changed after the
 * request, it stops: the person never approves one text and gets another.
 */
final class PublishArticle extends ArticleAction
{
    public const ID = 'article.publish';

    public function id(): string
    {
        return self::ID;
    }

    public function version(): string
    {
        return $this->withLayout('1');
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $article = $this->article(self::input($input, ['article_id'])['article_id']);
        if (Article::LIVE === $article->status()) {
            throw new ApprovalException('already_live', 'This article is live already: propose a change to its live text instead.');
        }
        $revision = ($article->revision() ?? 0) + 1;

        return new PreparedAction(
            self::ID,
            $this->version(),
            $this->target($actor, $article->id()),
            // What visitors will see. The badge on the public page later compares the live text with this title and body.
            ['title' => $article->draftTitle(), 'body' => $article->draftBody(), 'status' => Article::LIVE, 'revision' => $revision],
            ['status' => $article->status(), 'revision' => $article->revision()],
            // The statement already says what happens on approval, so no separate consequence.
            ['title' => 'Publish “'.$article->draftTitle().'”'],
            ['title' => $article->draftTitle(), 'revision' => (string) $revision, 'application' => self::APPLICATION],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $article = $this->stillThere($action->target['resource']);
        if ($article->status() !== $action->preconditions['status'] || $article->revision() !== $action->preconditions['revision']
            || $article->draftTitle() !== $action->effect['title'] || $article->draftBody() !== $action->effect['body']) {
            throw new ApprovalException('stale_resource', 'The article changed after the request: its draft, or what visitors see. Ask again to review it as it is now.');
        }
    }

    protected function targets(Actor $actor, PreparedAction $action): array
    {
        return ['article' => [Article::class, $action->target['resource']]];
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        $article = $entities['article'];
        \assert($article instanceof Article);
        $article->putLive($action->effect['title'], $action->effect['body'], $action->effect['revision'], $this->approvalId());

        return ['article_id' => $article->id(), 'status' => Article::LIVE, 'revision' => $action->effect['revision']];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $this->assertLiveAsApproved($action, $result);
    }
}
