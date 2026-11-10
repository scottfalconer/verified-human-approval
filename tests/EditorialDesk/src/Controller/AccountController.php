<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\PasskeyEnrollment;
use App\Security\Account;
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
use VerifiedHumanApproval\WebAuthn\AnchoredCredentialRepository;
use VerifiedHumanApproval\WebAuthn\WebAuthnVerifier;

#[Route(defaults: [SecurityHeadersSubscriber::ROUTE_DEFAULT => true])]
final class AccountController extends AbstractController
{
    private const LINK = 'editorial.registration_link';
    private const CEREMONY = 'editorial.registration_ceremony';
    private const CSRF = 'editorial.registration';

    public function __construct(
        private readonly ActorProvider $actors,
        private readonly WebAuthnVerifier $webauthn,
        private readonly AnchoredCredentialRepository $credentials,
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
        throw new \LogicException('The Security firewall handles logout.');
    }

    #[Route('/account/passkeys', name: 'passkeys', methods: ['GET'])]
    public function passkeys(Request $request): Response
    {
        $actor = $this->actors->interactive();

        return $this->render('account/passkeys.html.twig', ['passkeys' => $this->credentials->forActor($actor), 'can_register' => null !== $this->link($request, $actor), 'csrf_id' => self::CSRF]);
    }

    #[Route('/account/passkeys/link/{token}', name: 'passkey_link', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET'])]
    public function openLink(Request $request, string $token): Response
    {
        $actor = $this->actors->interactive();
        $hash = $this->enrollment->openLink($actor, $token);
        if (null === $hash) {
            return $this->render('account/link_expired.html.twig', [], new Response('', 410));
        }
        $request->getSession()->set(self::LINK, ['user' => $actor->userId, 'hash' => $hash, 'until' => time() + 600]);

        return $this->redirectToRoute('passkeys');
    }

    #[Route('/account/passkeys/options', name: 'passkey_options', methods: ['POST'])]
    public function options(Request $request): JsonResponse
    {
        $actor = $this->actors->interactive();
        $this->assertCsrf($request);
        $link = $this->link($request, $actor) ?? throw $this->createAccessDeniedException('Open your operator-issued registration link first.');
        $account = $this->getUser();
        $options = $this->webauthn->registrationOptions($actor, random_bytes(32), $account instanceof Account ? $account->displayName() : $actor->userId);
        $id = bin2hex(random_bytes(16));
        $request->getSession()->set(self::CEREMONY, ['id' => $id, 'user' => $actor->userId, 'link' => $link, 'until' => time() + 120, 'options' => $options]);

        return new JsonResponse(['ceremony_id' => $id, 'publicKey' => $options]);
    }

    #[Route('/account/passkeys/complete', name: 'passkey_complete', methods: ['POST'])]
    public function complete(Request $request): JsonResponse
    {
        $actor = $this->actors->interactive();
        $this->assertCsrf($request);
        $body = \strlen($request->getContent()) <= 65536 ? json_decode($request->getContent(), true) : null;
        $saved = $request->getSession()->get(self::CEREMONY);
        $request->getSession()->remove(self::CEREMONY);
        if (!\is_array($saved) || !\is_array($body) || !\is_string($body['ceremony_id'] ?? null) || !\is_array($body['credential'] ?? null)
            || !hash_equals($saved['id'], $body['ceremony_id']) || $saved['user'] !== $actor->userId || $saved['until'] < time()) {
            return new JsonResponse(['status' => 'rejected', 'message' => 'The registration ceremony expired. Please start again.'], 400);
        }
        try {
            $this->enrollment->complete($actor, $saved['options'], $body['credential'], $saved['link']);
        } catch (ApprovalException $error) {
            return new JsonResponse(['status' => 'rejected', 'reason' => $error->reason, 'message' => $error->getMessage()], 400);
        }
        $request->getSession()->remove(self::LINK);

        return new JsonResponse(['status' => 'registered', 'message' => 'Your passkey is ready to approve changes.']);
    }

    private function link(Request $request, Actor $actor): ?string
    {
        $link = $request->getSession()->get(self::LINK);

        return \is_array($link) && ($link['user'] ?? null) === $actor->userId && ($link['until'] ?? 0) >= time() && \is_string($link['hash'] ?? null) ? $link['hash'] : null;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token', ''))) {
            throw $this->createAccessDeniedException('Reload this page before continuing.');
        }
    }
}
