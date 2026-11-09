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
 *
 * The policy is ContentSecurityPolicy's: the sources the bundle's content_security_policy configuration adds, and
 * the response's nonce, which templates read with verified_human_approval_csp_nonce().
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public const ROUTE_DEFAULT = '_verified_human_approval_headers';

    public function __construct(private readonly ContentSecurityPolicy $policy = new ContentSecurityPolicy())
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->attributes->getBoolean(self::ROUTE_DEFAULT)) {
            return;
        }
        $headers = $event->getResponse()->headers;
        // The nonce the page was rendered with, or a fresh one when nothing on the page asked for it.
        $headers->set('Content-Security-Policy', $this->policy->header($request));
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Cache-Control', 'no-store, private');
    }
}
