<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** The host application's account. Approval never supplies or replaces this identity. */
final readonly class Account implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private string $id,
        private string $username,
        private string $displayName,
        private string $passwordHash,
        private string $role,
        private bool $enabled = true,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER', $this->role];
    }

    public function eraseCredentials(): void
    {
    }
}
