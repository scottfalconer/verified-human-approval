<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/** The host owns login and tells its ceremony UI when a new session is needed. */
final readonly class BrowserEntryPoint implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $ceremony = 1 === preg_match('#^/(?:approval/apr_[a-f0-9]{32}|account/passkeys)/(?:options|complete)$#D', $request->getPathInfo());
        if ($request->isMethod('POST') && $ceremony && str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['status' => 'authentication_required'], Response::HTTP_UNAUTHORIZED, ['Cache-Control' => 'no-store']);
        }
        if ($request->isMethodSafe() && $request->hasSession()) {
            $this->saveTargetPath($request->getSession(), 'main', $request->getUri());
        }

        return new RedirectResponse($this->urls->generate('login'));
    }
}
