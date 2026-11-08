<?php

declare(strict_types=1);

namespace ExactApproval\Core;

final readonly class VerifiedApproval
{
    public function __construct(public string $credentialId, public array $evidence)
    {
        if ($credentialId === '') {
            throw new \InvalidArgumentException('A verified approval needs a credential reference.');
        }
        CanonicalJson::encode((object) $evidence);
    }
}
