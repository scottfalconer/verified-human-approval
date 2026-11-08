<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Action;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Authorizer;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Demo\DemoDatabase;

final readonly class DemoAuthorizer implements Authorizer
{
    public function __construct(private DemoDatabase $database)
    {
    }

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        $user = $this->database->user($actor->userId);
        if (ChangeAlertRule::ID === $action->actionId) {
            if (null === $user || !(bool) $user['enabled'] || !(bool) $user['is_admin'] || $user['tenant_id'] !== $actor->tenantId
                || null !== $actor->delegationId || ($action->target['tenant_id'] ?? null) !== $actor->tenantId) {
                throw new ApprovalException('permission_denied', 'Only an administrator, in their own browser, may change who can issue public alerts.');
            }

            return;
        }
        // The administrator's per-role rule decides who may act at all; a passkey never adds rights.
        $mode = null === $user ? null : $this->database->roleMode($action->actionId, (string) $user['role']);
        if (null === $user || !(bool) $user['enabled'] || $user['tenant_id'] !== $actor->tenantId || !(bool) $user['can_publish'] || null === $mode || 'denied' === $mode
            || ($action->target['tenant_id'] ?? null) !== $actor->tenantId || 'alert.publish' !== $action->actionId) {
            throw new ApprovalException('permission_denied', 'Your role may not issue this public alert.');
        }
        if (null !== $actor->delegationId) {
            $grant = $this->database->grant($actor->delegationId);
            if (null === $grant || !(bool) $grant['active'] || $grant['user_id'] !== $actor->userId || $grant['tenant_id'] !== $actor->tenantId
                || !\in_array('alert.publish', json_decode($grant['scopes'], true, 8, \JSON_THROW_ON_ERROR), true)
                || !\in_array('approval.read', json_decode($grant['scopes'], true, 8, \JSON_THROW_ON_ERROR), true)) {
                throw new ApprovalException('delegation_denied', 'The initiating agent’s personal authorization is no longer valid for this action.');
            }
        }
    }
}
