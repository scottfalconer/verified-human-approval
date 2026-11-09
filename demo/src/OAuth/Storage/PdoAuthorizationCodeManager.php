<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth\Storage;

use League\Bundle\OAuth2ServerBundle\Manager\AuthorizationCodeManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCodeInterface;
use VerifiedHumanApproval\Demo\DemoDatabase;

/** Authorization codes, so league/oauth2-server can use each one once. */
final readonly class PdoAuthorizationCodeManager implements AuthorizationCodeManagerInterface
{
    public function __construct(private DemoDatabase $database, private PdoClientManager $clients)
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

        return (bool) $row['revoked'] ? $code->revoke() : $code;
    }

    public function save(AuthorizationCodeInterface $authCode): void
    {
        $this->database->pdo->prepare('INSERT INTO oauth2_authorization_codes (identifier, expiry, user_identifier, client, scopes, revoked) VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(identifier) DO UPDATE SET expiry = excluded.expiry, revoked = excluded.revoked')
            ->execute([
                $authCode->getIdentifier(), $authCode->getExpiryDateTime()->getTimestamp(), $authCode->getUserIdentifier(),
                $authCode->getClient()->getIdentifier(), StoredScopes::encode($authCode->getScopes()), (int) $authCode->isRevoked(),
            ]);
    }

    public function clearExpired(): int
    {
        $query = $this->database->pdo->prepare('DELETE FROM oauth2_authorization_codes WHERE expiry < ?');
        $query->execute([time()]);

        return $query->rowCount();
    }
}
