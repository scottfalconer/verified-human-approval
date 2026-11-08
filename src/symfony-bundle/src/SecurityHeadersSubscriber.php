<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return [KernelEvents::RESPONSE => 'onResponse']; }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with((string) $event->getRequest()->attributes->get('_route'), 'verified_human_approval_')) {
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
