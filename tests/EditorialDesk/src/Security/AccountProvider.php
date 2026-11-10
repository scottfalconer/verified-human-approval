<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<Account> */
final readonly class AccountProvider implements UserProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): Account
    {
        return $this->account($this->connection->fetchAssociative('SELECT * FROM app_accounts WHERE username = ?', [$identifier]), $identifier);
    }

    public function loadById(string $id): Account
    {
        return $this->account($this->connection->fetchAssociative('SELECT * FROM app_accounts WHERE id = ?', [$id]), $id);
    }

    public function refreshUser(UserInterface $user): Account
    {
        if (!$user instanceof Account) {
            throw new UnsupportedUserException('This provider only refreshes application accounts.');
        }

        return $this->loadById($user->id());
    }

    public function supportsClass(string $class): bool
    {
        return Account::class === $class;
    }

    private function account(array|false $row, string $identifier): Account
    {
        if (false === $row || 1 !== (int) $row['enabled']) {
            $error = new UserNotFoundException('The account is unavailable.');
            $error->setUserIdentifier($identifier);
            throw $error;
        }

        return new Account($row['id'], $row['username'], $row['display_name'], $row['password_hash'], $row['role'], true);
    }
}
