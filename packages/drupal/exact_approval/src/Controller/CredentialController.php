<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\exact_approval\Security\ActorProvider;
use Drupal\exact_approval\Storage\DrupalCredentialRepository;
use ExactApproval\Core\ApprovalException;
use ExactApproval\WebAuthn\Credential;
use ExactApproval\WebAuthn\WebAuthnVerifier;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Passkeys are enrolled by the human session, never an MCP caller or tool. */
final class CredentialController extends ControllerBase
{
    public function __construct(
        private readonly ActorProvider $actors,
        private readonly WebAuthnVerifier $webauthn,
        private readonly DrupalCredentialRepository $credentials,
        private readonly CsrfTokenGenerator $csrf,
        private readonly HumanRequestGuard $human,
        private readonly TimeInterface $time,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('exact_approval.actor_provider'),
            $container->get('exact_approval.webauthn'),
            $container->get('exact_approval.credentials'),
            $container->get('csrf_token'),
            new HumanRequestGuard($container->get('exact_approval.actor_provider'), $container->get('csrf_token'), $container->get('flood')),
            $container->get('datetime.time'),
        );
    }

    public function keys(): array
    {
        $actor = HumanRequestGuard::interactive($this->actors);
        $rows = array_map(static fn (Credential $credential): array => [
            'id' => $credential->id,
            'label' => $credential->label,
            'revoked' => $credential->revoked,
            'revoke_url' => $credential->revoked ? null : Url::fromRoute('exact_approval.key_revoke', ['credential' => $credential->id])->toString(),
        ], $this->credentials->allForActor($actor));

        return [
            '#theme' => 'exact_approval_keys',
            '#title' => $this->t('My approval keys'),
            '#credentials' => $rows,
            '#csrf' => $this->csrf->get('exact_approval.enrollment'),
            '#options_url' => Url::fromRoute('exact_approval.enroll_options')->toString(),
            '#complete_url' => Url::fromRoute('exact_approval.enroll_complete')->toString(),
            '#attached' => ['library' => ['exact_approval/passkey']],
            '#cache' => ['max-age' => 0],
        ];
    }

    public function enrollmentOptions(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $actor = $this->human->assert($request, 'exact_approval.enrollment');
            $options = $this->webauthn->registrationOptions($actor, random_bytes(32), $this->actors->account($actor)->getDisplayName());
            $id = bin2hex(random_bytes(24));
            $request->getSession()->set('exact_approval.enrollment', [
                'id' => $id,
                'user' => $actor->userId,
                'tenant' => $actor->tenantId,
                'expires' => $this->time->getCurrentTime() + 120,
                'options' => $options,
            ]);

            return ['ceremony_id' => $id, 'publicKey' => $options];
        });
    }

    public function enrollmentComplete(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $actor = $this->human->assert($request, 'exact_approval.enrollment');
            $body = $this->human->proofBody($request);
            $saved = $request->getSession()->get('exact_approval.enrollment');
            if (!is_array($saved) || !is_string($saved['id'] ?? null) || !hash_equals($saved['id'], $body['ceremony_id'])
                || ($saved['user'] ?? null) !== $actor->userId || ($saved['tenant'] ?? null) !== $actor->tenantId
                || !is_int($saved['expires'] ?? null) || $saved['expires'] <= $this->time->getCurrentTime()
                || !is_array($saved['options'] ?? null)) {
                throw new AccessDeniedHttpException('This enrollment expired. Start again from your account page.');
            }
            // Failed verification consumes this ceremony too; replay cannot create a key.
            $request->getSession()->remove('exact_approval.enrollment');
            $this->webauthn->register($actor, $saved['options'], $body['credential'], 'Personal passkey');

            return ['status' => 'registered', 'message' => (string) $this->t('Your approval key is registered for this Drupal account.')];
        });
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return new JsonResponse($operation(), headers: ['Cache-Control' => 'private, no-store']);
        } catch (ApprovalException $error) {
            return new JsonResponse(['status' => 'rejected', 'reason' => $error->reason, 'message' => $error->getMessage()], 400, ['Cache-Control' => 'private, no-store']);
        } catch (HttpExceptionInterface $error) {
            return new JsonResponse(['status' => 'rejected', 'message' => $error->getMessage()], $error->getStatusCode(), ['Cache-Control' => 'private, no-store'] + $error->getHeaders());
        }
    }
}
