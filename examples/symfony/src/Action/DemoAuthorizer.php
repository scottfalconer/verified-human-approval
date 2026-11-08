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
        if ($user === null || !(bool) $user['enabled'] || $user['tenant_id'] !== $actor->tenantId || !(bool) $user['can_publish']
            || ($action->target['tenant_id'] ?? null) !== $actor->tenantId || $action->actionId !== 'announcement.publish') {
            throw new ApprovalException('permission_denied', 'Your account no longer has permission to publish this announcement.');
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
