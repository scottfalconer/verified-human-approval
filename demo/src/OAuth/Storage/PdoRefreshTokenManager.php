<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth\Storage;

use League\Bundle\OAuth2ServerBundle\Manager\RefreshTokenManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use League\Bundle\OAuth2ServerBundle\Model\RefreshTokenInterface;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\OAuth\OAuthConnections;
use VerifiedHumanApproval\Demo\OAuth\OAuthTransaction;
use VerifiedHumanApproval\Demo\OAuth\TokenExchangeContext;

/**
 * Refresh tokens, which league/oauth2-server rotates on every use. One whose connection was revoked, or has
 * reached its end, reads as revoked, so refreshing cannot keep a connection alive past its lifetime.
 */
final readonly class PdoRefreshTokenManager implements RefreshTokenManagerInterface
{
    public function __construct(private DemoDatabase $database, private PdoAccessTokenManager $accessTokens, private OAuthConnections $connections, private OAuthTransaction $transaction, private TokenExchangeContext $exchange)
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
        $connected = null !== $accessToken && !$accessToken->isRevoked()
            && $this->accessTokens->grantId($accessToken->getIdentifier()) === $row['grant_id']
            && null !== $this->connections->bound((string) $row['grant_id'], $accessToken->getUserIdentifier(), $accessToken->getClient()->getIdentifier());
        if ((bool) $row['revoked'] || (int) $row['expiry'] < time() || !$connected) {
            return $token->revoke();
        }
        $this->exchange->bind((string) $row['grant_id']);

        return $token;
    }

    public function save(RefreshTokenInterface $refreshToken): void
    {
        $this->transaction->run(function () use ($refreshToken): void {
            $query = $this->database->pdo->prepare('SELECT identifier FROM oauth2_refresh_tokens WHERE identifier = ?');
            $query->execute([$refreshToken->getIdentifier()]);
            if (false !== $query->fetchColumn()) {
                $this->database->pdo->prepare('UPDATE oauth2_refresh_tokens SET revoked = MAX(revoked, ?) WHERE identifier = ?')
                    ->execute([(int) $refreshToken->isRevoked(), $refreshToken->getIdentifier()]);

                return;
            }
            $access = $refreshToken->getAccessToken();
            if (null === $access) {
                throw new \LogicException('A refresh token needs its stored access token.');
            }
            $grantId = $this->exchange->grantFor($access->getUserIdentifier(), $access->getClient()->getIdentifier());
            if ($this->accessTokens->grantId($access->getIdentifier()) !== $grantId) {
                throw new \LogicException('A refresh token cannot change its access token’s original connection.');
            }
            $this->database->pdo->prepare('INSERT INTO oauth2_refresh_tokens (identifier, expiry, access_token, revoked, grant_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$refreshToken->getIdentifier(), $refreshToken->getExpiry()->getTimestamp(), $access->getIdentifier(), (int) $refreshToken->isRevoked(), $grantId]);
        });
    }

    public function clearExpired(): int
    {
        $query = $this->database->pdo->prepare('DELETE FROM oauth2_refresh_tokens WHERE expiry < ?');
        $query->execute([time()]);

        return $query->rowCount();
    }
}
