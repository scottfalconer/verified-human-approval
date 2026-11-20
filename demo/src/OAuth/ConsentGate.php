<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * The consent step of every authorization, in place of omouren/mcp-oauth-bundle's ConsentListener: that page shows
 * what its link says, and its Allow is a session flag the next authorization request passes, whatever it asks.
 * This one asks the person about the request league/oauth2-server has just validated, and answers only that
 * request, when it comes back after their Allow or Don't allow.
 */
final readonly class ConsentGate
{
    /** The request attribute in which the provider's listener names a client ID metadata document it resolved. */
    private const METADATA_URL = 'mcp_oauth.original_client_id';

    public function __construct(private RequestStack $requests, private ConsentSession $consents, private UrlGeneratorInterface $urls, private Environment $twig)
    {
    }

    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $request = $this->requests->getCurrentRequest() ?? throw new \LogicException('An authorization is answered within its own request.');
        $client = $event->getClient();
        $returnAddress = self::returnAddress($event);
        $trusted = self::trustsReturnAddress($request, $returnAddress);
        $scopes = self::scopes($event, $trusted ? $returnAddress->uri : null);
        $metadataUrl = $request->attributes->get(self::METADATA_URL);
        $metadataUrl = \is_string($metadataUrl) ? $metadataUrl : null;
        $binding = ConsentRequest::bind(
            $event->getUser()->getUserIdentifier(), $client->getIdentifier(), $metadataUrl, $returnAddress->uri, $scopes,
            $request->query->getString('resource'), $event->getState(), $event->getCodeChallenge(), $event->getCodeChallengeMethod(),
        );
        $session = $request->getSession();
        $allowed = $this->consents->take($session, $binding);
        if (false === $allowed && !$trusted) {
            // The person refused a client whose return address the server does not trust:
            // they hear of it here, not there.
            $event->setResponse(new Response($this->twig->render('consent_refused.html.twig', ['returns' => $returnAddress])));

            return;
        }
        if (null !== $allowed) {
            // league/oauth2-server answers any other refusal itself: it sends the person back to this redirect URI with
            // access_denied and the request's state (RFC 6749 §4.1.2.1), and the provider adds its issuer (RFC 9207).
            $event->resolveAuthorization($allowed ? AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED : AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED);

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
     * Whether the server may send the person to this return address on its own, with an error or their refusal.
     * RFC 9700 §4.11.2 asks it to only where it trusts the address: one that a client ID metadata document from a
     * trusted host gives, or one on this computer. A client that registered itself chose its own, which could be
     * anyone's.
     */
    public static function trustsReturnAddress(Request $request, ?ReturnAddress $returnAddress): bool
    {
        return \is_string($request->attributes->get(self::METADATA_URL)) || true === $returnAddress?->thisComputer;
    }

    /**
     * Where the code goes: the request's redirect URI, or the client's only one, registered for the client, on https
     * or back to this computer, and in a plain form, so the host the page names is the one the person's browser sends
     * the code to. Any other is refused here, without a redirect and before anyone is asked.
     */
    private static function returnAddress(AuthorizationRequestResolveEvent $event): ReturnAddress
    {
        $registered = array_map(strval(...), $event->getClient()->getRedirectUris());
        $redirectUri = $event->getRedirectUri() ?? (1 === \count($registered) ? $registered[0] : '');
        if (!(new RedirectUriValidator($registered))->validateRedirectUri($redirectUri)) {
            throw OAuthServerException::invalidRequest('redirect_uri', 'The redirect URI is not registered for this client.');
        }

        return ReturnAddress::of($redirectUri) ?? throw OAuthServerException::invalidRequest('redirect_uri', ReturnAddress::REFUSED);
    }

    /**
     * What the token will carry. A request for a scope the client was not registered with would fail at the token
     * endpoint, so it is refused now instead of being put to the person: by a redirect to $errorTo, the return
     * address when the server trusts it, or else here, without one.
     *
     * @return list<string>
     */
    private static function scopes(AuthorizationRequestResolveEvent $event, ?string $errorTo): array
    {
        $own = array_map(strval(...), $event->getClient()->getScopes());
        $asked = array_values(array_map(strval(...), $event->getScopes()));
        $beyond = [] === $own ? [] : array_diff($asked, $own);
        if ([] !== $beyond) {
            $state = $event->getState();
            if (null !== $errorTo && null !== $state) {
                $errorTo .= (str_contains($errorTo, '?') ? '&' : '?').http_build_query(['state' => $state]);
            }
            throw OAuthServerException::invalidScope(implode(' ', $beyond), $errorTo);
        }
        $scopes = ConsentRequest::grantedScopes($asked, $own);
        if ([] === $scopes) {
            throw OAuthServerException::invalidRequest('scope', 'This client has no scope to ask for.');
        }

        return $scopes;
    }
}
