<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/**
 * All application mutation and final checks must share the ApprovalStore transaction, and never end it. An action
 * that commits, rolls back or replaces that transaction gets no outcome recorded: the store refuses to write after
 * it, so the approval reads as not executed, even where the action's own writes were committed.
 */
interface ActionAdapter
{
    public function id(): string;

    public function version(): string;

    /** @param array<string, mixed> $input the request's input, as the tool or form sent it */
    public function prepare(Actor $actor, array $input): PreparedAction;

    public function assertPreconditions(Actor $actor, PreparedAction $action): void;

    /** @return array<string, mixed> what the action recorded; stored with the execution and its receipt */
    public function execute(Actor $actor, PreparedAction $action): array;

    /** @param array<string, mixed> $result what execute() returned */
    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void;
}
