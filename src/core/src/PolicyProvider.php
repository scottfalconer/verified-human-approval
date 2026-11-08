<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

interface PolicyProvider
{
    /**
     * The policy for this action, which may depend on who acts, for example on their role.
     * It is digested into each proposal, so a change invalidates proposals made under the old one.
     */
    public function get(string $actionId, ?Actor $actor = null): ApprovalPolicy;
}
