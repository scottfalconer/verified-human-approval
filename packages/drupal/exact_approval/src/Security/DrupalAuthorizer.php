<?php

declare(strict_types=1);

namespace Drupal\exact_approval\Security;

use Drupal\exact_approval\Action\{NodeDeleteAction, NodePublishAction};
use ExactApproval\Core\{Actor, ApprovalException, Authorizer, PreparedAction};

final readonly class DrupalAuthorizer implements Authorizer
{
    public function __construct(private NodePublishAction $publish, private NodeDeleteAction $delete, private DelegationAuthorizerInterface $delegations) {}

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        $this->delegations->assertAllowed($actor, $action);
        match ($action->actionId) {
            'node.publish' => $this->publish->assertAllowed($actor, $action),
            'node.delete' => $this->delete->assertAllowed($actor, $action),
            default => throw new ApprovalException('unsupported_action', 'The operation has no Drupal authority check.'),
        };
    }
}

