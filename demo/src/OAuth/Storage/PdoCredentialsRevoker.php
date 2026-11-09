<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth\Storage;

use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\Bundle\OAuth2ServerBundle\Service\CredentialsRevokerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use VerifiedHumanApproval\Demo\DemoDatabase;

/** Revokes every code and token of one person, or of one client, for league/oauth2-server-bundle. */
final readonly class PdoCredentialsRevoker implements CredentialsRevokerInterface
{
    public function __construct(private DemoDatabase $database)
    {
    }

    public function revokeCredentialsForUser(UserInterface $user): void
    {
        $this->revoke('user_identifier', $user->getUserIdentifier());
    }

    public function revokeCredentialsForClient(AbstractClient $client): void
    {
        $this->revoke('client', $client->getIdentifier());
    }

    private function revoke(string $column, string $value): void
    {
        $this->database->pdo->prepare('UPDATE oauth2_refresh_tokens SET revoked = 1 WHERE access_token IN (SELECT identifier FROM oauth2_access_tokens WHERE '.$column.' = ?)')->execute([$value]);
        $this->database->pdo->prepare('UPDATE oauth2_access_tokens SET revoked = 1 WHERE '.$column.' = ?')->execute([$value]);
        $this->database->pdo->prepare('UPDATE oauth2_authorization_codes SET revoked = 1 WHERE '.$column.' = ?')->execute([$value]);
    }
}
