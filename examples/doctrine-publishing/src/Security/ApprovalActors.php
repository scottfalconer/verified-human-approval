<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Example\Publishing\Security;

use Mcp\Server\Authorization\AccessToken;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalState;
use VerifiedHumanApproval\Example\Publishing\Approval\EditorialPermissions;
use VerifiedHumanApproval\Example\Publishing\Entity\User;

/**
 * Who is asking, from the signed-in account, never from a form field or a tool argument. An agent's request also
 * names its connection, the delegation the approval re-checks as it runs; the person's browser session names none.
 */
final readonly class ApprovalActors implements ActorProvider
{
    /** One site, so one tenant. A multi-tenant application takes the account's tenant here. */
    public const TENANT = 'site';

    public function __construct(private Security $security, private RequestStack $requests)
    {
    }

    public function current(): Actor
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Sign in first.');
        }
        $delegation = $this->agentToken()?->getClaim('grant_id');

        return new Actor($user->getUserIdentifier(), self::TENANT, \is_string($delegation) ? $delegation : null);
    }

    public function interactive(): Actor
    {
        $actor = $this->current();
        if (null !== $actor->delegationId || null !== $this->agentToken()) {
            throw new AccessDeniedException('Use your signed-in browser for this.');
        }

        return $actor;
    }

    public function assertCanReadApproval(ApprovalState $state): void
    {
        $actor = $this->current();
        if (!$actor->samePerson($state->request->actor())) {
            throw new AccessDeniedException('This approval belongs to another account.');
        }
        // An agent reads results only with the scope for it. Its token was checked against the stored connection on this request.
        if (null !== $actor->delegationId && !($this->agentToken()?->hasScope(EditorialPermissions::RESULTS) ?? false)) {
            throw new AccessDeniedException('This agent connection may not read approval results.');
        }
    }

    /** The agent connection's validated token, which AgentTokenAuthenticator sets on the main request. */
    private function agentToken(): ?AccessToken
    {
        $token = $this->requests->getMainRequest()?->attributes->get(AccessToken::class);

        return $token instanceof AccessToken ? $token : null;
    }
}
