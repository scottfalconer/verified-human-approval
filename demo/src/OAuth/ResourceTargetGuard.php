<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use Omouren\McpOauthBundle\OAuth\CanonicalResourceUri;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * RFC 8707: this server's only resource is its MCP endpoint. omouren/mcp-oauth-bundle applies its consent page,
 * issuer parameter and token binding to a request that names that endpoint (or one of its scopes), and lets any
 * other through. So a request that names another resource is refused here, and one that names none, or names
 * this server's origin, is taken to name the MCP endpoint, the default RFC 8707 §2 allows.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class ResourceTargetGuard
{
    public function __construct(
        #[Autowire('%env(DEMO_RUNTIME_ORIGIN)%')] private string $origin,
        private CanonicalResourceUri $uris = new CanonicalResourceUri(),
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!$event->isMainRequest() || !\in_array($route, ['oauth2_authorize', 'oauth2_token'], true)) {
            return;
        }
        $resource = $request->query->all()['resource'] ?? $request->request->all()['resource'] ?? null;
        if (\is_string($resource) && $this->uris->matches($this->origin.'/mcp', $resource)) {
            return;
        }
        if (null === $resource || '' === $resource || (\is_string($resource) && $this->uris->matches($this->origin, $resource))) {
            ('oauth2_token' === $route ? $request->request : $request->query)->set('resource', $this->origin.'/mcp');

            return;
        }
        $event->setResponse(new JsonResponse(['error' => 'invalid_target', 'error_description' => 'This server only issues tokens for its own MCP endpoint.'], 400, ['Cache-Control' => 'no-store']));
    }
}
