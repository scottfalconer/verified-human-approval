<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Core;

/** All application mutation and final checks must share the ApprovalStore transaction. */
interface ActionAdapter
{
    public function id(): string;

    public function version(): string;

    public function prepare(Actor $actor, array $input): PreparedAction;

    public function assertPreconditions(Actor $actor, PreparedAction $action): void;

    public function execute(Actor $actor, PreparedAction $action): array;

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void;
}
