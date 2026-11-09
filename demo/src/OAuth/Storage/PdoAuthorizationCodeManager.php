<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth\Storage;

use League\Bundle\OAuth2ServerBundle\Manager\AuthorizationCodeManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCodeInterface;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\OAuth\OAuthConnections;
use VerifiedHumanApproval\Demo\OAuth\OAuthTransaction;
use VerifiedHumanApproval\Demo\OAuth\TokenExchangeContext;

/** Authorization codes, so league/oauth2-server can use each one once. */
final readonly class PdoAuthorizationCodeManager implements AuthorizationCodeManagerInterface
{
    public function __construct(private DemoDatabase $database, private PdoClientManager $clients, private OAuthConnections $connections, private OAuthTransaction $transaction, private TokenExchangeContext $exchange)
    {
    }

    public function find(string $identifier): ?AuthorizationCodeInterface
    {
        $query = $this->database->pdo->prepare('SELECT * FROM oauth2_authorization_codes WHERE identifier = ?');
        $query->execute([$identifier]);
        $row = $query->fetch();
        $client = \is_array($row) ? $this->clients->find((string) $row['client']) : null;
        if (!\is_array($row) || null === $client) {
            return null;
        }
        $code = new AuthorizationCode($identifier, new \DateTimeImmutable('@'.$row['expiry']), $client, null === $row['user_identifier'] ? null : (string) $row['user_identifier'], StoredScopes::decode($row['scopes']));

        if ((bool) $row['revoked'] || (int) $row['expiry'] < time() || null === $this->connections->bound((string) $row['grant_id'], $code->getUserIdentifier(), $client->getIdentifier())) {
            return $code->revoke();
        }
        $this->exchange->bind((string) $row['grant_id']);

        return $code;
    }

    public function save(AuthorizationCodeInterface $authCode): void
    {
        $this->transaction->run(function () use ($authCode): void {
            // Holder, connection and expiry never change. Revocation cannot be undone by a stale model.
            $this->database->pdo->prepare('UPDATE oauth2_authorization_codes SET revoked = MAX(revoked, ?) WHERE identifier = ?')
                ->execute([(int) $authCode->isRevoked(), $authCode->getIdentifier()]);
        });
    }

    public function insert(AuthorizationCodeInterface $authCode, string $grantId): void
    {
        $this->transaction->assertActive();
        if (null === $this->connections->bound($grantId, $authCode->getUserIdentifier(), $authCode->getClient()->getIdentifier())) {
            throw new \LogicException('An authorization code needs its original, live connection.');
        }
        $this->database->pdo->prepare('INSERT INTO oauth2_authorization_codes (identifier, expiry, user_identifier, client, scopes, revoked, grant_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $authCode->getIdentifier(), $authCode->getExpiryDateTime()->getTimestamp(), $authCode->getUserIdentifier(),
                $authCode->getClient()->getIdentifier(), StoredScopes::encode($authCode->getScopes()), (int) $authCode->isRevoked(), $grantId,
            ]);
    }

    public function clearExpired(): int
    {
        $query = $this->database->pdo->prepare('DELETE FROM oauth2_authorization_codes WHERE expiry < ?');
        $query->execute([time()]);

        return $query->rowCount();
    }
}
