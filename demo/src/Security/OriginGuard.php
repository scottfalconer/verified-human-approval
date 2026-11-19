<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The demo answers only at the origin it was set up for: its passkeys belong to that origin, and every link it
 * gives an agent names it. Opened anywhere else, such as on another port, its pages would load and the passkey
 * would then fail, so the answer is instead where to open it. Behind the public tunnel the scheme and host are the
 * ones the trusted local proxy forwards. It runs before Symfony's request validation (256), so a host the demo
 * does not trust, such as 127.0.0.1 for localhost, gets the same answer instead of a bare 400.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 300)]
final readonly class OriginGuard
{
    public function __construct(#[Autowire('%env(DEMO_RUNTIME_ORIGIN)%')] private string $origin)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || self::openedAt($event->getRequest()) === self::address($this->origin)) {
            return;
        }
        $event->setResponse(new Response(
            "This demo runs at {$this->origin}. Open it there: its passkeys and the links it gives agents work only at that address.\n"
            ."To run it at another address, set it up for that one: see \"If port 8080 is taken\" in demo/README.md.\n",
            Response::HTTP_MISDIRECTED_REQUEST,
            ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'],
        ));
    }

    /** @return array{string, string, int}|null the scheme, host and port the request was sent to; null for a host this demo does not trust */
    private static function openedAt(Request $request): ?array
    {
        try {
            return [$request->getScheme(), $request->getHost(), (int) $request->getPort()];
        } catch (SuspiciousOperationException) {
            return null;
        }
    }

    /** @return array{string, string, int} */
    private static function address(string $origin): array
    {
        $parts = parse_url($origin) ?: [];
        $scheme = strtolower($parts['scheme'] ?? '');

        return [$scheme, strtolower($parts['host'] ?? ''), $parts['port'] ?? ('https' === $scheme ? 443 : 80)];
    }
}
