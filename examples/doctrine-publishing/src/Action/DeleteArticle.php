<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Action;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;

/**
 * Deletes one article for good: its draft and, if it was ever published, its live text. The proposal freezes
 * exactly what disappears, and the article is deleted only while it is still exactly that: a changed draft, or a
 * change to what visitors see, stops it. Nothing else is touched.
 */
final class DeleteArticle extends ArticleAction
{
    public const ID = 'article.delete';

    public function id(): string
    {
        return self::ID;
    }

    public function version(): string
    {
        return '1';
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        $article = $this->article(self::input($input, ['article_id'])['article_id']);
        $what = match ($article->status()) {
            Article::LIVE => 'what visitors see now, and its draft',
            Article::ARCHIVED => 'its archived text and its draft',
            default => 'its draft',
        };

        return new PreparedAction(
            self::ID,
            $this->version(),
            $this->target($actor, $article->id()),
            // No title or body here: nothing may show a badge on the strength of a deletion.
            ['deleted' => true],
            // What disappears, exactly: the article is deleted only while it is still this.
            self::snapshot($article),
            [
                'title' => 'Delete “'.$article->title().'”',
                'consequence' => Article::LIVE === $article->status() ? 'Visitors stop seeing it as soon as you approve, and it cannot be restored.' : 'It cannot be restored.',
            ],
            ['title' => $article->title(), 'what' => $what, 'application' => self::APPLICATION],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        // Compared as canonical JSON: a proposal read back from storage has its keys in canonical order.
        if (CanonicalJson::encode(self::snapshot($this->stillThere($action->target['resource']))) !== CanonicalJson::encode($action->preconditions)) {
            throw new ApprovalException('stale_resource', 'The article changed after the request, so it was not deleted. Ask again to review what would disappear now.');
        }
    }

    protected function targets(Actor $actor, PreparedAction $action): array
    {
        return ['article' => [Article::class, $action->target['resource']]];
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        $this->manager()->remove($entities['article']);

        return ['deleted' => $action->target['resource']];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $id = $action->target['resource'];
        if (!\is_string($id) || null !== $this->current(Article::class, $id) || $result !== ['deleted' => $id]) {
            throw new ApprovalException('unexpected_effect', 'The article was not deleted as approved, so nothing changed.');
        }
    }

    /**
     * Everything the article holds.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(Article $article): array
    {
        return [
            'status' => $article->status(), 'revision' => $article->revision(),
            'draft_title' => $article->draftTitle(), 'draft_body' => $article->draftBody(),
            'live_title' => $article->liveTitle(), 'live_body' => $article->liveBody(), 'live_request_id' => $article->liveRequestId(),
        ];
    }
}
