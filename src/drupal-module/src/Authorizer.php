<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\verified_human_approval\Action\PublishNodeRevision;
use Drupal\verified_human_approval\Storage\DrupalTransaction;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Authorizer as AuthorizerInterface;
use VerifiedHumanApproval\Core\PreparedAction;

final class Authorizer implements AuthorizerInterface {

  public function __construct(
    private readonly ActorProviderInterface $actors,
    private readonly EntityTypeManagerInterface $entities,
    private readonly DrupalTransaction $transactions,
  ) {}

  public function assertAllowed(Actor $actor, PreparedAction $action): void {
    $this->transactions->assertActive();
    $account = $this->actors->accountFor($actor);
    if ($action->actionId !== PublishNodeRevision::ID || !$account->hasPermission('request verified human approval') || !$account->hasPermission('use verified human approval')) {
      throw new ApprovalException('permission_denied', 'Your current account cannot request verified publication.');
    }
    $storage = $this->entities->getStorage('node');
    $storage->resetCache([(int) $action->target['node_id']]);
    $node = $storage->load((int) $action->target['node_id']);
    $access = $this->entities->getAccessControlHandler('node');
    $access->resetCache();
    if (!$node instanceof NodeInterface || $node->uuid() !== $action->target['node_uuid'] || !$access->access($node, 'update', $account) || !$node->get('status')->access('edit', $account)) {
      throw new ApprovalException('permission_denied', 'You no longer have update access to this node.');
    }
  }

}

