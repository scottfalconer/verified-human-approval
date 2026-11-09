<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/**
 * The approval whose action is running, as its adapter sees it during execute(). An adapter can record the
 * request id with the change it makes, in the same transaction, so the change links to its evidence.
 */
final readonly class ExecutingApproval
{
    public function __construct(
        public string $requestId,
        public string $actionId,
        /** 'user_verified' when a person approved with a passkey, 'not_required' when the policy let it run directly. */
        public string $approval,
    ) {
    }

    /** Whether a person approved this exact action with their passkey, as opposed to a rule letting it run. */
    public function userVerified(): bool
    {
        return 'user_verified' === $this->approval;
    }
}
