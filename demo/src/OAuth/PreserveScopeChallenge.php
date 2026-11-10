<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

use Omouren\McpOauthBundle\EventListener\WwwAuthenticateListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/** Keep the SDK policy's per-operation challenge; let the provider supply challenges where none exists. */
final readonly class PreserveScopeChallenge
{
    public function __construct(private WwwAuthenticateListener $inner)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->getResponse()->headers->has('WWW-Authenticate')) {
            ($this->inner)($event);
        }
    }
}
