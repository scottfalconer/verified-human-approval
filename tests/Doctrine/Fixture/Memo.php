<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Fixture;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** An entity with no attribute, protected by configuration instead. */
#[ORM\Entity]
#[ORM\Table(name: 'orm_memos')]
class Memo
{
    #[ORM\Column(name: 'signed_off', type: Types::TEXT, nullable: true)]
    public ?string $signedOff = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public int $id,
        #[ORM\Column(type: Types::TEXT)]
        public string $text,
    ) {
    }
}
