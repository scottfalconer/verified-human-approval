<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/**
 * A stored request that cannot be read back, in the place a list would have put its state. Nothing in it vouches for
 * an outcome, so it carries only the row's id, the tenant the record names, and the integrity error that reading it
 * raised; while the proposal still reads back, that error's request shows whose record it is. A history or audit view
 * shows it as a record that cannot be read.
 */
final readonly class UnreadableApproval
{
    public function __construct(
        public string $requestId,
        /** The tenant the record names: its proposal's while that reads back, otherwise what the row still says, which nothing vouches for. Use it to decide who sees the row. */
        public ?string $tenantId,
        /** record_integrity_error, with what failed as its previous exception. */
        public ApprovalException $error,
    ) {
    }
}
