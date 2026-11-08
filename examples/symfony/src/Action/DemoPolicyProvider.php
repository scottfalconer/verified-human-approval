<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Action;

use ExactApproval\Core\ApprovalException;
use ExactApproval\Core\ApprovalPolicy;
use ExactApproval\Core\PolicyProvider;
use ExactApproval\Demo\DemoDatabase;

final readonly class DemoPolicyProvider implements PolicyProvider
{
    public function __construct(private DemoDatabase $database) {}

    public function get(string $actionId): ApprovalPolicy
    {
        $row = $this->database->policy($actionId);
        if ($row === null) throw new ApprovalException('policy_missing', 'This action has no configured approval policy.');

        return new ApprovalPolicy($row['action_id'], (string) $row['version'], $row['statement'], 600, (bool) $row['required']);
    }
}
