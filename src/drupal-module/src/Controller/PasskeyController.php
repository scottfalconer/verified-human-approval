<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\verified_human_approval\PasskeyManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use VerifiedHumanApproval\Core\ApprovalException;

/**
 * Manages the current Drupal user's approval credentials.
 */
final class PasskeyController extends ControllerBase {

  public function __construct(
    private readonly PasskeyManager $passkeys,
    private readonly CsrfTokenGenerator $tokens,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('verified_human_approval.passkey_manager'),
      $container->get('csrf_token'),
    );
  }

  public function page(string $grant = ''): array {
    $credentials = [];
    foreach ($this->passkeys->credentials() as $credential) {
      $credentials[] = [
        'label' => $credential->label,
        'id' => $credential->id,
        'revoked' => $credential->revoked,
        'revoke_url' => Url::fromRoute('verified_human_approval.revoke', ['credential_id' => $credential->id])->toString(),
      ];
    }
    return [
      '#theme' => 'verified_human_approval_passkeys',
      '#credentials' => $credentials,
      '#grant' => $grant,
      '#csrf_token' => $this->tokens->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY),
      '#options_url' => Url::fromRoute('verified_human_approval.registration_options')->toString(),
      '#complete_url' => Url::fromRoute('verified_human_approval.registration_complete')->toString(),
      '#success_url' => Url::fromRoute('verified_human_approval.passkeys')->toString(),
      '#attached' => ['library' => ['verified_human_approval/ceremony']],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function options(Request $request): JsonResponse {
    return $this->respond(function () use ($request): array {
      $body = $this->body($request);
      if (count($body) !== 2 || !is_string($body['grant'] ?? NULL) || !is_string($body['label'] ?? NULL)
        || strlen($body['grant']) > 256 || trim($body['label']) === '' || strlen(trim($body['label'])) > 200) {
        throw new \InvalidArgumentException('Provide the administrator’s enrollment grant and a passkey label of at most 200 bytes.');
      }
      return $this->passkeys->registrationOptions($body['grant'], trim($body['label']));
    });
  }

  public function complete(Request $request): JsonResponse {
    return $this->respond(function () use ($request): array {
      $body = $this->body($request);
      if (count($body) !== 2 || !is_string($body['ceremony_id'] ?? NULL)
        || strlen($body['ceremony_id']) > 128 || !is_array($body['credential'] ?? NULL)
        || array_is_list($body['credential'])) {
        throw new \InvalidArgumentException('Send only a ceremony identifier and WebAuthn response.');
      }
      $this->passkeys->register($body['ceremony_id'], $body['credential']);
      return ['status' => 'registered', 'message' => (string) $this->t('Your approval passkey was registered.')];
    }, TRUE);
  }

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

  private function respond(callable $operation, bool $may_change = FALSE): JsonResponse {
    $status = 200;
    try {
      $data = $operation();
    }
    catch (ApprovalException $error) {
      $data = ['status' => 'rejected', 'reason' => $error->reason, 'message' => $error->getMessage()];
      $status = 409;
    }
    catch (\InvalidArgumentException $error) {
      $data = ['status' => 'rejected', 'reason' => 'invalid_request', 'message' => $error->getMessage()];
      $status = 400;
    }
    catch (HttpExceptionInterface $error) {
      $data = ['status' => 'rejected', 'reason' => 'access_denied', 'message' => (string) $this->t('Passkey enrollment is unavailable for your account. Check that you are signed in with the account named by the administrator.')];
      $status = $error->getStatusCode();
    }
    catch (\Throwable $error) {
      $this->getLogger('verified_human_approval')->error('A passkey browser request failed ({exception_type}).', ['exception_type' => $error::class]);
      $data = [
        'status' => 'rejected',
        'reason' => $may_change ? 'execution_uncertain' : 'service_unavailable',
        'message' => (string) $this->t('The site could not confirm the outcome. Reload your passkey list before trying again.'),
      ];
      $status = 503;
    }
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
  }

}
