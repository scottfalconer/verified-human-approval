<?php

declare(strict_types=1);

namespace ExactApproval\Core;

final class ActionRegistry
{
    /** @var array<string, ActionAdapter> */
    private array $actions = [];

    public function __construct(iterable $actions)
    {
        foreach ($actions as $action) {
            if (!$action instanceof ActionAdapter || isset($this->actions[$action->id()])) {
                throw new \InvalidArgumentException('Action adapters must have unique identities.');
            }
            $this->actions[$action->id()] = $action;
        }
    }

    public function get(string $id): ActionAdapter
    {
        return $this->actions[$id] ?? throw new ApprovalException('unknown_action', 'This action is not registered.');
    }
}
