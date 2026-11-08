<?php

declare(strict_types=1);

namespace ExactApproval\Core\Evidence;

interface EvidenceSigner
{
    public function keyId(): string;
    /** Return a base64url signature over the supplied canonical manifest bytes. */
    public function sign(string $manifest): string;
}
