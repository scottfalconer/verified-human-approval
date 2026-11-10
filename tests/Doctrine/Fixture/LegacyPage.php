<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Fixture;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use VerifiedHumanApproval\Doctrine\Orm\RequiresApproval;

/** An existing CMS schema: draft is a non-null value, not an absence of protected state. */
#[ORM\Entity]
#[ORM\Table(name: 'orm_legacy_pages')]
class LegacyPage
{
    #[RequiresApproval]
    #[ORM\Column(type: Types::STRING, length: 20)]
    public string $status = 'draft';

    #[RequiresApproval]
    #[ORM\Column(type: Types::INTEGER)]
    public int $revision = 0;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public int $id,
        #[ORM\Column(type: Types::STRING, length: 64)]
        public string $tenant,
        #[RequiresApproval]
        #[ORM\Column(type: Types::TEXT)]
        public string $body,
    ) {
    }
}
