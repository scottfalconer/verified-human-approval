<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\StoreContracts\Fixture;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Authorizer;
use VerifiedHumanApproval\Core\PreparedAction;

final class FixtureAuthorizer implements Authorizer
{
    public int $checks = 0;

    /** Runs after each check that allows the action, while its transaction is still open. */
    public ?\Closure $afterCheck = null;

    private readonly FixtureDatabase $database;

    public function __construct(\PDO|FixtureDatabase $database)
    {
        $this->database = $database instanceof \PDO ? new PdoFixtureDatabase($database) : $database;
    }

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        ++$this->checks;
        // Read against change until commit: a revocation that commits first is seen, and one that comes later waits.
        $user = $this->database->row('SELECT enabled, can_publish FROM test_users WHERE user_id = ? AND tenant = ? '.$this->database->forShare(), [$actor->userId, $actor->tenantId]);
        if (null === $user || !(bool) $user['enabled'] || !(bool) $user['can_publish'] || $action->target['tenant'] !== $actor->tenantId) {
            throw new ApprovalException('permission_denied', 'Current application permission does not permit publication.');
        }
        if (null !== $actor->delegationId) {
            $delegation = $this->database->row('SELECT * FROM test_delegations WHERE id = ? AND user_id = ? AND tenant = ? '.$this->database->forShare(), [$actor->delegationId, $actor->userId, $actor->tenantId]);
            if (null === $delegation || !(bool) $delegation['active']) {
                throw new ApprovalException('delegation_revoked', 'The initiating client no longer has an active delegation.');
            }
            if (!(bool) $delegation['can_publish']) {
                throw new ApprovalException('scope_denied', 'The initiating client no longer has delegated publication authority.');
            }
        }
        if (null !== $this->afterCheck) {
            ($this->afterCheck)();
        }
    }
}
