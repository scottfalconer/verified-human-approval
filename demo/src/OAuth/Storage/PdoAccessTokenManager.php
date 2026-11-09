<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth\Storage;

use League\Bundle\OAuth2ServerBundle\Manager\AccessTokenManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\AccessTokenInterface;
use VerifiedHumanApproval\Demo\DemoDatabase;

/** Issued access tokens, so league/oauth2-server can tell a revoked one. A token that is not here counts as revoked. */
final readonly class PdoAccessTokenManager implements AccessTokenManagerInterface
{
    public function __construct(private DemoDatabase $database, private PdoClientManager $clients)
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

        return (bool) $row['revoked'] ? $token->revoke() : $token;
    }

    public function save(AccessTokenInterface $accessToken): void
    {
        $this->database->pdo->prepare('INSERT INTO oauth2_access_tokens (identifier, expiry, user_identifier, client, scopes, revoked) VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(identifier) DO UPDATE SET expiry = excluded.expiry, revoked = excluded.revoked')
            ->execute([
                $accessToken->getIdentifier(), $accessToken->getExpiry()->getTimestamp(), $accessToken->getUserIdentifier(),
                $accessToken->getClient()->getIdentifier(), StoredScopes::encode($accessToken->getScopes()), (int) $accessToken->isRevoked(),
            ]);
    }

    public function clearExpired(): int
    {
        $query = $this->database->pdo->prepare('DELETE FROM oauth2_access_tokens WHERE expiry < ?');
        $query->execute([time()]);

        return $query->rowCount();
    }
}
