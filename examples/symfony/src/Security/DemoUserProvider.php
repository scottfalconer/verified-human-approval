<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Security;

use ExactApproval\Demo\DemoDatabase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<DemoUser> */
final readonly class DemoUserProvider implements UserProviderInterface
{
    public function __construct(private DemoDatabase $database) {}

    public function loadUserByIdentifier(string $identifier): DemoUser
    {
        $row = $this->database->user($identifier);
        if ($row === null || !(bool) $row['enabled']) throw new UserNotFoundException('Account unavailable.');

        return DemoUser::fromRow($row);
    }

    public function refreshUser(UserInterface $user): DemoUser
    {
        if (!$user instanceof DemoUser || $user->delegationId !== null) throw new UnsupportedUserException();

        return $this->loadUserByIdentifier($user->id);
    }

    public function supportsClass(string $class): bool { return $class === DemoUser::class; }
}
