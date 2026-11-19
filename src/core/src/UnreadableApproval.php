<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/**
 * A stored request that cannot be read back, in the place a list would have put its state. Nothing in it can be
 * relied on, not even whose it is, so it carries only the row's id, the tenant the row names, and the integrity
 * error that reading it raised. A history or audit view shows it as a record that cannot be read.
 */
final readonly class UnreadableApproval
{
    public function __construct(
        public string $requestId,
        /** The tenant the stored row still names, if any. Nothing vouches for it: use it to decide who sees the row, never to trust it. */
        public ?string $tenantId,
        /** record_integrity_error, with what failed as its previous exception. */
        public ApprovalException $error,
    ) {
    }
}
