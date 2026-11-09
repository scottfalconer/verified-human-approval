<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Fixture;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use VerifiedHumanApproval\Doctrine\Orm\RequiresApproval;

/** A public notice: the whole entity changes only through an approved action. */
#[ORM\Entity]
#[ORM\Table(name: 'orm_notices')]
#[RequiresApproval]
class Notice
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public int $id,
        #[ORM\Column(type: Types::TEXT)]
        public string $text,
    ) {
    }
}
