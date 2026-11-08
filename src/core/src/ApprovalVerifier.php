<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

interface ApprovalVerifier
{
    /** @return array<string, mixed> Browser-compatible publicKey options; challenge is raw bytes. */
    public function requestOptions(Actor $actor, string $challenge): array;

    /**
     * Verify ownership, RP/origin, challenge, signature, required UV and current credential acceptance.
     *
     * @param array<string, mixed> $assertion the browser's WebAuthn response
     */
    public function verify(Actor $actor, string $challenge, array $assertion): VerifiedApproval;
}
