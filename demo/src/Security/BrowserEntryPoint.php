<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\HttpUtils;

/** The demo's browser firewall tells the passkey panel when sign-in is required. */
final readonly class BrowserEntryPoint implements AuthenticationEntryPointInterface
{
    private const CEREMONY_ROUTES = [
        'verified_human_approval_options',
        'verified_human_approval_complete',
        'verified_human_approval_cancel',
        'demo_enrollment_options',
        'demo_enrollment_complete',
        'demo_confirm_options',
        'demo_confirm_complete',
    ];

    public function __construct(private HttpUtils $httpUtils)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($request->isMethod('POST') && 'json' === $request->getPreferredFormat()
            && \in_array($request->attributes->get('_route'), self::CEREMONY_ROUTES, true)) {
            return new JsonResponse(['status' => 'authentication_required'], Response::HTTP_UNAUTHORIZED, ['Cache-Control' => 'no-store']);
        }

        // Symfony has already saved the target for a browser navigation.
        return $this->httpUtils->createRedirectResponse($request, 'demo_login');
    }
}
