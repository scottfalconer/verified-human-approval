<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Controller;

use ExactApproval\Core\ApprovalException;
use ExactApproval\Symfony\ActorProvider;
use ExactApproval\WebAuthn\PdoCredentialRepository;
use ExactApproval\WebAuthn\WebAuthnVerifier;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Twig\Environment;

final readonly class AccountController
{
    public function __construct(
        private ActorProvider $actors,
        private WebAuthnVerifier $webauthn,
        private PdoCredentialRepository $credentials,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
        private Security $security,
        private RateLimiterFactoryInterface $limiter,
    ) {}

    public function login(AuthenticationUtils $authentication): Response
    {
        return new Response($this->twig->render('login.html.twig', ['error' => $authentication->getLastAuthenticationError(), 'last_username' => $authentication->getLastUsername()]));
    }

    public function passkeys(): Response
    {
        $actor = $this->actors->interactive();

        return new Response($this->twig->render('passkeys.html.twig', [
            'credentials' => $this->credentials->forActor($actor),
            'csrf' => $this->csrf->getToken('exact_approval.enrollment')->getValue(),
        ]));
    }

    public function enrollmentOptions(Request $request): JsonResponse
    {
        $this->assertCsrf($request);
        if (($limited = $this->limit()) !== null) return $limited;
        $actor = $this->actors->interactive();
        $options = $this->webauthn->registrationOptions($actor, random_bytes(32), $this->security->getUser()?->getUserIdentifier() ?? $actor->userId);
        $id = bin2hex(random_bytes(24));
        $request->getSession()->set('exact_approval.enrollment', [
            'id' => $id, 'user' => $actor->userId, 'tenant' => $actor->tenantId,
            'expires' => time() + 120, 'options' => $options,
        ]);

        return new JsonResponse(['ceremony_id' => $id, 'publicKey' => $options]);
    }

    public function enrollmentComplete(Request $request): JsonResponse
    {
        $this->assertCsrf($request);
        if (($limited = $this->limit()) !== null) return $limited;
        $actor = $this->actors->interactive();
        if (\strlen($request->getContent()) > 65_536) throw new BadRequestHttpException('Request is too large.');
        try { $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new BadRequestHttpException('Invalid response.'); }
        if (!\is_array($body) || !\is_string($body['ceremony_id'] ?? null) || !\is_array($body['credential'] ?? null)) throw new BadRequestHttpException('Invalid credential response.');
        $saved = $request->getSession()->get('exact_approval.enrollment');
        if (!\is_array($saved) || !hash_equals($saved['id'], $body['ceremony_id']) || $saved['user'] !== $actor->userId || $saved['tenant'] !== $actor->tenantId || $saved['expires'] < time()) {
            throw new AccessDeniedException('This enrollment expired. Start again from your account page.');
        }
        $request->getSession()->remove('exact_approval.enrollment');
        try {
            $this->webauthn->register($actor, $saved['options'], $body['credential'], 'Personal passkey');

            return new JsonResponse(['status' => 'registered', 'message' => 'Your passkey is registered for this account.']);
        } catch (ApprovalException $error) {
            return new JsonResponse(['status' => 'rejected', 'message' => $error->getMessage()], 400);
        }
    }

    private function assertCsrf(Request $request): void
    {
        $this->actors->interactive();
        if (!$this->csrf->isTokenValid(new CsrfToken('exact_approval.enrollment', (string) $request->headers->get('X-CSRF-Token', '')))) throw new AccessDeniedException('Invalid or missing CSRF token.');
    }

    private function limit(): ?JsonResponse
    {
        $actor = $this->actors->interactive();
        $limit = $this->limiter->create(hash('sha256', $actor->tenantId."\0".$actor->userId))->consume();
        if ($limit->isAccepted()) return null;

        return new JsonResponse(['status' => 'rate_limited', 'message' => 'Too many passkey enrollment attempts. Wait before trying again.'], 429,
            ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
    }
}
