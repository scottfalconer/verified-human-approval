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
        $returnAddress = self::returnAddress($event);
        $scopes = self::scopes($event, $returnAddress->uri);
        $metadataUrl = $request->attributes->get(self::METADATA_URL);
        $metadataUrl = \is_string($metadataUrl) ? $metadataUrl : null;
        $binding = ConsentRequest::bind(
            $event->getUser()->getUserIdentifier(), $client->getIdentifier(), $metadataUrl, $returnAddress->uri, $scopes,
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
            \is_string($publisher) ? strtolower($publisher) : null, $returnAddress, $scopes,
        ));
        $event->setResponse(new RedirectResponse($this->urls->generate('mcp_oauth_consent')));
    }

    /**
     * Where the code goes: the request's redirect URI, or the client's only one, registered for the client and in
     * a plain form, so the host the page names is the one the person's browser sends the code to. Any other is
     * refused here, without a redirect and before anyone is asked.
     */
    private static function returnAddress(AuthorizationRequestResolveEvent $event): ReturnAddress
    {
        $registered = array_map(strval(...), $event->getClient()->getRedirectUris());
        $redirectUri = $event->getRedirectUri() ?? (1 === \count($registered) ? $registered[0] : '');
        if (!(new RedirectUriValidator($registered))->validateRedirectUri($redirectUri)) {
            throw OAuthServerException::invalidRequest('redirect_uri', 'The redirect URI is not registered for this client.');
        }

        return ReturnAddress::of($redirectUri) ?? throw OAuthServerException::invalidRequest('redirect_uri', ReturnAddress::NOT_PLAIN);
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
