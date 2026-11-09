<?php

declare(strict_types=1);

namespace Drupal\verified_human_approval;

use Drupal\verified_human_approval\Action\PublishNodeRevision;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\PolicyProvider as PolicyProviderInterface;

final class PolicyProvider implements PolicyProviderInterface {

  public function __construct(private readonly ModuleSettings $settings) {}

  public function get(string $actionId, ?Actor $actor = NULL): ApprovalPolicy {
    if ($actionId !== PublishNodeRevision::ID) {
      throw new ApprovalException('unknown_action', 'No Drupal approval policy is configured for this action.');
    }
    return new ApprovalPolicy($actionId, '1', 'I approve publishing “{{title}}” from revision {{revision}} in {{language}} on this Drupal site.', $this->settings->lifetime(), TRUE);
  }

}

