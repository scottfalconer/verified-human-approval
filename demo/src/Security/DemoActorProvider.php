<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Security;

use Mcp\Server\Authorization\AccessToken;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Demo\DemoDatabase;

final readonly class DemoActorProvider implements ActorProvider
{
    public function __construct(private Security $security, private DemoDatabase $database, private RequestStack $requests)
    {
    }

    public function current(): Actor
    {
        $user = $this->security->getUser();
        if (!$user instanceof DemoUser) {
            throw new AccessDeniedException('An authenticated application account is required.');
        }

        return new Actor($user->id, $user->tenantId, $user->delegationId);
    }

    public function interactive(): Actor
    {
        $actor = $this->current();
        if (null !== $actor->delegationId) {
            throw new AccessDeniedException('Use a human browser session for this operation.');
        }

        return $actor;
    }

    public function assertCanReadApproval(ApprovalState $state): void
    {
        $actor = $this->current();
        $owner = $state->request->actor();
        $user = $this->database->user($actor->userId);
        if ($actor->userId !== $owner->userId || $actor->tenantId !== $owner->tenantId
            || null === $user || !(bool) $user['enabled'] || $user['tenant_id'] !== $actor->tenantId) {
            throw new AccessDeniedException('This approval is unavailable for your account.');
        }
        if (null !== $actor->delegationId) {
            // The firewall validated the connection's token for this request and handed it to the SDK;
            // its scopes say what the connection may read. The same object reaches tools as
            // RequestContext::getAccessToken().
            $token = $this->requests->getCurrentRequest()?->attributes->get(AccessToken::class);
            if (!$token instanceof AccessToken || $token->getClaim('grant_id') !== $actor->delegationId || !$token->hasScope('approval.read')) {
                throw new AccessDeniedException('This personal connection is not allowed to read approval results.');
            }
        }
    }
}
