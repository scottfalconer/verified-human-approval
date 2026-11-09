<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A one-time link that lets one signed-in person add a passkey: a password alone never can. It is stored only as a
 * hash, and its issue and its use are both in the signed audit log, so only a passkey enrolled through one approves.
 */
#[ORM\Entity]
#[ORM\Table(name: 'publishing_passkey_links')]
class PasskeyLink
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
        private string $tokenHash,
        #[ORM\Column(name: 'user_id', type: Types::STRING, length: 64)]
        private string $userId,
        #[ORM\Column(name: 'expires_at', type: Types::INTEGER)]
        private int $expiresAt,
        /** When a registration used it; the update that sets this is the link's single use. */
        #[ORM\Column(name: 'used_at', type: Types::INTEGER, nullable: true)]
        private ?int $usedAt = null,
    ) {
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    /** Whether this person may still use it to add a passkey. */
    public function opensFor(string $userId, int $now): bool
    {
        return null === $this->usedAt && $this->userId === $userId && $now <= $this->expiresAt;
    }
}
