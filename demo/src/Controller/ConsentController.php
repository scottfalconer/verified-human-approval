<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use VerifiedHumanApproval\Demo\OAuth\ConsentSession;

/**
 * The consent page, at the OAuth provider's consent route. It shows the authorization request waiting in the
 * person's session (OAuth\ConsentGate put it there) and nothing its link says; Allow approves that request.
 */
#[AsController]
final readonly class ConsentController
{
    private const CSRF_ID = 'oauth_consent';

    public function __construct(private ConsentSession $consents, private CsrfTokenManagerInterface $csrf, private Environment $twig)
    {
    }

    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $request->request->getString('_csrf_token')))) {
                throw new AccessDeniedException('Invalid CSRF token.');
            }
            $resume = $this->consents->allow($session, $request->request->getString('consent'));

            return null === $resume ? $this->unavailable() : new RedirectResponse($resume);
        }
        $waiting = $this->consents->waiting($session);

        return null === $waiting ? $this->unavailable() : new Response($this->twig->render('consent.html.twig', ['consent' => $waiting, 'csrf_token' => $this->csrf->getToken(self::CSRF_ID)->getValue()]));
    }

    private function unavailable(): Response
    {
        return new Response($this->twig->render('consent_unavailable.html.twig'), 410);
    }
}
