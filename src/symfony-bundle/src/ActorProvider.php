<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalState;

/**
 * Implement using the application's verified principal, never MCP arguments.
 *
 * Refuse with AccessDeniedException: the bundle's tools and pages turn that into their refusal, and the firewall
 * sends a stranger to sign in. Any other exception is an error.
 */
interface ActorProvider
{
    /** @throws AccessDeniedException when no account is authenticated */
    public function current(): Actor;

    /**
     * An existing human account session; an agent token must not satisfy this.
     *
     * @throws AccessDeniedException when there is none, as for an agent's token
     */
    public function interactive(): Actor;

    /**
     * Apply the current read grant before exposing potentially sensitive stored results.
     *
     * @throws AccessDeniedException when this account or connection may not read it
     */
    public function assertCanReadApproval(ApprovalState $state): void;
}
