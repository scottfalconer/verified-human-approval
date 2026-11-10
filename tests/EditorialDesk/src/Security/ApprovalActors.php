<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Mcp\Server\Authorization\AccessToken;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use VerifiedHumanApproval\Bundle\ActorProvider;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalState;

/** Maps only authenticated host principals to approval actors; never reads identity from form or tool input. */
final readonly class ApprovalActors implements ActorProvider
{
    public const TENANT = 'editorial';

    public function __construct(private Security $security, private RequestStack $requests, private Connection $connection)
    {
    }

    public function current(): Actor
    {
        $account = $this->security->getUser();
        if (!$account instanceof Account) {
            throw new AccessDeniedException('Sign in first.');
        }
        $token = $this->agentToken();
        $grant = $token?->getClaim('grant_id');
        if (null !== $token && (!\is_string($grant) || '' === $grant || $token->getClaim('sub') !== $account->id())) {
            throw new AccessDeniedException('The agent credential does not identify a current delegation.');
        }

        return new Actor($account->id(), self::TENANT, \is_string($grant) ? $grant : null);
    }

    public function interactive(): Actor
    {
        $actor = $this->current();
        if (null !== $actor->delegationId || null !== $this->agentToken() || $this->requests->getMainRequest()?->headers->has('Authorization')) {
            throw new AccessDeniedException('Use your signed-in browser for this step.');
        }

        return $actor;
    }

    public function assertCanReadApproval(ApprovalState $state): void
    {
        $actor = $this->current();
        if (!$actor->samePerson($state->request->actor()) || 1 !== (int) $this->connection->fetchOne('SELECT enabled FROM app_accounts WHERE id = ?', [$actor->userId])) {
            throw new AccessDeniedException('This approval is unavailable to this account.');
        }
        if (null === $actor->delegationId) {
            return;
        }
        $grant = $this->connection->fetchAssociative('SELECT * FROM app_connections WHERE id = ? AND user_id = ?', [$actor->delegationId, $actor->userId]);
        if (false === $grant || null !== $grant['revoked_at'] || (int) $grant['expires_at'] <= time()
            || !\in_array('approval.read', explode(' ', $grant['scopes']), true)
            || !($this->agentToken()?->hasScope('approval.read') ?? false)) {
            throw new AccessDeniedException('This agent connection may not read approval results.');
        }
    }

    private function agentToken(): ?AccessToken
    {
        $token = $this->requests->getMainRequest()?->attributes->get(AccessToken::class);

        return $token instanceof AccessToken ? $token : null;
    }
}
