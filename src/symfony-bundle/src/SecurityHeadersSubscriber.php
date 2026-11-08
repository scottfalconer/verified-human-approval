<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The review pages' headers: a strict content security policy, no framing, no caching and no referrer.
 *
 * A route opts in with the route default named by ROUTE_DEFAULT. The bundle's own routes carry it;
 * set it on your own pages that deserve the same treatment, such as passkey enrollment:
 *
 *     passkeys:
 *         path: /account/passkeys
 *         defaults: { _verified_human_approval_headers: true }
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public const ROUTE_DEFAULT = '_verified_human_approval_headers';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->attributes->getBoolean(self::ROUTE_DEFAULT)) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'");
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Cache-Control', 'no-store, private');
    }
}
