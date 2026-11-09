<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use VerifiedHumanApproval\Demo\Security\DemoUser;

/**
 * When the person allows a client on the consent page (omouren/mcp-oauth-bundle resolves the request), this
 * opens the agent connection its tokens will belong to. It runs after the consent listener has decided.
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE, priority: -16)]
final readonly class OAuthConsentListener
{
    public function __construct(private OAuthConnections $connections)
    {
    }

    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $user = $event->getUser();
        // Only a person signed in on this site can connect a client; an agent's own token never can.
        if (!$event->getAuthorizationResolution() || !$user instanceof DemoUser || null !== $user->delegationId) {
            return;
        }
        $client = $event->getClient();
        $this->connections->open($user->id, $user->tenantId, $client->getIdentifier(), $client->getName(), array_values(array_map('strval', $event->getScopes())));
    }
}
