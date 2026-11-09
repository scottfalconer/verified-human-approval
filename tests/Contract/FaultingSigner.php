<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Contract;

use VerifiedHumanApproval\Core\Evidence\EvidenceSigner;

/** A real Ed25519 receipt signer that can be made to fail. */
final readonly class FaultingSigner implements EvidenceSigner
{
    public function __construct(private EvidenceSigner $inner, private Faults $faults)
    {
    }

    public function keyId(): string
    {
        return $this->inner->keyId();
    }

    public function sign(string $manifest): string
    {
        if ($this->faults->signer) {
            throw new \RuntimeException('Injected receipt signer outage.');
        }

        return $this->inner->sign($manifest);
    }

    public function publicKey(): string
    {
        return $this->inner->publicKey();
    }
}
