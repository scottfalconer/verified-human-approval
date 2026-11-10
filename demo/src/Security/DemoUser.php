<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class DemoUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $displayName,
        private string $passwordHash,
        private array $roles,
        public ?string $delegationId = null,
    ) {
    }

    public static function fromRow(array $row, ?string $delegationId = null): self
    {
        $roles = ['ROLE_USER'];
        if ((bool) $row['can_publish']) {
            $roles[] = 'ROLE_PUBLISHER';
        }
        // A personal agent token intentionally never gets account/policy administration roles.
        if (null === $delegationId && (bool) $row['is_admin']) {
            $roles[] = 'ROLE_ADMIN';
        }

        return new self($row['id'], $row['tenant_id'], $row['display_name'], $row['password_hash'], $roles, $delegationId);
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->id) {
            throw new \LogicException('A demo account always has an identifier.');
        }

        return $this->id;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /** Nothing to erase: only the password's hash is kept. Marked so, Symfony 7.3 and later skip it; Symfony 8 drops it. */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
