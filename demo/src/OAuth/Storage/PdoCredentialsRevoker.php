<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth\Storage;

use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\Bundle\OAuth2ServerBundle\Service\CredentialsRevokerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\OAuth\OAuthTransaction;

/** Revokes every code and token of one person, or of one client, for league/oauth2-server-bundle. */
final readonly class PdoCredentialsRevoker implements CredentialsRevokerInterface
{
    public function __construct(private DemoDatabase $database, private OAuthTransaction $transaction)
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
        $this->transaction->run(function () use ($column, $value): void {
            $grantColumn = 'client' === $column ? 'client_id' : 'user_id';
            $this->database->pdo->prepare('UPDATE demo_grants SET active = 0 WHERE '.$grantColumn.' = ? AND token_hash IS NULL')->execute([$value]);
            $this->database->pdo->prepare('UPDATE oauth2_refresh_tokens SET revoked = 1 WHERE access_token IN (SELECT identifier FROM oauth2_access_tokens WHERE '.$column.' = ?)')->execute([$value]);
            $this->database->pdo->prepare('UPDATE oauth2_access_tokens SET revoked = 1 WHERE '.$column.' = ?')->execute([$value]);
            $this->database->pdo->prepare('UPDATE oauth2_authorization_codes SET revoked = 1 WHERE '.$column.' = ?')->execute([$value]);
        });
    }
}
