<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use VerifiedHumanApproval\Doctrine\Orm\RequiresApproval;

/** The working draft and the published snapshot have independent lifecycles. */
#[ORM\Entity]
#[ORM\Table(name: 'app_pages')]
class Page
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 32)]
    public string $id;

    #[ORM\Column(name: 'draft_title', type: Types::STRING, length: 200)]
    public string $draftTitle;

    #[ORM\Column(name: 'draft_body', type: Types::TEXT)]
    public string $draftBody;

    #[ORM\Column(name: 'draft_revision', type: Types::INTEGER)]
    public int $draftRevision = 1;

    // These stay null until a guarded action first publishes the page. A new draft may therefore be inserted freely.
    #[RequiresApproval]
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    public ?string $status = null;

    #[RequiresApproval]
    #[ORM\Column(name: 'live_title', type: Types::STRING, length: 200, nullable: true)]
    public ?string $liveTitle = null;

    #[RequiresApproval]
    #[ORM\Column(name: 'live_body', type: Types::TEXT, nullable: true)]
    public ?string $liveBody = null;

    #[RequiresApproval]
    #[ORM\Column(name: 'live_revision', type: Types::INTEGER, nullable: true)]
    public ?int $liveRevision = null;

    #[RequiresApproval]
    #[ORM\Column(name: 'live_request_id', type: Types::STRING, length: 64, nullable: true)]
    public ?string $liveRequestId = null;

    public function __construct(string $id, string $title, string $body)
    {
        $this->id = $id;
        $this->draftTitle = $title;
        $this->draftBody = $body;
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->draftTitle !== $this->liveTitle || $this->draftBody !== $this->liveBody;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status ?? self::DRAFT,
            'draft' => ['title' => $this->draftTitle, 'body' => $this->draftBody, 'revision' => $this->draftRevision],
            'live' => null === $this->liveRevision ? null : [
                'title' => $this->liveTitle, 'body' => $this->liveBody,
                'revision' => $this->liveRevision, 'request_id' => $this->liveRequestId,
            ],
        ];
    }
}
