<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Embedded;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A title and body, embedded in a page more than once: what is published, and what is scheduled. */
#[ORM\Embeddable]
class Copy
{
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $body = null;
}
