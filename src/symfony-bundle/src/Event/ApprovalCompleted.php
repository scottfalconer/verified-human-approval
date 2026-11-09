<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle\Event;

use VerifiedHumanApproval\Core\ApprovalState;

/**
 * Dispatched after an action ran and its transaction committed, whether a person approved it with a passkey or a
 * rule let it run (see `$state->execution['approval']`). Use it for work that must wait for a durable change, such
 * as purging a cache or reindexing.
 *
 * Delivery is at least once: a request that recovers from an ambiguous commit reports what it reads, so a
 * listener can see the same request twice and must treat a repeat as harmless. A listener that throws is logged;
 * it cannot change the result.
 */
final readonly class ApprovalCompleted
{
    public function __construct(public ApprovalState $state)
    {
    }
}
