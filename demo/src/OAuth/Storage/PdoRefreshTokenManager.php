<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth\Storage;

use League\Bundle\OAuth2ServerBundle\Manager\RefreshTokenManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use League\Bundle\OAuth2ServerBundle\Model\RefreshTokenInterface;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\OAuth\OAuthConnections;

/**
 * Refresh tokens, which league/oauth2-server rotates on every use. One whose connection was revoked, or has
 * reached its end, reads as revoked, so refreshing cannot keep a connection alive past its lifetime.
 */
final readonly class PdoRefreshTokenManager implements RefreshTokenManagerInterface
{
    public function __construct(private DemoDatabase $database, private PdoAccessTokenManager $accessTokens, private OAuthConnections $connections)
    {
    }

    public function find(string $identifier): ?RefreshTokenInterface
    {
        $query = $this->database->pdo->prepare('SELECT * FROM oauth2_refresh_tokens WHERE identifier = ?');
        $query->execute([$identifier]);
        $row = $query->fetch();
        if (!\is_array($row)) {
            return null;
        }
        $accessToken = null === $row['access_token'] ? null : $this->accessTokens->find((string) $row['access_token']);
        $token = new RefreshToken($identifier, new \DateTimeImmutable('@'.$row['expiry']), $accessToken);
        $connected = null !== $accessToken && null !== $this->connections->active($accessToken->getUserIdentifier(), $accessToken->getClient()->getIdentifier());

        return (bool) $row['revoked'] || !$connected ? $token->revoke() : $token;
    }

    public function save(RefreshTokenInterface $refreshToken): void
    {
        $this->database->pdo->prepare('INSERT INTO oauth2_refresh_tokens (identifier, expiry, access_token, revoked) VALUES (?, ?, ?, ?)
            ON CONFLICT(identifier) DO UPDATE SET expiry = excluded.expiry, revoked = excluded.revoked')
            ->execute([$refreshToken->getIdentifier(), $refreshToken->getExpiry()->getTimestamp(), $refreshToken->getAccessToken()?->getIdentifier(), (int) $refreshToken->isRevoked()]);
    }

    public function clearExpired(): int
    {
        $query = $this->database->pdo->prepare('DELETE FROM oauth2_refresh_tokens WHERE expiry < ?');
        $query->execute([time()]);

        return $query->rowCount();
    }
}
