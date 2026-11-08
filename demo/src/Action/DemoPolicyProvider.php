<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Action;

use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\PolicyProvider;
use VerifiedHumanApproval\Demo\DemoDatabase;

final readonly class DemoPolicyProvider implements PolicyProvider
{
    public function __construct(private DemoDatabase $database) {}

    /** The rule follows the acting person's role. Without a direct rule, verified approval is required. */
    public function get(string $actionId, ?Actor $actor = null): ApprovalPolicy
    {
        if ($actionId === ChangeAlertRule::ID) {
            // Fixed in code, not in the database: no rule change can switch off approval of rule changes.
            return new ApprovalPolicy(ChangeAlertRule::ID, 'fixed-1', 'I approve changing who can issue public alerts on {{application}}: {{changes}}.', 600, true);
        }
        $row = $this->database->policy($actionId);
        if ($row === null) throw new ApprovalException('policy_missing', 'This action has no configured approval policy.');
        $user = $actor === null ? null : $this->database->user($actor->userId);
        $mode = $user === null ? 'approval' : ($this->database->roleMode($actionId, (string) $user['role']) ?? 'denied');

        return new ApprovalPolicy($row['action_id'], (string) $row['version'], $row['statement'], 600, $mode !== 'direct');
    }
}
