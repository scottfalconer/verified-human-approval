<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/** All application mutation and final checks must share the ApprovalStore transaction. */
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
