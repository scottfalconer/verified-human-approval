<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Bundle\SecurityHeadersSubscriber;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Example\Publishing\Account\PasskeyEnrollment;
use VerifiedHumanApproval\Example\Publishing\Entity\User;
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

/**
 * Signing in, and adding the passkey that approves. The passkey pages need the signed-in person and, for a first
 * passkey, the one-time link setup printed for them. The browser runs the registration with the bundle's
 * approval.js; this controller keeps the options it issued in the session and accepts only those back.
 */
#[Route(defaults: [SecurityHeadersSubscriber::ROUTE_DEFAULT => true])]
final class AccountController extends AbstractController
{
    private const LINK = 'publishing.passkey_link';
    private const CEREMONY = 'publishing.passkey_registration';
    private const CSRF = 'publishing.passkey';

    public function __construct(
        private readonly ActorProvider $actors,
        private readonly WebAuthnVerifier $webauthn,
        private readonly AnchoredCredentialRepository $passkeys,
        private readonly PasskeyEnrollment $enrollment,
    ) {
    }

    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authentication): Response
    {
        return $this->render('account/login.html.twig', ['error' => $authentication->getLastAuthenticationError(), 'last_username' => $authentication->getLastUsername()]);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall signs the person out.');
    }

    #[Route('/account/passkey', name: 'passkey', methods: ['GET'])]
    public function passkey(Request $request): Response
    {
        $actor = $this->actors->interactive();

        return $this->render('account/passkey.html.twig', [
            'passkeys' => $this->passkeys->forActor($actor),
            'can_register' => null !== $this->link($request, $actor),
            'csrf_id' => self::CSRF,
        ]);
    }

    /** The link setup printed: opened while signed in, it lets this person register one passkey in the next ten minutes. */
    #[Route('/account/passkey/link/{token}', name: 'passkey_link', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{43}'])]
    public function openLink(Request $request, string $token): Response
    {
        $actor = $this->actors->interactive();
        $link = $this->enrollment->openLink($actor, $token);
        if (null === $link) {
            return $this->render('account/link_invalid.html.twig', [], new Response('', Response::HTTP_GONE));
        }
        $request->getSession()->set(self::LINK, ['user' => $actor->userId, 'link' => $link, 'until' => time() + 600]);

        return $this->redirectToRoute('passkey');
    }

    #[Route('/account/passkey/options', name: 'passkey_options', methods: ['POST'])]
    public function options(Request $request): JsonResponse
    {
        $actor = $this->actors->interactive();
        $this->assertCsrf($request);
        $link = $this->link($request, $actor) ?? throw $this->createAccessDeniedException('Open your one-time passkey link first, while signed in.');
        $user = $this->getUser();
        $options = $this->webauthn->registrationOptions($actor, random_bytes(32), $user instanceof User ? $user->displayName() : $actor->userId);
        $id = bin2hex(random_bytes(16));
        $request->getSession()->set(self::CEREMONY, ['id' => $id, 'user' => $actor->userId, 'link' => $link, 'until' => time() + 120, 'options' => $options]);

        return new JsonResponse(['ceremony_id' => $id, 'publicKey' => $options]);
    }

    #[Route('/account/passkey/complete', name: 'passkey_complete', methods: ['POST'])]
    public function complete(Request $request): JsonResponse
    {
        $actor = $this->actors->interactive();
        $this->assertCsrf($request);
        $body = \strlen($request->getContent()) <= 65_536 ? json_decode($request->getContent(), true) : null;
        $saved = $request->getSession()->get(self::CEREMONY);
        $request->getSession()->remove(self::CEREMONY);
        if (!\is_array($body) || !\is_string($body['ceremony_id'] ?? null) || !\is_array($body['credential'] ?? null)
            || !\is_array($saved) || !hash_equals($saved['id'], $body['ceremony_id']) || $saved['user'] !== $actor->userId || $saved['until'] < time()) {
            return new JsonResponse(['status' => 'rejected', 'message' => 'This registration expired. Start again from this page.'], 400);
        }
        try {
            $this->enrollment->complete($actor, $saved['options'], $body['credential'], $saved['link']);
        } catch (ApprovalException $error) {
            return new JsonResponse(['status' => 'rejected', 'message' => $error->getMessage()], 400);
        }
        $request->getSession()->remove(self::LINK);

        return new JsonResponse(['status' => 'registered', 'message' => 'Your passkey is registered: it can approve changes now.']);
    }

    /** The link this person opened in this session, if it is still theirs to use. */
    private function link(Request $request, Actor $actor): ?string
    {
        $link = $request->getSession()->get(self::LINK);

        return \is_array($link) && $link['user'] === $actor->userId && $link['until'] >= time() && \is_string($link['link']) ? $link['link'] : null;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token', ''))) {
            throw $this->createAccessDeniedException('This page expired. Reload it and try again.');
        }
    }
}
