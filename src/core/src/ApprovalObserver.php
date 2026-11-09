<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/**
 * Told about an action that ran, after its transaction committed: for work that must not happen unless the change
 * is durable, such as purging a cache, reindexing or notifying someone. It runs outside the transaction and cannot
 * change the result; if it throws, the error is logged and the person still gets the completed state.
 *
 * Delivery is at least once. A call that recovers from an ambiguous commit reports the completed state it reads,
 * so an observer can see the same request twice and must treat a repeat as harmless. It never sees a change that
 * did not commit.
 */
interface ApprovalObserver
{
    public function completed(ApprovalState $state): void;
}
