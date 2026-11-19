<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * league/oauth2-server answers an authorization request for a scope it does not know by sending the person to the
 * client's redirect URI with invalid_scope, before the consent step runs. Where the server does not trust that
 * address (ConsentGate::trustsReturnAddress()), this listener gives the same error first, without a redirect. It
 * does so too for a request that names no redirect URI: it does not look up the one League would use.
 *
 * It runs after the listener that resolves a client ID metadata document (16), the firewall (8) and the provider's
 * sign-in redirect (7), so the person has signed in before anything is answered.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final readonly class UnknownScopeGuard
{
    public function __construct(private ScopeRepositoryInterface $scopes)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'oauth2_authorize' !== $request->attributes->get('_route')) {
            return;
        }
        $query = $request->query->all();
        // Read as League reads it: a list separated by spaces. A value that is not a string League refuses itself,
        // without a redirect.
        $scope = $query['scope'] ?? '';
        $unknown = \is_string($scope) ? array_filter(explode(' ', trim($scope)), fn (string $name): bool => '' !== $name && null === $this->scopes->getScopeEntityByIdentifier($name)) : [];
        $redirectUri = $query['redirect_uri'] ?? null;
        if ([] === $unknown || ConsentGate::trustsReturnAddress($request, \is_string($redirectUri) ? ReturnAddress::of($redirectUri) : null)) {
            return;
        }
        $error = OAuthServerException::invalidScope(implode(' ', $unknown));
        $event->setResponse(new JsonResponse($error->getPayload(), $error->getHttpStatusCode(), ['Cache-Control' => 'no-store']));
    }
}
