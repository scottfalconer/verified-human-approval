<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Controller;

use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\Audit\AuditLog;
use ExactApproval\Core\ChallengeBinding;
use ExactApproval\Demo\DemoDatabase;
use ExactApproval\Demo\Security\Authenticators;
use ExactApproval\Symfony\ActorProvider;
use ExactApproval\WebAuthn\AnchoredCredentialRepository;
use ExactApproval\WebAuthn\PdoCredentialRepository;
use ExactApproval\WebAuthn\WebAuthnVerifier;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

/**
 * The person's own account: approval passkeys and agent connections. A password session alone cannot
 * add an approval passkey. The first one needs a one-time link from setup or an administrator; any
 * other needs a fresh confirmation with an existing passkey. Every passkey is recorded, signed, in the
 * security log, and only passkeys with that record can approve anything.
 */
final readonly class AccountController
{
    /** How long a passkey confirmation authorizes a sensitive step, such as adding another passkey. */
    public const CONFIRMATION_SECONDS = 120;

    public function __construct(
        private ActorProvider $actors,
        private WebAuthnVerifier $webauthn,
        private PdoCredentialRepository $credentials,
        private AnchoredCredentialRepository $anchored,
        private AuditLog $audit,
        private DemoDatabase $database,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
        private Security $security,
        private RateLimiterFactoryInterface $limiter,
    ) {}

    public function login(AuthenticationUtils $authentication): Response
    {
        return new Response($this->twig->render('login.html.twig', ['error' => $authentication->getLastAuthenticationError(), 'last_username' => $authentication->getLastUsername()]));
    }

    public function passkeys(Request $request): Response
    {
        $actor = $this->actors->interactive();
        $passkeys = [];
        foreach ($this->credentials->forActor($actor) as $credential) {
            $record = $this->anchored->enrollmentRecord($credential->id);
            $passkeys[] = [
                'id' => $credential->id, 'label' => $credential->label, 'authenticator' => Authenticators::name($credential->sourceJson),
                'anchored' => $this->anchored->anchored($credential), 'enrolledAt' => $record['at'] ?? null, 'method' => $record['data']->method ?? null,
            ];
        }
        $anchored = array_filter($passkeys, static fn (array $passkey): bool => $passkey['anchored']);

        return new Response($this->twig->render('passkeys.html.twig', [
            'passkeys' => $passkeys,
            'connections' => $this->database->grants($actor->tenantId, $actor->userId),
            'authorized' => $this->authorization($request, $actor),
            'can_confirm' => $anchored !== [],
            'csrf' => $this->csrf->getToken('exact_approval.enrollment')->getValue(),
            'form_csrf' => $this->csrf->getToken('demo.account')->getValue(),
        ]));
    }

    /** A one-time link from setup or an administrator: it lets this signed-in person add a passkey. */
    public function passkeyLink(Request $request, string $token): Response
    {
        $actor = $this->actors->interactive();
        $link = preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) ? $this->database->passkeyLink($token) : null;
        if ($link === null || $link['user_id'] !== $actor->userId || $link['tenant_id'] !== $actor->tenantId) {
            return new Response($this->twig->render('passkey_link_invalid.html.twig'), 410);
        }
        $request->getSession()->set('demo.passkey_link', [
            'user' => $actor->userId, 'tenant' => $actor->tenantId, 'link' => $link['token_hash'], 'issuedBy' => $link['issued_by'], 'expires' => time() + 600,
        ]);

        return new RedirectResponse('/account/passkeys');
    }

    /** A fresh confirmation with an existing passkey, for steps a password alone must not authorize. */
    public function confirmOptions(Request $request): JsonResponse
    {
        $this->assertCsrf($request);
        if (($limited = $this->limit()) !== null) return $limited;
        $actor = $this->actors->interactive();
        $challenge = random_bytes(32);
        try {
            $options = $this->webauthn->requestOptions($actor, $challenge);
        } catch (ApprovalException $error) {
            return new JsonResponse(['status' => 'rejected', 'message' => $error->getMessage()], 409);
        }
        $id = bin2hex(random_bytes(16));
        $request->getSession()->set('demo.confirm', ['id' => $id, 'user' => $actor->userId, 'tenant' => $actor->tenantId, 'challenge' => ChallengeBinding::encode($challenge), 'expires' => time() + 120]);

        return new JsonResponse(['ceremony_id' => $id, 'publicKey' => $options]);
    }

    public function confirmComplete(Request $request): JsonResponse
    {
        $this->assertCsrf($request);
        if (($limited = $this->limit()) !== null) return $limited;
        $actor = $this->actors->interactive();
        $body = $this->body($request);
        $saved = $request->getSession()->get('demo.confirm');
        $request->getSession()->remove('demo.confirm');
        if (!\is_array($saved) || !hash_equals($saved['id'], $body['ceremony_id']) || $saved['user'] !== $actor->userId || $saved['tenant'] !== $actor->tenantId || $saved['expires'] < time()) {
            throw new AccessDeniedException('This confirmation expired. Start again.');
        }
        try {
            $verified = $this->webauthn->verify($actor, ChallengeBinding::decode($saved['challenge']), $body['credential']);
        } catch (ApprovalException $error) {
            return new JsonResponse(['status' => 'rejected', 'message' => $error->getMessage()], 400);
        }
        $request->getSession()->set('demo.confirmed', ['user' => $actor->userId, 'tenant' => $actor->tenantId, 'credential' => $verified->credentialId, 'at' => time()]);

        return new JsonResponse(['status' => 'confirmed', 'message' => 'Confirmed with your passkey.']);
    }

    public function enrollmentOptions(Request $request): JsonResponse
    {
        $this->assertCsrf($request);
        if (($limited = $this->limit()) !== null) return $limited;
        $actor = $this->actors->interactive();
        $authorization = $this->authorization($request, $actor);
        if ($authorization === null) {
            return new JsonResponse(['status' => 'rejected', 'reason' => 'authorization_required', 'message' => $this->anchored->forActor($actor) === []
                ? 'Your first passkey needs the one-time link your administrator gave you. Open it while signed in.'
                : 'Confirm with one of your passkeys first.'], 403);
        }
        $options = $this->webauthn->registrationOptions($actor, random_bytes(32), $this->security->getUser()?->getUserIdentifier() ?? $actor->userId);
        $id = bin2hex(random_bytes(24));
        $request->getSession()->set('exact_approval.enrollment', [
            'id' => $id, 'user' => $actor->userId, 'tenant' => $actor->tenantId,
            'expires' => time() + 120, 'options' => $options, 'authorization' => $authorization,
        ]);

        return new JsonResponse(['ceremony_id' => $id, 'publicKey' => $options]);
    }

    public function enrollmentComplete(Request $request): JsonResponse
    {
        $this->assertCsrf($request);
        if (($limited = $this->limit()) !== null) return $limited;
        $actor = $this->actors->interactive();
        $body = $this->body($request);
        $saved = $request->getSession()->get('exact_approval.enrollment');
        if (!\is_array($saved) || !hash_equals($saved['id'], $body['ceremony_id']) || $saved['user'] !== $actor->userId || $saved['tenant'] !== $actor->tenantId || $saved['expires'] < time()) {
            throw new AccessDeniedException('This enrollment expired. Start again from your account page.');
        }
        $request->getSession()->remove('exact_approval.enrollment');
        $authorization = $saved['authorization'];
        $pdo = $this->database->pdo;
        // One transaction: the passkey, its signed enrollment record and the link it used up.
        $pdo->beginTransaction();
        try {
            $credential = $this->webauthn->register($actor, $saved['options'], $body['credential'], 'Personal passkey');
            if ($authorization['method'] === 'passkey link' && !$this->database->usePasskeyLink($authorization['link'], $credential->id)) {
                throw new ApprovalException('link_used', 'This passkey link has already been used or has expired.');
            }
            $this->anchored->anchor($credential, [
                'method' => $authorization['method'], 'authorizedBy' => $authorization['authorizedBy'],
                'authenticator' => Authenticators::name($credential->sourceJson),
            ]);
            $pdo->commit();
        } catch (ApprovalException $error) {
            $pdo->rollBack();

            return new JsonResponse(['status' => 'rejected', 'message' => $error->getMessage()], 400);
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        $request->getSession()->remove('demo.passkey_link');
        $request->getSession()->remove('demo.confirmed');

        return new JsonResponse(['status' => 'registered', 'message' => 'Your passkey is registered for this account.']);
    }

    public function revokePasskey(Request $request, string $id): Response
    {
        $actor = $this->actors->interactive();
        $this->assertFormCsrf($request);
        $this->anchored->revoke($actor, $id, ['by' => $actor->userId, 'reason' => 'revoked by its owner']);

        return new RedirectResponse('/account/passkeys');
    }

    public function revokeConnection(Request $request, string $id): Response
    {
        $actor = $this->actors->interactive();
        $this->assertFormCsrf($request);
        $pdo = $this->database->pdo;
        $pdo->beginTransaction();
        if ($this->database->revokeGrant($id, $actor->tenantId, $actor->userId)) {
            $this->audit->append($actor->tenantId, 'connection.revoked', $id, ['userId' => $actor->userId, 'by' => $actor->userId]);
        }
        $pdo->commit();

        return new RedirectResponse('/account/passkeys');
    }

    /**
     * What currently lets this person add a passkey: a fresh confirmation with one of their passkeys,
     * or an opened one-time link. Null if neither.
     *
     * @return array{method: string, authorizedBy: string, link?: string}|null
     */
    private function authorization(Request $request, Actor $actor): ?array
    {
        $confirmed = self::recentConfirmation($request, $actor);
        if ($confirmed !== null) {
            return ['method' => 'existing passkey', 'authorizedBy' => 'passkey '.$confirmed['credential']];
        }
        $link = $request->getSession()->get('demo.passkey_link');
        if (\is_array($link) && $link['user'] === $actor->userId && $link['tenant'] === $actor->tenantId && $link['expires'] >= time()) {
            return ['method' => 'passkey link', 'authorizedBy' => 'link issued by '.$link['issuedBy'], 'link' => $link['link']];
        }

        return null;
    }

    /** @return array{user: string, tenant: string, credential: string, at: int}|null */
    public static function recentConfirmation(Request $request, Actor $actor): ?array
    {
        $confirmed = $request->getSession()->get('demo.confirmed');

        return \is_array($confirmed) && $confirmed['user'] === $actor->userId && $confirmed['tenant'] === $actor->tenantId
            && $confirmed['at'] + self::CONFIRMATION_SECONDS >= time() ? $confirmed : null;
    }

    /** @return array{ceremony_id: string, credential: array<string, mixed>} */
    private function body(Request $request): array
    {
        if (\strlen($request->getContent()) > 65_536) throw new BadRequestHttpException('Request is too large.');
        try { $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new BadRequestHttpException('Invalid response.'); }
        if (!\is_array($body) || !\is_string($body['ceremony_id'] ?? null) || !\is_array($body['credential'] ?? null)) throw new BadRequestHttpException('Invalid credential response.');

        return $body;
    }

    private function assertCsrf(Request $request): void
    {
        $this->actors->interactive();
        if (!$this->csrf->isTokenValid(new CsrfToken('exact_approval.enrollment', (string) $request->headers->get('X-CSRF-Token', '')))) throw new AccessDeniedException('Invalid or missing CSRF token.');
    }

    private function assertFormCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('demo.account', (string) $request->request->get('_token', '')))) throw new AccessDeniedException('Invalid or missing CSRF token.');
    }

    private function limit(): ?JsonResponse
    {
        $actor = $this->actors->interactive();
        $limit = $this->limiter->create(hash('sha256', $actor->tenantId."\0".$actor->userId))->consume();
        if ($limit->isAccepted()) return null;

        return new JsonResponse(['status' => 'rate_limited', 'message' => 'Too many passkey attempts. Wait before trying again.'], 429,
            ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
    }
}
