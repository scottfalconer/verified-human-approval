<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Open client registration (omouren/mcp-oauth-bundle's /oauth/register) has a budget per address, so nobody
 * can fill the client table. It runs after routing, before the registration controller.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RegistrationLimiter
{
    public function __construct(#[Target('demo_oauth_registration')] private RateLimiterFactoryInterface $limiter)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'mcp_oauth_register' !== $request->attributes->get('_route')) {
            return;
        }
        $limit = $this->limiter->create($request->getClientIp() ?? 'unknown')->consume();
        if ($limit->isAccepted()) {
            return;
        }
        $event->setResponse(new JsonResponse(
            ['error' => 'rate_limited', 'error_description' => 'Too many registrations from this address. Try again in a minute.'],
            429,
            ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Cache-Control' => 'no-store'],
        ));
    }
}
