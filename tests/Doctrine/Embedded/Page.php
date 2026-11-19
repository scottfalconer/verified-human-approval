<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Embedded;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use VerifiedHumanApproval\Doctrine\Orm\RequiresApproval;

/**
 * A page whose approved state is held in embedded objects: the published copy, marked; the scheduled copy, named in
 * the listener's configuration; and the review, whose embeddable marks one field of its own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'embedded_pages')]
class Page
{
    #[ORM\Column(type: Types::TEXT)]
    public string $draft = '';

    #[RequiresApproval]
    #[ORM\Embedded(class: Copy::class)]
    public Copy $published;

    #[ORM\Embedded(class: Copy::class)]
    public Copy $scheduled;

    #[ORM\Embedded(class: Review::class)]
    public Review $review;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public int $id,
    ) {
        $this->published = new Copy();
        $this->scheduled = new Copy();
        $this->review = new Review();
    }
}
