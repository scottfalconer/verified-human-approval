<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/** Narrow in-process boundary for trusted adapters; not a sandbox against application-code control. */
final class ExecutionGuard
{
    private ?string $active = null;
    private ?ExecutingApproval $current = null;
    private ?object $owner = null;

    /** @internal Bound once by the ApprovalService. Never expose this object as an MCP argument. */
    public function bind(object $owner): void
    {
        if (null !== $this->owner) {
            throw new \LogicException('An execution guard belongs to one approval service.');
        }
        $this->owner = $owner;
    }

    /** @internal Only ApprovalService can supply its private owner capability. */
    public function within(object $owner, Actor $actor, PreparedAction $action, callable $callback, ?ExecutingApproval $approval = null): mixed
    {
        if ($owner !== $this->owner || null !== $this->active) {
            throw new ApprovalException('approval_required', 'No valid execution context exists.');
        }
        $this->active = $this->key($actor, $action);
        $this->current = $approval;
        try {
            return $callback();
        } finally {
            $this->active = null;
            $this->current = null;
        }
    }

    /**
     * The approval whose action is executing, while its adapter runs; null at any other time. An adapter can
     * write the request id onto the change it makes, in the same transaction, to link the change to its evidence.
     */
    public function current(): ?ExecutingApproval
    {
        return $this->current;
    }

    public function assertExecuting(Actor $actor, PreparedAction $action): void
    {
        if (null === $this->active || !hash_equals($this->active, $this->key($actor, $action))) {
            throw new ApprovalException('approval_required', 'This action must execute through the approval service.');
        }
    }

    private function key(Actor $actor, PreparedAction $action): string
    {
        return hash('sha256', CanonicalJson::encode([$actor->toArray(), $action->toArray()]));
    }
}
