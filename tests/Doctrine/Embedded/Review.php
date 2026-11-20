<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Doctrine\Embedded;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use VerifiedHumanApproval\Doctrine\Orm\RequiresApproval;

/** Who signed a page off, which only an approved action may record, and a remark anyone may write. */
#[ORM\Embeddable]
class Review
{
    #[RequiresApproval]
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    public ?string $signedOffBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $remark = null;
}
