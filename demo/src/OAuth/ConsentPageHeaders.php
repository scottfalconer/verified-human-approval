<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The consent and authorize pages can never be framed, so nobody can trick a click on "Allow", and are never
 * cached. Deliberately not the review pages' full policy: its form-action 'self' would stop the browser following
 * the authorization's final redirect back to the client.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class ConsentPageHeaders
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !\in_array($event->getRequest()->attributes->get('_route'), ['mcp_oauth_consent', 'oauth2_authorize'], true)) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Cache-Control', 'no-store, private');
    }
}
