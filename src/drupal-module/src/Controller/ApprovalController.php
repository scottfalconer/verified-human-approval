<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\verified_human_approval\ApprovalManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalState;

/**
 * Presents stored proposals and accepts their browser-bound WebAuthn responses.
 */
final class ApprovalController extends ControllerBase {

  public function __construct(
    private readonly ApprovalManager $approvals,
    private readonly CsrfTokenGenerator $tokens,
    private readonly AccountProxyInterface $account,
    private readonly DateFormatterInterface $dates,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('verified_human_approval.approval_manager'),
      $container->get('csrf_token'),
      $container->get('current_user'),
      $container->get('date.formatter'),
    );
  }

  public function review(string $request_id): array {
    try {
      $state = $this->approvals->get($request_id);
    }
    catch (ApprovalException $error) {
      throw new NotFoundHttpException('This request is unavailable for your account.', $error);
    }

    return [
      '#theme' => 'verified_human_approval_review',
      '#request_id' => $state->request->id,
      '#statement' => $state->request->statement(),
      '#display' => $state->request->prepared()->display,
      '#digest' => $state->request->digest,
      '#expires' => $this->dates->format($state->request->expiresAt()->getTimestamp(), 'long'),
      '#status' => $state->status,
      '#reason' => $state->reason,
      '#has_proof' => $state->proof !== NULL,
      '#has_receipt' => $state->receipt !== NULL,
      '#csrf_token' => $this->tokens->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY),
      '#options_url' => $this->route('options', $request_id),
      '#complete_url' => $this->route('complete', $request_id),
      '#cancel_url' => $this->route('cancel', $request_id),
      '#review_url' => $this->route('review', $request_id),
      '#receipt_url' => $this->route('receipt', $request_id),
      '#key_url' => Url::fromRoute('verified_human_approval.public_key')->toString(),
      '#passkeys_url' => Url::fromRoute('verified_human_approval.passkeys')->toString(),
      '#return_url' => Url::fromRoute('<front>')->toString(),
      '#attached' => ['library' => ['verified_human_approval/ceremony']],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function status(string $request_id): JsonResponse {
    return $this->respond(fn(): array => $this->data($this->approvals->get($request_id)), $request_id);
  }

  public function options(Request $request, string $request_id): JsonResponse {
    return $this->respond(function () use ($request, $request_id): array {
      if ($this->body($request) !== []) {
        throw new \InvalidArgumentException('Options do not accept proposal data.');
      }
      $ceremony = $this->approvals->begin($request_id);
      // Pair this page, person and browser session independently of the proof.
      $request->getSession()->set($this->sessionKey($request_id), [
        'ceremony_id' => $ceremony->id,
        'uid' => (string) $this->account->id(),
      ]);
      return [
        'ceremony_id' => $ceremony->id,
        'publicKey' => $ceremony->options,
        'expires_at' => $ceremony->expiresAt,
      ];
    }, $request_id);
  }

  public function complete(Request $request, string $request_id): JsonResponse {
    return $this->respond(function () use ($request, $request_id): array {
      $body = $this->body($request);
      if (count($body) !== 2 || !is_string($body['ceremony_id'] ?? NULL)
        || strlen($body['ceremony_id']) > 128 || !is_array($body['credential'] ?? NULL)
        || array_is_list($body['credential'])) {
        throw new \InvalidArgumentException('Send only a ceremony identifier and WebAuthn response.');
      }
      // Check request ownership before consuming any browser state.
      $this->approvals->get($request_id);
      $saved = $request->getSession()->get($this->sessionKey($request_id));
      if (!is_array($saved) || ($saved['uid'] ?? NULL) !== (string) $this->account->id()
        || !is_string($saved['ceremony_id'] ?? NULL)
        || !hash_equals($saved['ceremony_id'], $body['ceremony_id'])) {
        throw new ApprovalException('ceremony_mismatch', 'Start a fresh approval from this request’s review page.');
      }
      $request->getSession()->remove($this->sessionKey($request_id));
      return $this->data($this->approvals->complete($body['ceremony_id'], $body['credential']));
    }, $request_id, TRUE);
  }

  public function cancel(Request $request, string $request_id): JsonResponse {
    return $this->respond(function () use ($request, $request_id): array {
      if ($this->body($request) !== []) {
        throw new \InvalidArgumentException('Cancellation does not accept proposal data.');
      }
      $state = $this->approvals->cancel($request_id);
      $request->getSession()->remove($this->sessionKey($request_id));
      return $this->data($state);
    }, $request_id, TRUE);
  }

  public function receipt(string $request_id): JsonResponse {
    $response = $this->respond(fn(): array => $this->approvals->receipt($request_id), $request_id);
    if ($response->isSuccessful()) {
      $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
        HeaderUtils::DISPOSITION_ATTACHMENT, 'receipt-' . $request_id . '.json',
      ));
    }
    return $response;
  }

  public function publicKey(): JsonResponse {
    $response = $this->respond(fn(): array => $this->approvals->publicKey());
    if ($response->isSuccessful()) {
      $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      $response->headers->set('Cache-Control', 'public, max-age=300');
    }
    return $response;
  }

  private function data(ApprovalState $state): array {
    return $state->toArray() + [
      'approved_with_passkey' => $state->proof !== NULL,
      'review_url' => $this->route('review', $state->request->id),
    ];
  }

  private function route(string $name, string $id): string {
    return Url::fromRoute('verified_human_approval.' . $name, ['request_id' => $id])->toString();
  }

  private function sessionKey(string $id): string {
    return 'verified_human_approval.ceremony.' . $id;
  }

  /**
   * Accepts a bounded JSON object, never client-supplied proposal/options state.
   */
  private function body(Request $request): array {
    $content = $request->getContent();
    if (strlen($content) > 65536 || !str_starts_with(strtolower($request->headers->get('Content-Type', '')), 'application/json')) {
      throw new \InvalidArgumentException('Send a JSON response smaller than 64 KiB.');
    }
    try {
      $value = json_decode($content, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new \InvalidArgumentException('The JSON response is invalid.');
    }
    if (!is_array($value) || !str_starts_with(ltrim($content), '{')) {
      throw new \InvalidArgumentException('The JSON response must be an object.');
    }
    return $value;
  }

  private function respond(callable $operation, ?string $id = NULL, bool $may_change = FALSE): JsonResponse {
    try {
      return $this->json($operation());
    }
    catch (ApprovalException $error) {
      $value = ['status' => 'rejected', 'reason' => $error->reason, 'message' => $error->getMessage()];
      if ($id !== NULL) {
        try {
          $value['request_status'] = $this->approvals->get($id)->status;
        }
        catch (\Throwable) {
          // The current outcome is unknown; the browser must not invent one.
        }
      }
      return $this->json($value, 409);
    }
    catch (\InvalidArgumentException $error) {
      return $this->json(['status' => 'rejected', 'reason' => 'invalid_request', 'message' => $error->getMessage()], 400);
    }
    catch (HttpExceptionInterface $error) {
      return $this->json(['status' => 'rejected', 'reason' => 'access_denied', 'message' => (string) $this->t('This request is unavailable for your account. Reload the page and check that you are signed in.')], $error->getStatusCode());
    }
    catch (\Throwable $error) {
      $this->getLogger('verified_human_approval')->error('An approval browser request failed ({exception_type}).', ['exception_type' => $error::class]);
      return $this->json([
        'status' => 'rejected',
        'reason' => $may_change ? 'execution_uncertain' : 'service_unavailable',
        'message' => (string) $this->t('The site could not confirm the request outcome. Reload the page to check its recorded status.'),
      ], 503);
    }
  }

  private function json(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, [
      'Cache-Control' => 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
