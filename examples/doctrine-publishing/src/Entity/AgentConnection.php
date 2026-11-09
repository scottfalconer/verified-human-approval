<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * EXAMPLE-GRADE: a personal bearer token for one person's agent, and the delegation it stands for. The token is
 * stored only as its SHA-256 hash; its id is the delegation id every approval records and re-checks as it runs.
 * There is no expiry, rotation or OAuth here: a real application issues and revokes these through its own account
 * process, as the demo's OAuth server shows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'publishing_agent_connections')]
#[ORM\UniqueConstraint(name: 'publishing_agent_token', columns: ['token_hash'])]
class AgentConnection
{
    #[ORM\Column(name: 'revoked_at', type: Types::INTEGER, nullable: true)]
    private ?int $revokedAt = null;

    /**
     * @param string $scopes what the agent may do, space-separated: articles.read, articles.write, approval.read
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: Types::STRING, length: 64)]
        private string $userId,
        #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
        private string $tokenHash,
        #[ORM\Column(name: 'scopes', type: Types::STRING, length: 255)]
        private string $scopes,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return array_values(array_filter(explode(' ', $this->scopes)));
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }

    public function revoke(int $at): void
    {
        $this->revokedAt ??= $at;
    }
}
