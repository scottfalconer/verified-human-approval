<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Action;

use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\Authorizer;
use ExactApproval\Core\PreparedAction;
use ExactApproval\Demo\DemoDatabase;

final readonly class DemoAuthorizer implements Authorizer
{
    public function __construct(private DemoDatabase $database) {}

    public function assertAllowed(Actor $actor, PreparedAction $action): void
    {
        $user = $this->database->user($actor->userId);
        if ($action->actionId === ChangeAlertRule::ID) {
            if ($user === null || !(bool) $user['enabled'] || !(bool) $user['is_admin'] || $user['tenant_id'] !== $actor->tenantId
                || $actor->delegationId !== null || ($action->target['tenant_id'] ?? null) !== $actor->tenantId) {
                throw new ApprovalException('permission_denied', 'Only an administrator, in their own browser, may change who can issue public alerts.');
            }

            return;
        }
        // The administrator's per-role rule decides who may act at all; a passkey never adds rights.
        $mode = $user === null ? null : $this->database->roleMode($action->actionId, (string) $user['role']);
        if ($user === null || !(bool) $user['enabled'] || $user['tenant_id'] !== $actor->tenantId || !(bool) $user['can_publish'] || $mode === null || $mode === 'denied'
            || ($action->target['tenant_id'] ?? null) !== $actor->tenantId || $action->actionId !== 'announcement.publish') {
            throw new ApprovalException('permission_denied', 'Your role may not issue this public alert.');
        }
        if ($actor->delegationId !== null) {
            $grant = $this->database->grant($actor->delegationId);
            if ($grant === null || !(bool) $grant['active'] || $grant['user_id'] !== $actor->userId || $grant['tenant_id'] !== $actor->tenantId
                || !\in_array('announcement.publish', json_decode($grant['scopes'], true, 8, JSON_THROW_ON_ERROR), true)
                || !\in_array('approval.read', json_decode($grant['scopes'], true, 8, JSON_THROW_ON_ERROR), true)) {
                throw new ApprovalException('delegation_denied', 'The initiating agent’s personal authorization is no longer valid for this action.');
            }
        }
    }
}
