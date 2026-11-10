<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/**
 * Told about an action that ran, after its transaction committed: for work that must not happen unless the change
 * is durable, such as purging a cache, reindexing or notifying someone. It runs outside the transaction and cannot
 * change the result; if it throws, the error is logged and the person still gets the completed state.
 *
 * Delivery is synchronous and best effort, not durable: the process can stop after commit before an observer is
 * called. Recovery from an ambiguous commit can report a completed state again, so observers must also tolerate
 * repeats. It never reports a change that did not commit. Work requiring durable delivery needs the host to write
 * an outbox entry in the action's transaction and dispatch it independently; this callback is not a queue.
 */
interface ApprovalObserver
{
    public function completed(ApprovalState $state): void;
}
