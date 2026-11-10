<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth\Storage;

use League\Bundle\OAuth2ServerBundle\Manager\AccessTokenManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\AccessTokenInterface;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\OAuth\OAuthConnections;
use VerifiedHumanApproval\Demo\OAuth\OAuthTransaction;
use VerifiedHumanApproval\Demo\OAuth\TokenExchangeContext;

/** Issued access tokens, so league/oauth2-server can tell a revoked one. A token that is not here counts as revoked. */
final readonly class PdoAccessTokenManager implements AccessTokenManagerInterface
{
    public function __construct(private DemoDatabase $database, private PdoClientManager $clients, private OAuthConnections $connections, private OAuthTransaction $transaction, private TokenExchangeContext $exchange)
    {
    }

    public function find(string $identifier): ?AccessTokenInterface
    {
        $query = $this->database->pdo->prepare('SELECT * FROM oauth2_access_tokens WHERE identifier = ?');
        $query->execute([$identifier]);
        $row = $query->fetch();
        $client = \is_array($row) ? $this->clients->find((string) $row['client']) : null;
        if (!\is_array($row) || null === $client) {
            return null;
        }
        $token = new AccessToken($identifier, new \DateTimeImmutable('@'.$row['expiry']), $client, null === $row['user_identifier'] ? null : (string) $row['user_identifier'], StoredScopes::decode($row['scopes']));

        return (bool) $row['revoked'] || null === $this->connections->bound((string) $row['grant_id'], $token->getUserIdentifier(), $client->getIdentifier()) ? $token->revoke() : $token;
    }

    public function save(AccessTokenInterface $accessToken): void
    {
        $this->transaction->run(function () use ($accessToken): void {
            if (null !== $this->grantId($accessToken->getIdentifier())) {
                $this->database->pdo->prepare('UPDATE oauth2_access_tokens SET revoked = MAX(revoked, ?) WHERE identifier = ?')
                    ->execute([(int) $accessToken->isRevoked(), $accessToken->getIdentifier()]);

                return;
            }
            $grantId = $this->exchange->grantFor($accessToken->getUserIdentifier(), $accessToken->getClient()->getIdentifier());
            $this->database->pdo->prepare('INSERT INTO oauth2_access_tokens (identifier, expiry, user_identifier, client, scopes, revoked, grant_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $accessToken->getIdentifier(), $accessToken->getExpiry()->getTimestamp(), $accessToken->getUserIdentifier(),
                    $accessToken->getClient()->getIdentifier(), StoredScopes::encode($accessToken->getScopes()), (int) $accessToken->isRevoked(), $grantId,
                ]);
        });
    }

    public function grantId(string $identifier): ?string
    {
        $query = $this->database->pdo->prepare('SELECT grant_id FROM oauth2_access_tokens WHERE identifier = ?');
        $query->execute([$identifier]);
        $grantId = $query->fetchColumn();

        return \is_string($grantId) ? $grantId : null;
    }

    public function clearExpired(): int
    {
        // A valid refresh token still needs its expired access token's holder and original connection.
        $query = $this->database->pdo->prepare('DELETE FROM oauth2_access_tokens WHERE expiry < ? AND identifier NOT IN (SELECT access_token FROM oauth2_refresh_tokens WHERE expiry >= ? AND revoked = 0 AND access_token IS NOT NULL)');
        $query->execute([time(), time()]);

        return $query->rowCount();
    }
}
