<?php

declare(strict_types=1);

namespace ExactApproval\Core;

/** Narrow in-process boundary for trusted adapters; not a sandbox against application-code control. */
final class ExecutionGuard
{
    private ?string $active = null;
    private ?object $owner = null;

    /** @internal Bound once by the ApprovalService. Never expose this object as an MCP argument. */
    public function bind(object $owner): void
    {
        if ($this->owner !== null) {
            throw new \LogicException('An execution guard belongs to one approval service.');
        }
        $this->owner = $owner;
    }

    /** @internal Only ApprovalService can supply its private owner capability. */
    public function within(object $owner, Actor $actor, PreparedAction $action, callable $callback): mixed
    {
        if ($owner !== $this->owner || $this->active !== null) {
            throw new ApprovalException('approval_required', 'No valid execution context exists.');
        }
        $this->active = $this->key($actor, $action);
        try {
            return $callback();
        } finally {
            $this->active = null;
        }
    }

    public function assertExecuting(Actor $actor, PreparedAction $action): void
    {
        if ($this->active === null || !hash_equals($this->active, $this->key($actor, $action))) {
            throw new ApprovalException('approval_required', 'This action must execute through the approval service.');
        }
    }

    private function key(Actor $actor, PreparedAction $action): string
    {
        return hash('sha256', CanonicalJson::encode([$actor->toArray(), $action->toArray()]));
    }
}
