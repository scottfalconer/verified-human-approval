<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Action;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\CanonicalJson;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;

/**
 * Archives one live article or several, under one approval: visitors stop seeing them. The proposal lists every
 * article with its live revision, and a digest of its live text. If any one of them changed after the request, none
 * is archived; and they are all archived in the one transaction, or none is.
 */
final class ArchiveArticles extends ArticleAction
{
    public const ID = 'article.archive';
    /** The most one approval archives. */
    public const LIMIT = 20;

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
        $ids = self::input($input, ['article_ids'])['article_ids'];
        if (!\is_array($ids) || !array_is_list($ids) || [] === $ids || \count($ids) > self::LIMIT || \count(array_unique($ids, \SORT_REGULAR)) !== \count($ids)) {
            throw new ApprovalException('invalid_input', 'Name 1 to '.self::LIMIT.' different articles to archive.');
        }
        // In one order, so the same articles make the same proposal, and their rows are locked in the same order every time.
        sort($ids, \SORT_STRING);
        $live = $after = $names = [];
        foreach ($ids as $id) {
            $article = $this->article($id);
            if (Article::LIVE !== $article->status()) {
                throw new ApprovalException('not_live', '“'.$article->title().'” is not live, so there is nothing to archive.');
            }
            $live[] = ['id' => $article->id(), 'title' => $article->liveTitle(), 'revision' => $article->revision(), 'text_sha256' => self::digest($article)];
            $after[] = ['id' => $article->id(), 'revision' => (int) $article->revision() + 1];
            $names[] = '“'.$article->liveTitle().'” (live revision '.$article->revision().')';
        }
        $last = array_pop($names);

        return new PreparedAction(
            self::ID,
            $this->version(),
            $this->target($actor, implode(',', $ids)),
            ['status' => Article::ARCHIVED, 'articles' => $after],
            ['articles' => $live],
            [
                'title' => 1 === \count($live) ? 'Archive “'.$live[0]['title'].'”' : 'Archive '.\count($live).' articles',
                'consequence' => 'Visitors stop seeing '.(1 === \count($live) ? 'it' : 'them').' as soon as you approve. If any of them changes first, none is archived.',
            ],
            ['articles' => ([] === $names ? '' : implode(', ', $names).' and ').$last, 'application' => self::APPLICATION],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        foreach ($action->preconditions['articles'] as $frozen) {
            // A proposal read back from storage has its nested objects as stdClass; one just prepared, as arrays.
            $frozen = (array) $frozen;
            $article = $this->stillThere($frozen['id']);
            if (Article::LIVE !== $article->status() || $article->revision() !== $frozen['revision']
                || $article->liveTitle() !== $frozen['title'] || self::digest($article) !== $frozen['text_sha256']) {
                throw new ApprovalException('stale_resource', '“'.$frozen['title'].'” changed after the request, so none of these articles was archived. Ask again to review them as they are now.');
            }
        }
    }

    protected function targets(Actor $actor, PreparedAction $action): array
    {
        $targets = [];
        foreach ($action->effect['articles'] as $article) {
            $targets['article:'.((array) $article)['id']] = [Article::class, ((array) $article)['id']];
        }

        return $targets;
    }

    protected function apply(Actor $actor, PreparedAction $action, array $entities): array
    {
        $archived = [];
        foreach ($action->effect['articles'] as $planned) {
            $planned = (array) $planned;
            $article = $entities['article:'.$planned['id']];
            \assert($article instanceof Article);
            $article->archive($planned['revision'], $this->approvalId());
            $archived[] = ['id' => $article->id(), 'revision' => $planned['revision']];
        }

        return ['archived' => $archived];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $expected = [];
        foreach ($action->effect['articles'] as $planned) {
            $planned = (array) $planned;
            $article = $this->current(Article::class, $planned['id']);
            if (null === $article || Article::ARCHIVED !== $article->status() || $article->revision() !== $planned['revision'] || $article->liveRequestId() !== $this->approvalId()) {
                throw new ApprovalException('unexpected_effect', 'Not every article was archived as approved, so none was.');
            }
            $expected[] = ['id' => $planned['id'], 'revision' => $planned['revision']];
        }
        if ($result !== ['archived' => $expected]) {
            throw new ApprovalException('unexpected_effect', 'The archive did not record what was approved, so nothing changed.');
        }
    }

    /** A digest of the live text, so a change to it that no approval made stops the batch too. */
    private static function digest(Article $article): string
    {
        return hash('sha256', CanonicalJson::encode([$article->liveTitle(), $article->liveBody()]));
    }
}
