<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use League\Bundle\OAuth2ServerBundle\Manager\AuthorizationCodeManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCodeInterface;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Demo\OAuth\Storage\PdoAuthorizationCodeManager;

/**
 * league/oauth2-server issues an authorization code only after the person allowed the client on the consent page,
 * bound to that person, client and scopes. Its first save is therefore when the agent connection its tokens will
 * belong to is opened. A listener on the authorization event cannot do this, because approving stops that event.
 */
final readonly class ConnectionOpeningCodeManager implements AuthorizationCodeManagerInterface
{
    public function __construct(private PdoAuthorizationCodeManager $codes, private OAuthConnections $connections, private DemoDatabase $database, private OAuthTransaction $transaction)
    {
    }

    public function find(string $identifier): ?AuthorizationCodeInterface
    {
        return $this->codes->find($identifier);
    }

    public function save(AuthorizationCodeInterface $authCode): void
    {
        $this->transaction->run(function () use ($authCode): void {
            if (null !== $this->codes->find($authCode->getIdentifier())) {
                $this->codes->save($authCode);

                return;
            }
            $user = null === $authCode->getUserIdentifier() ? null : $this->database->user($authCode->getUserIdentifier());
            if ($authCode->isRevoked() || null === $user || !(bool) $user['enabled']) {
                throw new \LogicException('A new authorization code needs an enabled person.');
            }
            $client = $authCode->getClient();
            // A client that names no scope gets its own, as its token will and as the consent page told the person.
            $scopes = ConsentRequest::grantedScopes(array_map(strval(...), $authCode->getScopes()), array_map(strval(...), $client->getScopes()));
            // Retire old credentials first. Saving this code first would revoke it along with the old ones.
            $grantId = $this->connections->open((string) $user['id'], (string) $user['tenant_id'], $client->getIdentifier(), $client->getName(), $scopes);
            $this->codes->insert($authCode, $grantId);
        });
    }

    public function clearExpired(): int
    {
        return $this->codes->clearExpired();
    }
}
