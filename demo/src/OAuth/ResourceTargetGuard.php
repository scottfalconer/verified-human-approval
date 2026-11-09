<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * RFC 8707: this server issues tokens only for its own MCP endpoint. omouren/mcp-oauth-bundle binds tokens to that
 * resource but treats a request naming another one as "not MCP" and lets it through; this refuses it instead.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class ResourceTargetGuard
{
    public function __construct(#[Autowire('%env(DEMO_RUNTIME_ORIGIN)%')] private string $origin)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !\in_array($request->attributes->get('_route'), ['oauth2_authorize', 'oauth2_token'], true)) {
            return;
        }
        $resource = $request->query->all()['resource'] ?? $request->request->all()['resource'] ?? null;
        if (null === $resource || (\is_string($resource) && \in_array(rtrim($resource, '/'), [$this->origin.'/mcp', $this->origin], true))) {
            return;
        }
        $event->setResponse(new JsonResponse(['error' => 'invalid_target', 'error_description' => 'This server only issues tokens for its own MCP endpoint.'], 400, ['Cache-Control' => 'no-store']));
    }
}
