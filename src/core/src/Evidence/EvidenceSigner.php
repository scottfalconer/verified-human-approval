<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core\Evidence;

interface EvidenceSigner
{
    public function keyId(): string;

    /** Return a base64url signature over the supplied canonical manifest bytes. */
    public function sign(string $manifest): string;

    /**
     * The base64url Ed25519 public key that verifies this signer's signatures. The application publishes it,
     * so anyone holding a receipt can check it without trusting the application's records.
     */
    public function publicKey(): string;
}
