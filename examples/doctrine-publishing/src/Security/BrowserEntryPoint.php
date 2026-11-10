<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/** Host-owned browser authentication: JSON for a ceremony, the ordinary login page for navigation. */
final readonly class BrowserEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ('json' === $request->getPreferredFormat()) {
            return new JsonResponse(['status' => 'authentication_required'], Response::HTTP_UNAUTHORIZED, ['Cache-Control' => 'no-store']);
        }

        return new RedirectResponse($this->urls->generate('login'));
    }
}
