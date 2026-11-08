<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\exact_approval\Security\ActorProvider;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Review is served by Drupal; only proof can come back from the browser. */
final class ApprovalController extends ControllerBase
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ActorProvider $actors,
        private readonly CsrfTokenGenerator $csrf,
        private readonly HumanRequestGuard $human,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('exact_approval.approvals'),
            $container->get('exact_approval.actor_provider'),
            $container->get('csrf_token'),
            new HumanRequestGuard($container->get('exact_approval.actor_provider'), $container->get('csrf_token'), $container->get('flood')),
        );
    }

    public function review(string $id): array
    {
        $actor = HumanRequestGuard::interactive($this->actors);
        try {
            $state = $this->approvals->get($actor, $id);
        } catch (ApprovalException) {
            throw new NotFoundHttpException('This request is unavailable for your account.');
        }
        $prepared = $state->request->prepared();
        $json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            '#theme' => 'exact_approval_review',
            '#title' => $this->t('Review this exact action'),
            '#state' => $state,
            '#prepared' => $prepared,
            '#request_id' => $id,
            '#account_name' => $this->actors->account($actor)->getDisplayName(),
            '#statement' => $state->request->statement(),
            '#expires' => $state->request->expiresAt()->format('Y-m-d H:i:s T'),
            '#digest' => $state->request->digest,
            '#target_json' => $json((object) $prepared->target),
            '#effect_json' => $json((object) $prepared->effect),
            '#preconditions_json' => $json((object) $prepared->preconditions),
            '#result_json' => $state->result === null ? null : $json((object) $state->result),
            '#csrf' => $this->csrf->get('exact_approval.' . $id),
            '#options_url' => Url::fromRoute('exact_approval.options', ['id' => $id])->toString(),
            '#complete_url' => Url::fromRoute('exact_approval.complete', ['id' => $id])->toString(),
            '#cancel_url' => Url::fromRoute('exact_approval.cancel', ['id' => $id])->toString(),
            '#evidence_url' => $state->status === 'completed' ? Url::fromRoute('exact_approval.evidence', ['id' => $id])->toString() : null,
            '#keys_url' => Url::fromRoute('exact_approval.keys')->toString(),
            '#attached' => ['library' => ['exact_approval/passkey']],
            '#cache' => ['max-age' => 0],
        ];
    }

    public function status(string $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->approvals->get(HumanRequestGuard::interactive($this->actors), $id)->toArray());
    }

    public function options(Request $request, string $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $actor = $this->human->assert($request, 'exact_approval.' . $id);
            $ceremony = $this->approvals->begin($actor, $id);
            $request->getSession()->set('exact_approval.ceremony.' . $id, $ceremony->id);

            return ['ceremony_id' => $ceremony->id, 'publicKey' => $ceremony->options, 'expires_at' => $ceremony->expiresAt];
        });
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $actor = $this->human->assert($request, 'exact_approval.' . $id);
            $body = $this->human->proofBody($request);
            $expected = $request->getSession()->get('exact_approval.ceremony.' . $id);
            if (!is_string($expected) || !hash_equals($expected, $body['ceremony_id'])) {
                throw new AccessDeniedHttpException('Start a fresh approval from this request’s review page.');
            }
            $this->approvals->get($actor, $id);
            $request->getSession()->remove('exact_approval.ceremony.' . $id);

            return $this->approvals->complete($actor, $body['ceremony_id'], $body['credential'])->toArray();
        });
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $actor = $this->human->assert($request, 'exact_approval.' . $id);
            $state = $this->approvals->cancel($actor, $id);
            $request->getSession()->remove('exact_approval.ceremony.' . $id);

            return $state->toArray();
        });
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            $response = new JsonResponse($operation());
        } catch (ApprovalException $error) {
            $response = new JsonResponse(['status' => 'rejected', 'reason' => $error->reason, 'message' => $error->getMessage()], $error->reason === 'not_found' ? 404 : 409);
        } catch (HttpExceptionInterface $error) {
            $response = new JsonResponse(['status' => 'rejected', 'message' => $error->getMessage()], $error->getStatusCode(), $error->getHeaders());
        }
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
