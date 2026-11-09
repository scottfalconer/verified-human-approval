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
    public function __construct(private PdoAuthorizationCodeManager $codes, private OAuthConnections $connections, private DemoDatabase $database)
    {
    }

    public function find(string $identifier): ?AuthorizationCodeInterface
    {
        return $this->codes->find($identifier);
    }

    public function save(AuthorizationCodeInterface $authCode): void
    {
        $new = !$authCode->isRevoked() && null === $this->codes->find($authCode->getIdentifier());
        $this->codes->save($authCode);
        $user = null === $authCode->getUserIdentifier() ? null : $this->database->user($authCode->getUserIdentifier());
        if ($new && null !== $user) {
            $client = $authCode->getClient();
            // A client that names no scope gets the default ones, all of them, as the consent page told the person.
            $scopes = array_map('strval', $authCode->getScopes()) ?: array_keys(ConsentExtension::SCOPE_DESCRIPTIONS);
            $this->connections->open((string) $user['id'], (string) $user['tenant_id'], $client->getIdentifier(), $client->getName(), $scopes);
        }
    }

    public function clearExpired(): int
    {
        return $this->codes->clearExpired();
    }
}
