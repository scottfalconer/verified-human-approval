<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Editing;

use Doctrine\ORM\EntityManagerInterface;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Example\Publishing\Entity\Article;
use VerifiedHumanApproval\Example\Publishing\Entity\User;

/**
 * Drafts, which editors change freely: no approval, because visitors never see a draft. The web page and the MCP
 * tool both save through here. A live article's text changes only through an approved live edit, which also brings
 * its draft along, so the draft of a live article is not edited here.
 */
final readonly class Drafts
{
    public function __construct(private EntityManagerInterface $entities)
    {
    }

    public function create(User $editor, mixed $title, mixed $body): Article
    {
        self::assertEditor($editor);
        $article = new Article(bin2hex(random_bytes(8)), ArticleText::title($title), ArticleText::body($body));
        $this->entities->persist($article);
        $this->entities->flush();

        return $article;
    }

    public function save(User $editor, string $id, mixed $title, mixed $body): Article
    {
        self::assertEditor($editor);
        $article = $this->entities->find(Article::class, $id) ?? throw new ApprovalException('not_found', 'No such article.');
        if (Article::LIVE === $article->status()) {
            throw new ApprovalException('article_live', 'This article is live: propose a change to its live text instead. That change waits for your passkey.');
        }
        $article->editDraft(ArticleText::title($title), ArticleText::body($body));
        $this->entities->flush();

        return $article;
    }

    private static function assertEditor(User $user): void
    {
        if (!$user->isEditor()) {
            throw new ApprovalException('permission_denied', 'Only an editor may write drafts.');
        }
    }
}
