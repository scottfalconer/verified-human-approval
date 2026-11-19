<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The consent step of every authorization, in place of omouren/mcp-oauth-bundle's ConsentListener: that page shows
 * what its link says, and its Allow is a session flag the next authorization request passes, whatever it asks.
 * This one asks the person about the request league/oauth2-server has just validated, and approves only that
 * request, when it comes back after their Allow.
 */
final readonly class ConsentGate
{
    /** The request attribute in which the provider's listener names a client ID metadata document it resolved. */
    private const METADATA_URL = 'mcp_oauth.original_client_id';

    public function __construct(private RequestStack $requests, private ConsentSession $consents, private UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $request = $this->requests->getCurrentRequest() ?? throw new \LogicException('An authorization is answered within its own request.');
        $client = $event->getClient();
        $redirectUri = self::redirectUri($event);
        $scopes = self::scopes($event, $redirectUri);
        $metadataUrl = $request->attributes->get(self::METADATA_URL);
        $metadataUrl = \is_string($metadataUrl) ? $metadataUrl : null;
        $binding = ConsentRequest::bind(
            $event->getUser()->getUserIdentifier(), $client->getIdentifier(), $metadataUrl, $redirectUri, $scopes,
            $request->query->getString('resource'), $event->getState(), $event->getCodeChallenge(), $event->getCodeChallengeMethod(),
        );
        $session = $request->getSession();
        if ($this->consents->take($session, $binding)) {
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);

            return;
        }
        $publisher = null === $metadataUrl ? null : parse_url($metadataUrl, \PHP_URL_HOST);
        // Resuming repeats the request exactly as the client sent it, so the provider validates it again.
        $this->consents->ask($session, new ConsentRequest(
            bin2hex(random_bytes(16)), $binding, $request->getRequestUri(), $client->getName(),
            \is_string($publisher) ? strtolower($publisher) : null, $redirectUri, $scopes,
        ));
        $event->setResponse(new RedirectResponse($this->urls->generate('mcp_oauth_consent')));
    }

    /** The request's redirect URI, or the client's only one, checked against those registered for the client. */
    private static function redirectUri(AuthorizationRequestResolveEvent $event): string
    {
        $registered = array_map(strval(...), $event->getClient()->getRedirectUris());
        $redirectUri = $event->getRedirectUri() ?? (1 === \count($registered) ? $registered[0] : '');
        if (!(new RedirectUriValidator($registered))->validateRedirectUri($redirectUri)) {
            throw OAuthServerException::invalidRequest('redirect_uri', 'The redirect URI is not registered for this client.');
        }

        return $redirectUri;
    }

    /**
     * What the token will carry. A request for a scope the client was not registered with would fail at the token
     * endpoint, so it goes back to the client now instead of being put to the person.
     *
     * @return list<string>
     */
    private static function scopes(AuthorizationRequestResolveEvent $event, string $redirectUri): array
    {
        $own = array_map(strval(...), $event->getClient()->getScopes());
        $asked = array_values(array_map(strval(...), $event->getScopes()));
        $beyond = [] === $own ? [] : array_diff($asked, $own);
        if ([] !== $beyond) {
            $state = $event->getState();
            throw OAuthServerException::invalidScope(implode(' ', $beyond), null === $state ? $redirectUri : $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query(['state' => $state]));
        }
        $scopes = ConsentRequest::grantedScopes($asked, $own);
        if ([] === $scopes) {
            throw OAuthServerException::invalidRequest('scope', 'This client has no scope to ask for.');
        }

        return $scopes;
    }
}
