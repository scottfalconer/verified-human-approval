<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use Omouren\McpOauthBundle\McpOauthSettings;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Open registration (omouren/mcp-oauth-bundle's /oauth/register), where a runtime offers it, takes only a redirect
 * URI whose host the consent page can name (ReturnAddress): on https or back to this computer, in plain form. The
 * controller would also take an application's own link scheme. ConsentGate checks every authorization again, for
 * clients named by a client ID metadata document. It runs after the registration budget, before the controller.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
final readonly class RegistrationRedirectGuard
{
    public function __construct(private McpOauthSettings $settings)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->settings->dcrEnabled || 'mcp_oauth_register' !== $request->attributes->get('_route')) {
            return;
        }
        // The controller answers a body it cannot read; this looks only at the redirect URIs it would store.
        $metadata = json_decode($request->getContent(), true);
        foreach (\is_array($metadata) && \is_array($metadata['redirect_uris'] ?? null) ? $metadata['redirect_uris'] : [] as $redirectUri) {
            if (\is_string($redirectUri) && null === ReturnAddress::of($redirectUri)) {
                $event->setResponse(new JsonResponse(['error' => 'invalid_redirect_uri', 'error_description' => ReturnAddress::REFUSED], 400, ['Cache-Control' => 'no-store']));

                return;
            }
        }
    }
}
