<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Embedded;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use VerifiedHumanApproval\Doctrine\Orm\RequiresApproval;

/**
 * A page whose approved state is held in embedded objects: the published copy, marked; the scheduled copy, named in
 * the listener's configuration; the review, whose embeddable marks one field of its own; and the listing, whose
 * embeddable marks one of the objects it embeds.
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

    #[ORM\Embedded(class: Listing::class)]
    public Listing $listing;

    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?self $relatedPage = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public int $id,
    ) {
        $this->published = new Copy();
        $this->scheduled = new Copy();
        $this->review = new Review();
        $this->listing = new Listing();
    }
}
