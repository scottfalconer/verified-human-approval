<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use Drupal\Core\Flood\FloodInterface;
use Drupal\verified_human_approval\Action\PublishNodeRevision;
use Drupal\verified_human_approval\Storage\DrupalTransaction;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalService;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Core\Ceremony;
use VerifiedHumanApproval\Core\Evidence\EvidenceExporter;
use VerifiedHumanApproval\Core\Evidence\ReceiptVerifier;

/** Public Drupal service API. Identity is always resolved by the trusted host. */
final class ApprovalManager {

  public function __construct(
    private readonly \Symfony\Component\DependencyInjection\ContainerInterface $container,
    private readonly ActorProviderInterface $actors,
    private readonly DrupalTransaction $transactions,
    private readonly SettingsEvidenceSigner $signer,
    private readonly FloodInterface $flood,
    private readonly ModuleSettings $settings,
  ) {}

  public function requestPublish(int $nodeId, int $revisionId, string $langcode, ?string $requestKey = NULL): ApprovalState {
    $actor = $this->actors->current();
    $this->limit($actor, 'request', 20);
    $state = $this->service()->request($actor, PublishNodeRevision::ID, ['node_id' => $nodeId, 'revision_id' => $revisionId, 'langcode' => $langcode], $requestKey, $langcode);
    $this->assertRead($actor, TRUE);
    return $state;
  }

  public function get(string $requestId): ApprovalState {
    $actor = $this->actors->current();
    $this->limit($actor, 'read', 120);
    $state = $this->service()->get($actor, $requestId);
    $this->assertRead($actor);
    return $state;
  }

  public function begin(string $requestId): Ceremony {
    $actor = $this->actors->interactive();
    $this->limit($actor, 'ceremony', 15);
    $this->assertRead($actor);
    return $this->service()->begin($actor, $requestId);
  }

  public function complete(string $ceremonyId, array $credential): ApprovalState {
    $actor = $this->actors->interactive();
    $this->limit($actor, 'complete', 15);
    $this->assertRead($actor);
    return $this->service()->complete($actor, $ceremonyId, $credential);
  }

  public function cancel(string $requestId): ApprovalState {
    $actor = $this->actors->interactive();
    $this->limit($actor, 'cancel', 20);
    $this->assertRead($actor);
    return $this->service()->cancel($actor, $requestId);
  }

  public function receipt(string $requestId): array {
    $state = $this->get($requestId);
    $bundle = (new EvidenceExporter($this->signer))->export($state);
    // Never download a stored receipt as "verified" after covered rows changed.
    (new ReceiptVerifier())->verify($bundle, $this->signer->keyId(), $this->signer->publicKey());
    return $bundle;
  }

  public function publicKey(): array {
    $webAuthn = $this->settings->webAuthn();
    return $this->signer->trust() + ['rpId' => $webAuthn->rpId, 'origin' => $webAuthn->origin];
  }

  private function service(): ApprovalService {
    return $this->container->get('verified_human_approval.approval');
  }

  private function assertRead(Actor $actor, bool $request = FALSE): void {
    $this->transactions->run(function () use ($actor, $request): void {
      $account = $this->actors->accountFor($actor);
      if (!$account->hasPermission('use verified human approval') || ($request && !$account->hasPermission('request verified human approval'))) {
        throw new ApprovalException('permission_denied', 'Your current account cannot access approval records.');
      }
    });
  }

  private function limit(Actor $actor, string $operation, int $threshold): void {
    $name = 'verified_human_approval.' . $operation;
    if (!$this->flood->isAllowed($name, $threshold, 60, $actor->key())) {
      throw new ApprovalException('rate_limited', 'Too many approval requests. Wait a minute and try again.');
    }
    $this->flood->register($name, 60, $actor->key());
  }

}

