<?php

declare(strict_types=1);

namespace ExactApproval\Symfony;

use ExactApproval\Core\Actor;
use ExactApproval\Core\ApprovalState;

/** Implement using the application's verified principal, never MCP arguments. */
interface ActorProvider
{
    public function current(): Actor;

    /** An existing human account session; an agent token must not satisfy this. */
    public function interactive(): Actor;

    /** Apply the current read grant before exposing potentially sensitive stored results. */
    public function assertCanReadApproval(ApprovalState $state): void;
}
