<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Action;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Example\Publishing\Editing\ArticleText;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;

/**
 * Changes the text of a live article. The proposed text is frozen beside the live text it replaces, and the review
 * shows the two side by side; visitors keep seeing the live text until the person approves. If what visitors see
 * changed after the request, by another approved edit say, this one stops: it never overwrites the newer text.
 */
final class EditLiveArticle extends ArticleAction
{
    public const ID = 'article.edit_live';

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
        $input = self::input($input, ['article_id', 'title', 'body']);
        $article = $this->article($input['article_id']);
        if (Article::LIVE !== $article->status()) {
            throw new ApprovalException('not_live', 'Only a live article’s text changes this way. Publish its draft instead.');
        }
        $title = ArticleText::title($input['title']);
        $body = ArticleText::body($input['body']);
        if ($title === $article->liveTitle() && $body === $article->liveBody()) {
            throw new ApprovalException('no_change', 'That is the live text already.');
        }
        $revision = (int) $article->revision() + 1;

        return new PreparedAction(
            self::ID,
            $this->version(),
            $this->target($actor, $article->id()),
            ['title' => $title, 'body' => $body, 'revision' => $revision],
            // What visitors see now: shown beside the proposal, and required unchanged when it runs.
            ['status' => Article::LIVE, 'revision' => $article->revision(), 'title' => $article->liveTitle(), 'body' => $article->liveBody()],
            ['title' => 'Change the live article “'.$article->liveTitle().'”', 'consequence' => 'Visitors keep seeing the live text until you approve, then see the new text.'],
            ['current_title' => (string) $article->liveTitle(), 'title' => $title, 'from' => (string) $article->revision(), 'to' => (string) $revision, 'application' => self::APPLICATION],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $article = $this->stillThere($action->target['resource']);
        if (Article::LIVE !== $article->status() || $article->revision() !== $action->preconditions['revision']
            || $article->liveTitle() !== $action->preconditions['title'] || $article->liveBody() !== $action->preconditions['body']) {
            throw new ApprovalException('stale_resource', 'What visitors see changed after the request. This change never overwrites newer text: ask again to review it against what is live now.');
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
        // While an article is live its draft follows the live text, so archiving and publishing again starts from it.
        $article->editDraft($action->effect['title'], $action->effect['body']);

        return ['article_id' => $article->id(), 'status' => Article::LIVE, 'revision' => $action->effect['revision']];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $this->assertLiveAsApproved($action, $result);
    }
}
