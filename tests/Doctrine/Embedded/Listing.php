<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Embedded;

use Doctrine\ORM\Mapping as ORM;
use VerifiedHumanApproval\Doctrine\Orm\RequiresApproval;

/** How a page is listed: a summary that only an approved action may change, and notes anyone may write. */
#[ORM\Embeddable]
class Listing
{
    #[RequiresApproval]
    #[ORM\Embedded(class: Copy::class)]
    public Copy $summary;

    #[ORM\Embedded(class: Copy::class)]
    public Copy $notes;

    public function __construct()
    {
        $this->summary = new Copy();
        $this->notes = new Copy();
    }
}
