<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A person who signs in. The role is the permission the approval checks: the authorizer reads it again, locked,
 * inside every approval transaction, so a role taken away while a request waits stops that request.
 */
#[ORM\Entity]
#[ORM\Table(name: 'publishing_users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** May request every change to what visitors see, and approve their own requests with their passkey. */
    public const EDITOR = 'editor';
    /** May read the newsroom and the articles, and request nothing. */
    public const READER = 'reader';

    #[ORM\Column(name: 'password_hash', type: Types::STRING, length: 255)]
    private string $passwordHash = '';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $id,
        #[ORM\Column(name: 'display_name', type: Types::STRING, length: 200)]
        private string $displayName,
        #[ORM\Column(name: 'role', type: Types::STRING, length: 16)]
        private string $role,
    ) {
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->id) {
            throw new \LogicException('An account always has an identifier.');
        }

        return $this->id;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function isEditor(): bool
    {
        return self::EDITOR === $this->role;
    }

    public function getRoles(): array
    {
        return $this->isEditor() ? ['ROLE_USER', 'ROLE_EDITOR'] : ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
    }

    /** Nothing to erase: only the password's hash is kept. Marked so, Symfony 7.3 and later skip it; Symfony 8 drops it. */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
