<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Action;

use VerifiedHumanApproval\Core\ActionAdapter;
use VerifiedHumanApproval\Core\Actor;
use VerifiedHumanApproval\Core\ApprovalException;
use VerifiedHumanApproval\Core\ApprovalPolicy;
use VerifiedHumanApproval\Core\ExecutionGuard;
use VerifiedHumanApproval\Core\PreparedAction;
use VerifiedHumanApproval\Demo\DemoDatabase;

/**
 * Changing who must verify is itself high-stakes, so it is an action like any other: the administrator
 * approves the exact change with their own passkey, it executes once, and its receipt is signed at
 * commit. A password session alone cannot switch verification off.
 */
final readonly class ChangeAlertRule implements ActionAdapter
{
    public const ID = 'policy.change';
    public const MODES = ['direct' => 'Issue alerts directly', 'approval' => 'Issue alerts only with verified human approval', 'denied' => 'Cannot issue alerts'];
    private const ROLES = ['manager' => 'Emergency manager', 'staff' => 'County staff'];

    public function __construct(private DemoDatabase $database, private ExecutionGuard $guard, private PublishAlert $alerts)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function version(): string
    {
        return '1';
    }

    public function prepare(Actor $actor, array $input): PreparedAction
    {
        if (array_keys($input) !== ['manager', 'staff', 'statement'] || !\is_string($input['statement'])
            || !\is_string($input['manager']) || !isset(self::MODES[$input['manager']]) || !\is_string($input['staff']) || !isset(self::MODES[$input['staff']])) {
            throw new ApprovalException('invalid_input', 'Choose a rule for each role and a statement.');
        }
        $statement = trim($input['statement']);
        if ('' === $statement || \strlen($statement) > 2000) {
            throw new ApprovalException('invalid_input', 'The statement must be 1 to 2000 characters.');
        }
        // The new statement must render for a real alert before anyone can approve it.
        try {
            (new ApprovalPolicy(PublishAlert::ID, 'preview', $statement))->statement($this->alerts->prepare($actor, ['alert_id' => 'riverside-evacuation']));
        } catch (\InvalidArgumentException $error) {
            throw new ApprovalException('invalid_policy', $error->getMessage());
        }
        $current = $this->current();
        $changes = [];
        foreach (self::ROLES as $role => $label) {
            if ($current[$role] !== $input[$role]) {
                $changes[] = $label.': '.self::MODES[$current[$role]].' → '.self::MODES[$input[$role]];
            }
        }
        if ($current['statement'] !== $statement) {
            $changes[] = 'The statement approvers confirm';
        }
        if ([] === $changes) {
            throw new ApprovalException('no_change', 'Nothing changed.');
        }

        return new PreparedAction(
            self::ID, $this->version(),
            ['application' => 'Riverside Council website', 'environment' => 'Local demonstration', 'tenant_id' => $actor->tenantId, 'resource' => 'Who can issue public alerts'],
            ['manager' => $input['manager'], 'staff' => $input['staff'], 'statement' => $statement],
            $current,
            ['title' => 'Change who can issue public alerts', 'consequence' => 'As soon as this is approved, the new rule applies to every request, and requests waiting under the old rule can no longer be approved.', 'changes' => $changes],
            ['changes' => implode('; ', $changes), 'application' => 'Riverside Council website'],
        );
    }

    public function assertPreconditions(Actor $actor, PreparedAction $action): void
    {
        $now = $this->current();
        if ($now['version'] !== $action->preconditions['version'] || $now['statement'] !== $action->preconditions['statement']
            || $now['manager'] !== $action->preconditions['manager'] || $now['staff'] !== $action->preconditions['staff']) {
            throw new ApprovalException('stale_resource', 'The rule changed after this request was created. Review a new proposal.');
        }
    }

    public function execute(Actor $actor, PreparedAction $action): array
    {
        $this->guard->assertExecuting($actor, $action);
        $policy = $this->database->pdo->prepare('UPDATE demo_policies SET version = version + 1, statement = ? WHERE action_id = ? AND version = ?');
        $policy->execute([$action->effect['statement'], PublishAlert::ID, $action->preconditions['version']]);
        if (1 !== $policy->rowCount()) {
            throw new ApprovalException('stale_resource', 'The rule changed. Nothing was changed.');
        }
        $rule = $this->database->pdo->prepare('UPDATE demo_role_policies SET mode = ? WHERE action_id = ? AND role = ?');
        foreach (array_keys(self::ROLES) as $role) {
            $rule->execute([$action->effect[$role], PublishAlert::ID, $role]);
        }

        return ['disposition' => 'rule_changed', 'version' => $action->preconditions['version'] + 1];
    }

    public function assertPostconditions(Actor $actor, PreparedAction $action, array $result): void
    {
        $now = $this->current();
        if ($action->preconditions['version'] + 1 !== $now['version'] || $now['statement'] !== $action->effect['statement']
            || $now['manager'] !== $action->effect['manager'] || $now['staff'] !== $action->effect['staff']) {
            throw new ApprovalException('unexpected_effect', 'The rule did not change as approved; the transaction was rolled back.');
        }
    }

    /** @return array{version: int, statement: string, manager: string, staff: string} */
    private function current(): array
    {
        $policy = $this->database->policy(PublishAlert::ID) ?? throw new ApprovalException('policy_missing', 'No rule is configured.');

        return [
            'version' => (int) $policy['version'], 'statement' => (string) $policy['statement'],
            'manager' => $this->database->roleMode(PublishAlert::ID, 'manager') ?? 'denied',
            'staff' => $this->database->roleMode(PublishAlert::ID, 'staff') ?? 'denied',
        ];
    }
}
