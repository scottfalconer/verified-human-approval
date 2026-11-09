<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use VerifiedHumanApproval\Doctrine\Orm\RequiresApproval;

/**
 * An article: a draft that editors change freely, and the live version that visitors see.
 *
 * Everything visitors see, and everything that says it was approved, sits in the fields marked #[RequiresApproval].
 * The bundle's ProtectedEntityListener refuses any flush that changes them, inserts an article holding them or
 * deletes one that holds them, unless an approved action is executing for this article: a form, a console command
 * or a script that tries is stopped with `approval_required`. They are null until something is approved, so a new
 * draft holds nothing approved and needs no approval. That is why `status` is null for a draft.
 */
#[ORM\Entity]
#[ORM\Table(name: 'publishing_articles')]
class Article
{
    public const DRAFT = 'draft';
    public const LIVE = 'live';
    public const ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $id;

    #[ORM\Column(name: 'draft_title', type: Types::STRING, length: 200)]
    private string $draftTitle;

    #[ORM\Column(name: 'draft_body', type: Types::TEXT)]
    private string $draftBody;

    /** Live or archived once published; null while the article is a draft that was never published. */
    #[RequiresApproval]
    #[ORM\Column(name: 'status', type: Types::STRING, length: 16, nullable: true)]
    private ?string $status = null;

    #[RequiresApproval]
    #[ORM\Column(name: 'live_title', type: Types::STRING, length: 200, nullable: true)]
    private ?string $liveTitle = null;

    #[RequiresApproval]
    #[ORM\Column(name: 'live_body', type: Types::TEXT, nullable: true)]
    private ?string $liveBody = null;

    /** Goes up by one with every approved change to what visitors see: publishing, a live edit, archiving. */
    #[RequiresApproval]
    #[ORM\Column(name: 'revision', type: Types::INTEGER, nullable: true)]
    private ?int $revision = null;

    /** The approval that last changed what visitors see. The public page checks its signed receipt for the badge. */
    #[RequiresApproval]
    #[ORM\Column(name: 'live_request_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $liveRequestId = null;

    public function __construct(string $id, string $title, string $body)
    {
        $this->id = $id;
        $this->draftTitle = $title;
        $this->draftBody = $body;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function draftTitle(): string
    {
        return $this->draftTitle;
    }

    public function draftBody(): string
    {
        return $this->draftBody;
    }

    public function status(): string
    {
        return $this->status ?? self::DRAFT;
    }

    public function liveTitle(): ?string
    {
        return $this->liveTitle;
    }

    public function liveBody(): ?string
    {
        return $this->liveBody;
    }

    public function revision(): ?int
    {
        return $this->revision;
    }

    public function liveRequestId(): ?string
    {
        return $this->liveRequestId;
    }

    /** The title the newsroom knows the article by: the live one once it has been published. */
    public function title(): string
    {
        return $this->liveTitle ?? $this->draftTitle;
    }

    public function editDraft(string $title, string $body): void
    {
        $this->draftTitle = $title;
        $this->draftBody = $body;
    }

    /**
     * Makes this text what visitors see. Only an approved action's flush can save it: these fields are protected.
     */
    public function putLive(string $title, string $body, int $revision, string $requestId): void
    {
        $this->status = self::LIVE;
        $this->liveTitle = $title;
        $this->liveBody = $body;
        $this->revision = $revision;
        $this->liveRequestId = $requestId;
    }

    /** Visitors stop seeing the article. Its last live text stays, as the archived copy. Protected, like putLive(). */
    public function archive(int $revision, string $requestId): void
    {
        $this->status = self::ARCHIVED;
        $this->revision = $revision;
        $this->liveRequestId = $requestId;
    }

    /**
     * What the MCP read tool reports.
     *
     * @return array{id: string, status: string, revision: ?int, draft: array{title: string, body: string}, live: array{title: string, body: string, approval_request_id: ?string}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status(),
            'revision' => $this->revision,
            'draft' => ['title' => $this->draftTitle, 'body' => $this->draftBody],
            'live' => null === $this->liveTitle || null === $this->liveBody ? null
                : ['title' => $this->liveTitle, 'body' => $this->liveBody, 'approval_request_id' => $this->liveRequestId],
        ];
    }
}
