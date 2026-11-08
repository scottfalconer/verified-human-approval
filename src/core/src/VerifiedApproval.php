<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

final readonly class VerifiedApproval
{
    /** @param array<string, mixed> $evidence */
    public function __construct(public string $credentialId, public array $evidence)
    {
        if ('' === $credentialId) {
            throw new \InvalidArgumentException('A verified approval needs a credential reference.');
        }
        CanonicalJson::encode((object) $evidence);
    }
}
