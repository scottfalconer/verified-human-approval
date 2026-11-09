<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Tests\Support;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Authorizer;
use VerifiedHumanApproval\Core\PreparedAction;

final class FixtureAuthorizer implements Authorizer
{
    public int $checks = 0;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        ++$this->checks;
        $query = $this->pdo->prepare('SELECT enabled, can_publish FROM test_users WHERE user_id = ? AND tenant = ?');
        $query->execute([$actor->userId, $actor->tenantId]);
        $user = $query->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($user) || !(bool) $user['enabled'] || !(bool) $user['can_publish'] || $action->target['tenant'] !== $actor->tenantId) {
            throw new ApprovalException('permission_denied', 'Current application permission does not permit publication.');
        }
        if (null !== $actor->delegationId) {
            $query = $this->pdo->prepare('SELECT * FROM test_delegations WHERE id = ? AND user_id = ? AND tenant = ?');
            $query->execute([$actor->delegationId, $actor->userId, $actor->tenantId]);
            $delegation = $query->fetch(\PDO::FETCH_ASSOC);
            if (!\is_array($delegation) || !(bool) $delegation['active']) {
                throw new ApprovalException('delegation_revoked', 'The initiating client no longer has an active delegation.');
            }
            if (!(bool) $delegation['can_publish']) {
                throw new ApprovalException('scope_denied', 'The initiating client no longer has delegated publication authority.');
            }
        }
    }
}
