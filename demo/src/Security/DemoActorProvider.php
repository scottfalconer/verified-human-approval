<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Security;

use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Demo\DemoDatabase;
use VerifiedHumanApproval\Bundle\ActorProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class DemoActorProvider implements ActorProvider
{
    public function __construct(private Security $security, private DemoDatabase $database) {}

    public function current(): Actor
    {
        $user = $this->security->getUser();
        if (!$user instanceof DemoUser) throw new AccessDeniedException('An authenticated application account is required.');

        return new Actor($user->id, $user->tenantId, $user->delegationId);
    }

    public function interactive(): Actor
    {
        $actor = $this->current();
        if ($actor->delegationId !== null) throw new AccessDeniedException('Use a human browser session for this operation.');

        return $actor;
    }

    public function assertCanReadApproval(ApprovalState $state): void
    {
        $actor = $this->current();
        $owner = $state->request->actor();
        $user = $this->database->user($actor->userId);
        if ($actor->userId !== $owner->userId || $actor->tenantId !== $owner->tenantId
            || $user === null || !(bool) $user['enabled'] || $user['tenant_id'] !== $actor->tenantId) {
            throw new AccessDeniedException('This approval is unavailable for your account.');
        }
        if ($actor->delegationId !== null) {
            $grant = $this->database->grant($actor->delegationId);
            if ($grant === null || !(bool) $grant['active'] || $grant['user_id'] !== $actor->userId || $grant['tenant_id'] !== $actor->tenantId
                || !\in_array('approval.read', json_decode($grant['scopes'], true, 8, JSON_THROW_ON_ERROR), true)) {
                throw new AccessDeniedException('This personal connection is not allowed to read approval results.');
            }
        }
    }
}
