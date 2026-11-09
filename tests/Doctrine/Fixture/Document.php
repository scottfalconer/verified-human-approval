<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Fixture;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use VerifiedHumanApproval\Doctrine\Orm\RequiresApproval;

/**
 * A document with a draft anyone with access may edit, and the published copy only an approved action may set:
 * the protected fields hold the approved state, null until something is approved.
 */
#[ORM\Entity]
#[ORM\Table(name: 'orm_documents')]
class Document
{
    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $title;

    #[ORM\Column(type: Types::TEXT)]
    public string $body;

    #[ORM\Column(type: Types::INTEGER)]
    public int $revision = 1;

    #[RequiresApproval]
    #[ORM\Column(name: 'published_title', type: Types::STRING, length: 255, nullable: true)]
    public ?string $publishedTitle = null;

    #[RequiresApproval]
    #[ORM\Column(name: 'published_body', type: Types::TEXT, nullable: true)]
    public ?string $publishedBody = null;

    #[RequiresApproval]
    #[ORM\Column(name: 'published_revision', type: Types::INTEGER, nullable: true)]
    public ?int $publishedRevision = null;

    /** The approval the published copy was made under, linking it to its evidence. */
    #[ORM\Column(name: 'approval_request_id', type: Types::STRING, length: 64, nullable: true)]
    public ?string $approvalRequestId = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public int $id,
        #[ORM\Column(type: Types::STRING, length: 64)]
        public string $tenant,
        string $title,
        string $body,
    ) {
        $this->title = $title;
        $this->body = $body;
    }
}
