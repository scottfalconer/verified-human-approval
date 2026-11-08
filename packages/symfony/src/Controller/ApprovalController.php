<?php

declare(strict_types=1);

namespace ExactApproval\Symfony\Controller;

use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalService;
use ExactApproval\Symfony\ActorProvider;
use ExactApproval\Symfony\ApprovalPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Twig\Environment;

final readonly class ApprovalController
{
    public function __construct(
        private ApprovalService $approvals,
        private ActorProvider $actors,
        private ApprovalPresenter $presenter,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
        private RateLimiterFactoryInterface $limiter,
    ) {}

    public function review(Request $request, string $id): Response
    {
        try {
            $state = $this->approvals->get($this->actors->interactive(), $id);
        } catch (ApprovalException) {
            throw new NotFoundHttpException('This request is unavailable for your account.');
        }

        return new Response($this->twig->render('@ExactApproval/review.html.twig', [
            'state' => $state,
            'proposal' => $state->request->payload(),
            'prepared' => $state->request->prepared(),
            'csrf' => $this->csrf->getToken('exact_approval.'.$id)->getValue(),
        ]));
    }

    public function status(Request $request, string $id): JsonResponse
    {
        return $this->respond(fn () => $this->presenter->data($this->approvals->get($this->actors->interactive(), $id)));
    }

    public function options(Request $request, string $id): JsonResponse
    {
        $this->assertCsrf($request, $id);
        if (($limited = $this->limit()) !== null) return $limited;

        return $this->respond(function () use ($request, $id): array {
            $ceremony = $this->approvals->begin($this->actors->interactive(), $id);
            // The page/request pairing is checked independently of proof validation.
            $request->getSession()->set('exact_approval.ceremony.'.$id, $ceremony->id);

            return ['ceremony_id' => $ceremony->id, 'publicKey' => $ceremony->options, 'expires_at' => $ceremony->expiresAt];
        });
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $this->assertCsrf($request, $id);
        if (($limited = $this->limit()) !== null) return $limited;
        $body = $this->body($request);
        if (array_diff(array_keys($body), ['ceremony_id', 'credential']) !== []
            || !\is_string($body['ceremony_id'] ?? null) || !\is_array($body['credential'] ?? null)) {
            throw new BadRequestHttpException('Completion accepts only a ceremony identifier and WebAuthn response.');
        }
        $expected = $request->getSession()->get('exact_approval.ceremony.'.$id);
        if (!\is_string($expected) || !hash_equals($expected, $body['ceremony_id'])) {
            throw new AccessDeniedException('Start a fresh approval from this request’s review page.');
        }

        return $this->respond(function () use ($request, $id, $body): array {
            $actor = $this->actors->interactive();
            $this->approvals->get($actor, $id); // Ownership is checked before any work.
            $request->getSession()->remove('exact_approval.ceremony.'.$id);

            return $this->presenter->data($this->approvals->complete($actor, $body['ceremony_id'], $body['credential']));
        });
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->assertCsrf($request, $id);

        return $this->respond(fn () => $this->presenter->data($this->approvals->cancel($this->actors->interactive(), $id)));
    }

    public function script(): Response
    {
        return new Response(file_get_contents(\dirname(__DIR__, 2).'/assets/approval.js'), headers: ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    public function styles(): Response
    {
        return new Response(file_get_contents(\dirname(__DIR__, 2).'/assets/approval.css'), headers: ['Content-Type' => 'text/css; charset=utf-8']);
    }

    private function assertCsrf(Request $request, string $id): void
    {
        $this->actors->interactive();
        if (!$this->csrf->isTokenValid(new CsrfToken('exact_approval.'.$id, (string) $request->headers->get('X-CSRF-Token', '')))) {
            throw new AccessDeniedException('Invalid or missing CSRF token.');
        }
    }

    private function body(Request $request): array
    {
        if (\strlen($request->getContent()) > 65_536) {
            throw new BadRequestHttpException('Request is too large.');
        }
        try {
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON request.');
        }
        if (!\is_array($body) || array_is_list($body)) {
            throw new BadRequestHttpException('Expected a JSON object.');
        }

        return $body;
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return new JsonResponse($operation());
        } catch (ApprovalException $error) {
            return new JsonResponse(['status' => 'rejected', 'reason' => $error->reason, 'message' => $error->getMessage()], 409);
        }
    }

    private function limit(): ?JsonResponse
    {
        $actor = $this->actors->interactive();
        // One account budget covers all proposal IDs, so changing the URL cannot reset it.
        $limit = $this->limiter->create(hash('sha256', $actor->tenantId."\0".$actor->userId))->consume();
        if ($limit->isAccepted()) return null;

        return new JsonResponse([
            'status' => 'rate_limited', 'message' => 'Too many approval attempts. Wait a minute before starting a fresh review.',
        ], 429, ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
    }
}
