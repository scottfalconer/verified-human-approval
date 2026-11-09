<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Sensitive approval screens must not be framed, cached, or leak grant URLs. */
final class SecurityHeadersSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -100]];
  }

  public function onResponse(ResponseEvent $event): void {
    if (!$event->getRequest()->attributes->get('_verified_human_approval_sensitive')) {
      return;
    }
    $headers = $event->getResponse()->headers;
    $headers->set('Cache-Control', 'private, no-store, max-age=0');
    $headers->set('Referrer-Policy', 'no-referrer');
    $headers->set('X-Content-Type-Options', 'nosniff');
    $headers->set('X-Frame-Options', 'DENY');
    // Multiple CSP policies are intersected by the browser; preserve the site's
    // full existing policy and add this narrowly scoped frame restriction.
    $headers->set('Content-Security-Policy', "frame-ancestors 'none'", FALSE);
  }

}

