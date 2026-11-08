<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\WebAuthn;

use VerifiedHumanApproval\Core\Actor;

/** A host-owned registration record. sourceJson is serialized by the upstream WebAuthn library. */
final readonly class Credential
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $tenantId,
        public string $sourceJson,
        public bool $revoked = false,
        public string $label = 'Passkey',
    ) {
        if ('' === $id || '' === $userId || '' === $tenantId || '' === $sourceJson) {
            throw new \InvalidArgumentException('A credential requires an ID, an owner and a source record.');
        }
    }

    public function belongsTo(Actor $actor): bool
    {
        return $this->userId === $actor->userId && $this->tenantId === $actor->tenantId;
    }
}
